<?php
// ============================================================
//  REACTION DUEL — logic.php (Room-Based, Authoritative Server)
//  FIX: Room-based matchmaking, stale timer guards, type fixes,
//       checkRoundEnd lock, array access fix, WAIT handler
// ============================================================

require_once __DIR__ . '/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Logic implements MessageComponentInterface {

    // ── Konstanta game ────────────────────────────────────────
    const MAX_ROUNDS        = 5;
    const ROUND_DELAY_MIN   = 2000;
    const ROUND_DELAY_MAX   = 4000;
    const LATENCY_GRACE_MS  = 200;
    const PENALTY_SCORE     = 50;

    const ITEM_CONFIG = [
        1 => ['count'=>5,  'duration'=>3200, 'bomb_chance'=>0.20, 'bonus_chance'=>0.15],
        2 => ['count'=>7,  'duration'=>2800, 'bomb_chance'=>0.22, 'bonus_chance'=>0.15],
        3 => ['count'=>9,  'duration'=>2400, 'bomb_chance'=>0.25, 'bonus_chance'=>0.15],
        4 => ['count'=>11, 'duration'=>2000, 'bomb_chance'=>0.28, 'bonus_chance'=>0.13],
        5 => ['count'=>13, 'duration'=>1600, 'bomb_chance'=>0.30, 'bonus_chance'=>0.12],
    ];

    // ── State Global ──────────────────────────────────────────
    private \SplObjectStorage $clients;

    /*
     * $players: semua pemain yang terkoneksi (untuk lobby & chat)
     * Format per player:
     * [
     *   'conn'         => ConnectionInterface,
     *   'username'     => string,
     *   'icon'         => string,
     *   'isGuest'      => bool,
     *   'score'        => int,
     *   'reactionLog'  => float[],
     *   'combo'        => int,
     *   'penalties'    => int,
     *   'isReady'      => bool,
     *   'findingMatch' => bool,
     *   'roomId'       => ?string,   ← BARU: id room jika sedang bermain
     * ]
     */
    private array $players      = [];

    /*
     * $rooms: game session per pasangan pemain
     * Format per room:
     * [
     *   'id'            => string,
     *   'gameStarted'   => bool,
     *   'currentRound'  => int,
     *   'activeItems'   => [],
     *   'roundLog'      => [],
     *   'itemIdCounter' => int,
     *   'roundEnding'   => bool,   ← lock anti double-trigger
     * ]
     */
    private array $rooms        = [];

    // Antrian matchmaking (array username)
    private array $waitingQueue = [];
    private int   $roomCounter  = 0;

    // ── Constructor ───────────────────────────────────────────
    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[SERVER] Logic siap (Room-Based). Max 2 pemain per room.\n";
    }

    // =========================================================
    //  RATCHET CALLBACKS
    // =========================================================
    public function onOpen(ConnectionInterface $conn): void {
        $this->clients->attach($conn);
        echo "[OPEN]   #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) return;

        echo "[MSG]    #{$from->resourceId} → {$data['type']}\n";

        switch ($data['type']) {
            case 'AUTH_LOGIN':      $this->handleAuthLogin($from, $data);    break;
            case 'AUTH_REGISTER':   $this->handleAuthRegister($from, $data); break;
            case 'JOIN':            $this->handleJoin($from, $data);         break;
            case 'CHAT':            $this->handleChat($from, $data);         break;
            case 'UPDATE_ICON':     $this->handleUpdateIcon($from, $data);   break;
            case 'FIND_MATCH':      $this->handleFindMatch($from, $data);    break;
            case 'PLAYER_READY':    $this->handlePlayerReady($from, $data);  break;
            case 'ITEM_CLICKED':    $this->handleItemClicked($from, $data);  break;
            case 'get_leaderboard': $this->handleGetLeaderboard($from);      break;
            default:
                echo "[WARN]   Unknown type: {$data['type']}\n";
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? "#{$conn->resourceId}";

        // ── Cleanup room jika pemain sedang dalam game ────────
        $roomId = $this->getPlayerRoomId($conn);
        if ($roomId && isset($this->rooms[$roomId])) {
            $room = &$this->rooms[$roomId];
            if ($room['gameStarted']) {
                $room['gameStarted'] = false;
                $this->broadcastRoom($roomId, [
                    'type'    => 'SYSTEM',
                    'message' => "{$username} disconnect. Game dibatalkan."
                ]);
            }
            // Reset semua pemain dalam room ini
            foreach ($this->players as &$p) {
                if (($p['roomId'] ?? null) === $roomId) {
                    $p['roomId'] = null; $p['isReady'] = false;
                    $p['findingMatch'] = false; $p['score'] = 0;
                    $p['combo'] = 0; $p['reactionLog'] = []; $p['penalties'] = 0;
                }
            }
            unset($p);
            unset($this->rooms[$roomId]);
            echo "[ROOM]   {$roomId} dihapus karena disconnect.\n";
        }

        // Hapus dari antrian matchmaking
        $this->waitingQueue = array_values(
            array_filter($this->waitingQueue, fn($u) => $u !== $username)
        );

        $this->removePlayer($conn);
        echo "[CLOSE]  {$username}\n";
        $this->broadcastAll(['type' => 'PLAYER_LIST', 'players' => $this->getPlayerListData()]);
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[ERROR]  #{$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    // =========================================================
    //  AUTH HANDLERS
    // =========================================================
    private function handleAuthLogin(ConnectionInterface $conn, array $data): void {
        $id   = trim($data['identifier'] ?? '');
        $pass = $data['password']         ?? '';
        if (!$id || !$pass) {
            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'Isi semua field.']));
            return;
        }
        $db = getDB();
        if (!$db) {
            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'DB tidak tersedia.']));
            return;
        }
        try {
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :id_user OR email = :id_email LIMIT 1");
            $stmt->execute([':id_user' => $id, ':id_email' => $id]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($pass, $user['password_hash'])) {
                $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'Username atau password salah.']));
                return;
            }

            echo "[AUTH]   Login berhasil: {$user['username']}\n";
            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>true,'user'=>[
                'username'    => $user['username'],
                'email'       => $user['email'],
                'icon'        => $user['icon'],
                'level'       => $user['level'],
                'totalXP'     => $user['total_xp'] ?? 0,
                'gamesPlayed' => $user['games_played'],
                'bestTime'    => $user['best_time'],
                'type'        => 'registered',
            ]]));
        } catch (\PDOException $e) {
            echo "[DB ERR] Login: {$e->getMessage()}\n";
            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'Error server.']));
        }
    }

    private function handleAuthRegister(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email']    ?? '');
        $pass     = $data['password']      ?? '';
        if (!$username || !$pass) {
            $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'Username & password wajib.']));
            return;
        }
        $db = getDB();
        if (!$db) {
            $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'DB tidak tersedia.']));
            return;
        }
        try {
            $stmt = $db->prepare("SELECT id FROM users WHERE username=:u OR (email!='' AND email=:e) LIMIT 1");
            $stmt->execute([':u' => $username, ':e' => $email]);
            if ($stmt->fetch()) {
                $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'Username/email sudah dipakai.']));
                return;
            }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (username,email,password_hash) VALUES (:u,:e,:p)")
               ->execute([':u' => $username, ':e' => $email, ':p' => $hash]);
            $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>true,'user'=>[
                'username'    => $username,
                'email'       => $email,
                'icon'        => 'fa-user',
                'level'       => 1,
                'totalXP'     => 0,
                'gamesPlayed' => 0,
                'bestTime'    => null,
                'type'        => 'registered',
            ]]));
        } catch (\PDOException $e) {
            echo "[DB ERR] Register: {$e->getMessage()}\n";
            $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'Error server.']));
        }
    }

    // =========================================================
    //  LOBBY HANDLERS
    // =========================================================
    private function handleJoin(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        $icon     = $data['icon']          ?? 'fa-user';
        $isGuest  = $data['isGuest']        ?? false;
        if (!$username) return;

        foreach ($this->players as $p) {
            if (strtolower($p['username']) === strtolower($username)) {
                $conn->send($this->encode(['type'=>'ERROR','message'=>"Nama '{$username}' sudah dipakai."]));
                return;
            }
        }

        $this->players[] = [
            'conn'         => $conn,
            'username'     => $username,
            'icon'         => $icon,
            'isGuest'      => $isGuest,
            'score'        => 0,
            'reactionLog'  => [],
            'combo'        => 0,
            'penalties'    => 0,
            'isReady'      => false,
            'findingMatch' => false,
            'roomId'       => null,   // ← null = belum di room
        ];

        echo "[JOIN]   {$username} bergabung. Total: " . count($this->players) . "\n";
        $this->broadcastAll(['type' => 'PLAYER_LIST', 'players' => $this->getPlayerListData()]);
        $this->sendChatHistory($conn);
    }

    private function handleChat(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';
        $message  = trim($data['message'] ?? '');
        if (!$message || strlen($message) > 200) return;
        $time = date('H:i');
        $this->broadcastAll(['type'=>'CHAT_MESSAGE','username'=>$username,'message'=>htmlspecialchars($message),'time'=>$time]);
        $this->saveChatMessage($username, $message);
    }

    private function handleUpdateIcon(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '';
        $icon     = $data['icon']     ?? 'fa-user';
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) { $p['icon'] = $icon; break; }
        }
        unset($p);
        $db = getDB();
        if ($db) {
            try {
                $db->prepare("UPDATE users SET icon=:i WHERE username=:u")->execute([':i'=>$icon,':u'=>$username]);
            } catch (\PDOException $e) {}
        }
    }

    // =========================================================
    //  MATCHMAKING — Room-Based (FIX UTAMA)
    // =========================================================
    private function handleFindMatch(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';

        // Cek apakah sudah di room/game
        $existingRoom = $this->getPlayerRoomId($conn);
        if ($existingRoom) {
            $conn->send($this->encode(['type'=>'SYSTEM','message'=>'Kamu sudah dalam game.']));
            return;
        }

        // Tandai player sebagai findingMatch
        foreach ($this->players as &$p) {
            if ($p['conn'] === $conn) { $p['findingMatch'] = true; break; }
        }
        unset($p);

        // Tambahkan ke antrian jika belum ada
        if (!in_array($username, $this->waitingQueue)) {
            $this->waitingQueue[] = $username;
        }

        echo "[MATCH]  {$username} masuk antrian. Queue: " . count($this->waitingQueue) . "\n";

        // Jika ada >= 2 pemain menunggu, cocokkan 2 pertama
        if (count($this->waitingQueue) >= 2) {
            $u1 = $this->waitingQueue[0];
            $u2 = $this->waitingQueue[1];
            // Hapus keduanya dari antrian
            $this->waitingQueue = array_values(array_slice($this->waitingQueue, 2));

            // Cari data player
            $p1Data = $this->findPlayer($u1);
            $p2Data = $this->findPlayer($u2);

            if (!$p1Data || !$p2Data) {
                echo "[MATCH]  ERROR: Salah satu pemain tidak ditemukan.\n";
                return;
            }

            // Buat room baru
            $roomId = 'room_' . (++$this->roomCounter) . '_' . time();
            $this->rooms[$roomId] = [
                'id'             => $roomId,
                'gameStarted'    => false,
                'currentRound'   => 0,
                'activeItems'    => [],
                'roundLog'       => [],
                'itemIdCounter'  => 0,
                'roundEnding'    => false,
            ];

            // Assign roomId ke kedua pemain
            foreach ($this->players as &$p) {
                if ($p['username'] === $u1 || $p['username'] === $u2) {
                    $p['findingMatch'] = false;
                    $p['roomId']       = $roomId;
                }
            }
            unset($p);

            // Kirim MATCH_FOUND ke masing-masing, dengan info lawan yang benar
            // FIX BUG: akses array dengan [], bukan object dengan ->
            foreach ($this->players as $p) {
                if ($p['username'] === $u1) {
                    $opp = $this->findPlayer($u2);
                    $p['conn']->send($this->encode([
                        'type'         => 'MATCH_FOUND',
                        'opponent'     => $u2,
                        'opponentIcon' => $opp['icon'] ?? 'fa-robot',
                    ]));
                }
                if ($p['username'] === $u2) {
                    $opp = $this->findPlayer($u1);
                    $p['conn']->send($this->encode([
                        'type'         => 'MATCH_FOUND',
                        'opponent'     => $u1,
                        'opponentIcon' => $opp['icon'] ?? 'fa-robot',
                    ]));
                }
            }

            echo "[MATCH]  {$u1} vs {$u2} → Room: {$roomId}\n";
        } else {
            $conn->send($this->encode(['type'=>'MATCH_SEARCHING','message'=>'Mencari lawan...']));
        }
    }

    private function handlePlayerReady(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';
        $roomId   = $this->getPlayerRoomId($conn);

        if (!$roomId || !isset($this->rooms[$roomId])) {
            echo "[WARN]   PLAYER_READY dari {$username} tapi tidak ada room.\n";
            return;
        }

        // Tandai ready
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) { $p['isReady'] = true; break; }
        }
        unset($p);

        // Broadcast ke room saja (bukan semua client)
        $this->broadcastRoom($roomId, ['type'=>'PLAYER_READY_UPDATE','username'=>$username,'isReady'=>true]);
        echo "[READY]  {$username} @ Room {$roomId}\n";

        // Cek apakah KEDUA pemain dalam room ini sudah ready
        $roomPlayers = $this->getRoomPlayers($roomId);
        $readyCount  = count(array_filter($roomPlayers, fn($p) => $p['isReady']));

        if ($readyCount >= 2 && !$this->rooms[$roomId]['gameStarted']) {
            $this->scheduleCall(1000, fn() => $this->startGame($roomId));
        }
    }

    // =========================================================
    //  ITEM CLICKED
    // =========================================================
    private function handleItemClicked(ConnectionInterface $from, array $data): void {
        $username = $data['username'] ?? '?';
        $itemId   = $data['itemId']   ?? '';

        // Cari room pemain ini
        $roomId = $this->getPlayerRoomId($from);
        if (!$roomId || !isset($this->rooms[$roomId])) return;

        $room = &$this->rooms[$roomId];
        if (!$room['gameStarted']) return;

        // Validasi: item ada di room ini?
        if (!isset($room['activeItems'][$itemId])) {
            echo "[IGNORE] Item {$itemId} tidak ada di room {$roomId} (expired/diklik)\n";
            return;
        }

        $item = $room['activeItems'][$itemId];

        // Validasi expired?
        $serverNowMs = round(microtime(true) * 1000, 2);
        $elapsedMs   = $serverNowMs - $item['spawnedAt'];
        $maxAllowed  = $item['duration'] + self::LATENCY_GRACE_MS;

        if ($elapsedMs > $maxAllowed) {
            echo "[LATE]   {$username} klik {$itemId} terlambat ({$elapsedMs}ms > {$maxAllowed}ms)\n";
            return;
        }

        // Hapus item agar tidak bisa diklik 2x
        unset($room['activeItems'][$itemId]);

        $reactionMs = round($elapsedMs, 2);
        echo "[CLICK]  {$username} @ Room {$roomId}: {$itemId} ({$item['type']}) @ {$reactionMs}ms\n";

        // Update skor pemain
        foreach ($this->players as &$p) {
            if ($p['username'] !== $username) continue;
            if ($item['type'] === 'bad') {
                $p['score'] = max(0, $p['score'] - self::PENALTY_SCORE);
                $p['combo'] = 0;
                $p['penalties']++;
            } elseif ($item['type'] === 'bonus') {
                $p['score'] += 250;
                $p['combo']++;
                $p['reactionLog'][] = $reactionMs;
            } else {
                $comboBonus = min($p['combo'], 10) * 10;
                $p['score'] += (100 + $comboBonus);
                $p['combo']++;
                $p['reactionLog'][] = $reactionMs;
            }
            break;
        }
        unset($p);

        $this->broadcastScoreUpdate($roomId);
        $this->checkRoundEnd($roomId);
    }

    // =========================================================
    //  GAME FLOW — Semua fungsi terima $roomId
    // =========================================================
    private function startGame(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];

        $room['gameStarted']    = true;
        $room['currentRound']   = 0;
        $room['roundLog']       = [];
        $room['activeItems']    = [];
        $room['itemIdCounter']  = 0;
        $room['roundEnding']    = false;

        // Reset skor pemain dalam room
        foreach ($this->players as &$p) {
            if (($p['roomId'] ?? null) !== $roomId) continue;
            $p['score'] = 0; $p['reactionLog'] = []; $p['combo'] = 0; // FIX: int 0
            $p['penalties'] = 0; $p['isReady'] = false;
        }
        unset($p);

        echo "[GAME]   Dimulai! Room: {$roomId}\n";
        $this->broadcastRoom($roomId, ['type' => 'START_GAME']);
        $this->scheduleCall(1500, fn() => $this->startRound($roomId));
    }

    private function startRound(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];
        if (!$room['gameStarted']) return; // FIX: guard stale timer

        $room['currentRound']++;
        if ($room['currentRound'] > self::MAX_ROUNDS) {
            $this->endGame($roomId);
            return;
        }

        $room['activeItems'] = [];
        $room['roundEnding'] = false;

        echo "[ROUND]  Room {$roomId}: Ronde {$room['currentRound']}\n";
        $this->broadcastRoom($roomId, ['type'=>'ROUND_UPDATE','round'=>$room['currentRound']]);
        $this->broadcastRoom($roomId, ['type'=>'WAIT']); // client: tampilkan "Get Ready"

        $delay = rand(self::ROUND_DELAY_MIN, self::ROUND_DELAY_MAX);
        $this->scheduleCall($delay, fn() => $this->spawnItems($roomId));
    }

    private function spawnItems(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];
        if (!$room['gameStarted']) return; // FIX: guard stale timer

        $round       = $room['currentRound'];
        $cfg         = self::ITEM_CONFIG[$round] ?? self::ITEM_CONFIG[5];
        $count       = $cfg['count'];
        $duration    = $cfg['duration'];
        $bombChance  = $cfg['bomb_chance'];
        $bonusChance = $cfg['bonus_chance'];

        $items     = [];
        $spawnedAt = round(microtime(true) * 1000, 2);

        for ($i = 0; $i < $count; $i++) {
            $room['itemIdCounter']++;
            // Item ID mengandung roomId agar unik antar room
            $id   = "item_{$roomId}_{$round}_{$room['itemIdCounter']}";
            $rand = mt_rand() / mt_getrandmax();

            if ($rand < $bombChance) {
                $type = 'bad'; $itemDur = $duration + 500;
            } elseif ($rand > (1 - $bonusChance)) {
                $type = 'bonus'; $itemDur = 1200;
            } else {
                $type = 'good'; $itemDur = $duration + mt_rand(-200, 200);
            }

            $itemData = [
                'id'        => $id,
                'type'      => $type,
                'top'       => mt_rand(15, 75),
                'left'      => mt_rand(10, 80),
                'duration'  => $itemDur,
                'spawnedAt' => $spawnedAt,
                'round'     => $round,
            ];

            $room['activeItems'][$id] = $itemData;

            $items[] = [
                'id'       => $id,
                'type'     => $type,
                'top'      => $itemData['top'],
                'left'     => $itemData['left'],
                'duration' => $itemDur,
            ];

            $this->scheduleItemExpiry($roomId, $id, $itemDur);
        }

        echo "[SPAWN]  Room {$roomId} Ronde {$round}: {$count} item\n";
        $this->broadcastRoom($roomId, ['type'=>'SPAWN_ITEMS','items'=>$items,'round'=>$round]);
    }

    private function scheduleItemExpiry(string $roomId, string $itemId, int $durationMs): void {
        $this->scheduleCall($durationMs, function() use ($roomId, $itemId) {
            if (!isset($this->rooms[$roomId])) return;
            $room = &$this->rooms[$roomId];
            if (!isset($room['activeItems'][$itemId])) return; // sudah diklik

            $item = $room['activeItems'][$itemId];
            unset($room['activeItems'][$itemId]);

            echo "[EXPIRY] Room {$roomId} Item {$itemId} expired.\n";

            $resetCombo = ($item['type'] === 'good');
            $this->broadcastRoom($roomId, ['type'=>'ITEM_EXPIRED','itemId'=>$itemId,'resetCombo'=>$resetCombo]);

            if ($resetCombo) {
                foreach ($this->players as &$p) {
                    if (($p['roomId'] ?? null) === $roomId) { $p['combo'] = 0; }
                }
                unset($p);
            }

            $this->checkRoundEnd($roomId);
        });
    }

    private function checkRoundEnd(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];

        if (!empty($room['activeItems'])) return;   // masih ada item
        if ($room['roundEnding']) return;            // FIX: anti double-trigger

        $room['roundEnding'] = true;

        echo "[ROUND]  Room {$roomId}: Ronde {$room['currentRound']} selesai.\n";

        // Hitung skor
        $scores = [];
        foreach ($this->getRoomPlayers($roomId) as $p) {
            $scores[$p['username']] = $p['score'];
        }
        arsort($scores);
        $roundWinner = array_key_first($scores);

        $this->broadcastRoom($roomId, ['type'=>'ROUND_RESULT','roundWinner'=>$roundWinner,'scores'=>$scores]);
        $room['roundLog'][] = ['round'=>$room['currentRound'],'winner'=>$roundWinner,'scores'=>$scores];

        if ($room['currentRound'] >= self::MAX_ROUNDS) {
            $this->scheduleCall(2500, fn() => $this->endGame($roomId));
        } else {
            $this->scheduleCall(2500, fn() => $this->startRound($roomId));
        }
    }

    private function endGame(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];
        $room['gameStarted'] = false;

        echo "[GAME]   Selesai! Room: {$roomId}\n";

        $statsPerPlayer = [];
        foreach ($this->getRoomPlayers($roomId) as $p) {
            $log  = $p['reactionLog'];
            $avg  = count($log) ? round(array_sum($log)/count($log), 2) : null;
            $best = count($log) ? round(min($log), 2) : null;
            $cons = count($log) > 1 ? round(max($log)-min($log), 2) : null;

            $statsPerPlayer[] = [
                'username'    => $p['username'],
                'icon'        => $p['icon'],
                'isGuest'     => $p['isGuest'],
                'score'       => $p['score'],
                'avgTime'     => $avg,
                'bestTime'    => $best,
                'consistency' => $cons,
                'penalties'   => $p['penalties'],
                'roundsWon'   => count($log),
                'comboMax'    => $p['combo'],
            ];
        }

        usort($statsPerPlayer, fn($a,$b) => $b['score'] <=> $a['score']);

        $this->broadcastRoom($roomId, ['type'=>'GAME_OVER','stats'=>$statsPerPlayer]);
        echo "[STATS]  " . json_encode($statsPerPlayer, JSON_PRETTY_PRINT) . "\n";

        $this->saveToDatabase($statsPerPlayer, $room['roundLog']);

        // Bersihkan room setelah 5 detik (beri waktu client terima GAME_OVER)
        $this->scheduleCall(5000, function() use ($roomId) {
            if (!isset($this->rooms[$roomId])) return;
            // Reset player state
            foreach ($this->players as &$p) {
                if (($p['roomId'] ?? null) === $roomId) {
                    $p['roomId'] = null; $p['isReady'] = false;
                    $p['score'] = 0; $p['combo'] = 0;
                    $p['reactionLog'] = []; $p['penalties'] = 0;
                }
            }
            unset($p);
            unset($this->rooms[$roomId]);
            echo "[ROOM]   {$roomId} dihapus setelah game selesai.\n";
            $this->broadcastAll(['type' => 'PLAYER_LIST', 'players' => $this->getPlayerListData()]);
        });
    }

    // =========================================================
    //  BROADCAST SCORE UPDATE (per room)
    // =========================================================
    private function broadcastScoreUpdate(string $roomId): void {
        $roomPlayers = $this->getRoomPlayers($roomId);

        foreach ($roomPlayers as $me) {
            $myAvg  = count($me['reactionLog']) ? round(array_sum($me['reactionLog'])/count($me['reactionLog']), 0) : null;
            $myBest = count($me['reactionLog']) ? round(min($me['reactionLog']), 0) : null;

            $oppScore = 0;
            foreach ($roomPlayers as $opp) {
                if ($opp['username'] !== $me['username']) { $oppScore = $opp['score']; break; }
            }

            $me['conn']->send($this->encode([
                'type'           => 'SCORE_UPDATE',
                'myScore'        => $me['score'],          // int, bukan string
                'opponentScore'  => $oppScore,
                'myCombo'        => $me['combo'],
                'myAvgReaction'  => $myAvg  ? $myAvg  . 'ms' : '---',
                'myBestReaction' => $myBest ? $myBest . 'ms' : '---',
            ]));
        }
    }

    // =========================================================
    //  LEADERBOARD
    // =========================================================
    private function handleGetLeaderboard(ConnectionInterface $from): void {
        $db = getDB();
        if (!$db) {
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>[]]));
            return;
        }
        try {
            $stmt = $db->query("SELECT username,icon,total_score,avg_reaction_time,best_time,games_played,level FROM players_stats ORDER BY total_score DESC LIMIT 10");
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>$stmt->fetchAll()]));
        } catch (\PDOException $e) {
            echo "[DB ERR] Leaderboard: {$e->getMessage()}\n";
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>[]]));
        }
    }

    // =========================================================
    //  DATABASE
    // =========================================================
    private function saveToDatabase(array $stats, array $roundLog): void {
        $db = getDB();
        if (!$db) { echo "[DB ERR] Skip.\n"; return; }
        try {
            $stmtStats = $db->prepare("
                INSERT INTO players_stats (username, icon, total_score, avg_reaction_time, best_time, games_played)
                VALUES (:u, :i, :s, :a, :b, 1)
                ON DUPLICATE KEY UPDATE
                    icon              = VALUES(icon),
                    total_score       = total_score + VALUES(total_score),
                    avg_reaction_time = IF(avg_reaction_time IS NULL, VALUES(avg_reaction_time),
                                          ROUND((avg_reaction_time + VALUES(avg_reaction_time))/2, 2)),
                    best_time         = IF(best_time IS NULL OR VALUES(best_time) < best_time, VALUES(best_time), best_time),
                    games_played      = games_played + 1,
                    level             = GREATEST(1, FLOOR(games_played/5)+1)
            ");

            foreach ($stats as $p) {
                if ($p['isGuest']) continue;
                $stmtStats->execute([':u'=>$p['username'],':i'=>$p['icon'],':s'=>$p['score'],':a'=>$p['avgTime'],':b'=>$p['bestTime']]);

                if ($p['bestTime'] !== null) {
                    $db->prepare("UPDATE users SET best_time=IF(best_time IS NULL OR :b<best_time,:b,best_time), games_played=games_played+1, level=GREATEST(1,FLOOR(games_played/5)+1) WHERE username=:u")
                       ->execute([':b'=>$p['bestTime'],':u'=>$p['username']]);
                }
                echo "[DB]     {$p['username']} | Skor: {$p['score']}\n";
            }

            if (!empty($roundLog)) {
                $stmtRound = $db->prepare("INSERT INTO round_logs (username, reaction_time, round_score, is_foul) VALUES (:u,:t,:s,0)");
                foreach ($roundLog as $r) {
                    foreach ($stats as $p) {
                        $stmtRound->execute([':u'=>$p['username'],':t'=>$p['avgTime']??0,':s'=>$r['scores'][$p['username']]??0]);
                    }
                }
                echo "[DB]     Round logs: " . count($roundLog) . "\n";
            }
        } catch (\PDOException $e) {
            echo "[DB ERR] {$e->getMessage()}\n";
        }
    }

    // =========================================================
    //  HELPERS
    // =========================================================

    /** Broadcast ke semua client (lobby) */
    private function broadcastAll(array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->clients as $c) { $c->send($json); }
    }

    /** Broadcast hanya ke pemain dalam satu room */
    private function broadcastRoom(string $roomId, array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->players as $p) {
            if (($p['roomId'] ?? null) === $roomId) {
                $p['conn']->send($json);
            }
        }
    }

    /** Ambil semua player dalam room tertentu */
    private function getRoomPlayers(string $roomId): array {
        return array_values(
            array_filter($this->players, fn($p) => ($p['roomId'] ?? null) === $roomId)
        );
    }

    /** Ambil roomId dari connection */
    private function getPlayerRoomId(ConnectionInterface $conn): ?string {
        foreach ($this->players as $p) {
            if ($p['conn'] === $conn) return $p['roomId'] ?? null;
        }
        return null;
    }

    /** Cari data player berdasarkan username */
    private function findPlayer(string $username): ?array {
        foreach ($this->players as $p) {
            if ($p['username'] === $username) return $p;
        }
        return null;
    }

    /** Data list pemain untuk broadcast PLAYER_LIST */
    private function getPlayerListData(): array {
        return array_map(fn($p) => [
            'name'    => $p['username'],
            'icon'    => $p['icon'],
            'score'   => $p['score'],
            'isGuest' => $p['isGuest'],
            'ready'   => $p['isReady'],
            'level'   => 1,
        ], $this->players);
    }

    private function removePlayer(ConnectionInterface $conn): void {
        $this->players = array_values(array_filter($this->players, fn($p) => $p['conn'] !== $conn));
    }

    private function getUsernameByConn(ConnectionInterface $conn): ?string {
        foreach ($this->players as $p) {
            if ($p['conn'] === $conn) return $p['username'];
        }
        return null;
    }

    private function encode(array $data): string { return json_encode($data); }

    private function scheduleCall(int $ms, callable $cb): void {
        \React\EventLoop\Loop::get()->addTimer($ms / 1000.0, $cb);
    }

    private function saveChatMessage(string $username, string $message): void {
        $db = getDB();
        if (!$db) return;
        try {
            $db->prepare("INSERT INTO chat_messages (username,message) VALUES (:u,:m)")
               ->execute([':u'=>$username,':m'=>$message]);
        } catch (\PDOException $e) {
            echo "[DB ERR] Chat: {$e->getMessage()}\n";
        }
    }

    private function sendChatHistory(ConnectionInterface $conn): void {
        $db = getDB();
        if (!$db) return;
        try {
            $rows = array_reverse(
                $db->query("SELECT username,message,created_at FROM chat_messages ORDER BY created_at DESC LIMIT 20")
                   ->fetchAll()
            );
            foreach ($rows as $r) {
                $conn->send($this->encode([
                    'type'     => 'CHAT_MESSAGE',
                    'username' => $r['username'],
                    'message'  => $r['message'],
                    'time'     => date('H:i', strtotime($r['created_at'])),
                ]));
            }
        } catch (\PDOException $e) {}
    }
}