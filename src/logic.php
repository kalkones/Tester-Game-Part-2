<?php
// ============================================================
//  REACTION DUEL — logic.php  (Room-Based Authoritative Server)
//
//  ARSITEKTUR "GEDUNG OLAHRAGA":
//  Setiap 2 pemain yang bertemu → dibuatkan 1 Room terpisah.
//  Semua state game (item, skor, ronde) hidup di dalam Room,
//  bukan di variabel global class.
//
//  $this->rooms   = ['room_1' => [...], 'room_2' => [...]]
//  $this->players = semua koneksi aktif (lobby + in-game)
//
//  broadcastRoom() → hanya kirim ke 2 pemain dalam room itu.
//  broadcastLobby() → kirim ke semua (chat & player list).
// ============================================================

require_once __DIR__ . '/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Logic implements MessageComponentInterface {

    // ── Konstanta Game ────────────────────────────────────────
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

    /**
     * Semua pemain yang terkoneksi (termasuk yang sedang di lobby).
     * [
     *   'conn'         => ConnectionInterface,
     *   'username'     => string,
     *   'icon'         => string,
     *   'isGuest'      => bool,
     *   'level'        => int,
     *   'roomId'       => ?string,   <- null jika di lobby
     *   'score'        => int,
     *   'reactionLog'  => float[],
     *   'combo'        => int,
     *   'penalties'    => int,
     *   'isReady'      => bool,
     *   'findingMatch' => bool,
     * ]
     */
    private array $players = [];

    /**
     * Semua room aktif.
     * [
     *   'id'            => string,
     *   'usernames'     => [u1, u2],
     *   'gameStarted'   => bool,
     *   'currentRound'  => int,
     *   'activeItems'   => [],
     *   'roundLog'      => [],
     *   'itemIdCounter' => int,
     *   'roundEnding'   => bool,  <- lock anti double-trigger
     * ]
     */
    private array $rooms        = [];
    private array $waitingQueue = [];  // antrian matchmaking (username saja)
    private int   $roomCounter  = 0;

    // ── Constructor ───────────────────────────────────────────
    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[SERVER] Room-Based Server siap!\n";
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
        echo "[MSG]    #{$from->resourceId} -> {$data['type']}\n";

        switch ($data['type']) {
            case 'AUTH_LOGIN':      $this->handleAuthLogin($from, $data);    break;
            case 'AUTH_REGISTER':   $this->handleAuthRegister($from, $data); break;
            case 'JOIN':            $this->handleJoin($from, $data);         break;
            case 'CHAT':            $this->handleChat($from, $data);         break;
            case 'UPDATE_ICON':     $this->handleUpdateIcon($from, $data);   break;
            case 'FIND_MATCH':      $this->handleFindMatch($from, $data);    break;
            case 'PLAYER_READY':    $this->handlePlayerReady($from, $data);  break;
            case 'ITEM_CLICKED':    $this->handleItemClicked($from, $data);  break;
            case 'get_leaderboard':   $this->handleGetLeaderboard($from);         break;
            case 'get_player_stats':  $this->handleGetPlayerStats($from, $data);  break;
            default:
                echo "[WARN]   Unknown: {$data['type']}\n";
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? "#{$conn->resourceId}";

        // Bersihkan room jika pemain ini sedang bermain
        $roomId = $this->getPlayerRoomId($conn);
        if ($roomId !== null && isset($this->rooms[$roomId])) {
            $room = &$this->rooms[$roomId];
            if ($room['gameStarted']) {
                $room['gameStarted'] = false;
                $this->broadcastRoom($roomId, [
                    'type'    => 'SYSTEM',
                    'message' => "{$username} disconnect. Game dibatalkan.",
                ]);
            }
            foreach ($this->players as &$p) {
                if (($p['roomId'] ?? null) === $roomId) {
                    $p['roomId'] = null; $p['isReady'] = false;
                    $p['findingMatch'] = false; $p['score'] = 0;
                    $p['reactionLog'] = []; $p['combo'] = 0; $p['penalties'] = 0;
                }
            }
            unset($p);
            unset($this->rooms[$roomId]);
            echo "[ROOM]   {$roomId} dihapus (disconnect).\n";
        }

        $this->waitingQueue = array_values(
            array_filter($this->waitingQueue, fn($u) => $u !== $username)
        );
        $this->removePlayer($conn);
        echo "[CLOSE]  {$username}\n";
        $this->broadcastPlayerList();
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[ERROR]  #{$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

    // =========================================================
    //  AUTH
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
            $stmt = $db->prepare("SELECT * FROM users WHERE username=:u OR email=:e LIMIT 1");
            $stmt->execute([':u'=>$id,':e'=>$id]);
            $user = $stmt->fetch();
            if (!$user || !password_verify($pass, $user['password_hash'])) {
                $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'Username atau password salah.']));
                return;
            }
            echo "[AUTH]   Login: {$user['username']}\n";
            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>true,'user'=>[
                'username'    => $user['username'],
                'email'       => $user['email'],
                'icon'        => $user['icon'] ?? 'fa-user',
                'level'       => (int)($user['level'] ?? 1),
                'totalXP'     => (int)($user['total_xp'] ?? 0),
                'gamesPlayed' => (int)($user['games_played'] ?? 0),
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
            $stmt->execute([':u'=>$username,':e'=>$email]);
            if ($stmt->fetch()) {
                $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'Username/email sudah dipakai.']));
                return;
            }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (username,email,password_hash) VALUES (:u,:e,:p)")
               ->execute([':u'=>$username,':e'=>$email,':p'=>$hash]);
            $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>true,'user'=>[
                'username'=>$username,'email'=>$email,'icon'=>'fa-user',
                'level'=>1,'totalXP'=>0,'gamesPlayed'=>0,'bestTime'=>null,'type'=>'registered',
            ]]));
        } catch (\PDOException $e) {
            echo "[DB ERR] Register: {$e->getMessage()}\n";
            $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'Error server.']));
        }
    }

    // =========================================================
    //  LOBBY
    // =========================================================
    private function handleJoin(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        $icon     = $data['icon']          ?? 'fa-user';
        $isGuest  = $data['isGuest']        ?? false;
        $level    = (int)($data['level']   ?? 1);
        if (!$username) return;

        // Cek duplikat SEBELUM menambah ke array (bug lama: cek setelah tambah)
        foreach ($this->players as $p) {
            if (strtolower($p['username']) === strtolower($username)) {
                $conn->send($this->encode(['type'=>'ERROR','message'=>"Username '{$username}' sudah online."]));
                return;
            }
        }

        $this->players[] = [
            'conn'         => $conn,
            'username'     => $username,
            'icon'         => $icon,
            'isGuest'      => $isGuest,
            'level'        => $level,
            'roomId'       => null,
            'score'        => 0,
            'reactionLog'  => [],
            'combo'        => 0,
            'penalties'    => 0,
            'isReady'      => false,
            'findingMatch' => false,
        ];

        echo "[JOIN]   {$username} online. Total: " . count($this->players) . "\n";
        $this->broadcastPlayerList();
        $this->sendChatHistory($conn);
    }

    private function handleChat(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';
        $message  = trim($data['message'] ?? '');
        if (!$message || strlen($message) > 200) return;
        $this->broadcastLobby([
            'type'=>'CHAT_MESSAGE','username'=>$username,
            'message'=>htmlspecialchars($message),'time'=>date('H:i'),
        ]);
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
            try { $db->prepare("UPDATE users SET icon=:i WHERE username=:u")->execute([':i'=>$icon,':u'=>$username]); }
            catch (\PDOException $e) {}
        }
    }

    // =========================================================
    //  MATCHMAKING — ROOM-BASED
    // =========================================================
    private function handleFindMatch(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';

        if ($this->getPlayerRoomId($conn) !== null) {
            $conn->send($this->encode(['type'=>'SYSTEM','message'=>'Kamu sudah dalam game.']));
            return;
        }

        foreach ($this->players as &$p) {
            if ($p['conn'] === $conn) { $p['findingMatch'] = true; break; }
        }
        unset($p);

        if (!in_array($username, $this->waitingQueue, true)) {
            $this->waitingQueue[] = $username;
        }

        echo "[QUEUE]  {$username} mengantri. Queue: " . count($this->waitingQueue) . "\n";

        if (count($this->waitingQueue) >= 2) {
            $u1 = array_shift($this->waitingQueue);
            $u2 = array_shift($this->waitingQueue);

            $p1 = $this->findPlayerByUsername($u1);
            $p2 = $this->findPlayerByUsername($u2);

            if (!$p1 || !$p2) {
                if ($p1) array_unshift($this->waitingQueue, $u1);
                if ($p2) array_unshift($this->waitingQueue, $u2);
                echo "[MATCH]  ERROR: pemain tidak ditemukan.\n";
                return;
            }

            $this->roomCounter++;
            $roomId = "room_{$this->roomCounter}";

            $this->rooms[$roomId] = [
                'id'            => $roomId,
                'usernames'     => [$u1, $u2],
                'gameStarted'   => false,
                'currentRound'  => 0,
                'activeItems'   => [],
                'roundLog'      => [],
                'itemIdCounter' => 0,
                'roundEnding'   => false,
            ];

            foreach ($this->players as &$p) {
                if ($p['username'] === $u1 || $p['username'] === $u2) {
                    $p['roomId'] = $roomId;
                    $p['findingMatch'] = false;
                }
            }
            unset($p);

            echo "[MATCH]  {$u1} vs {$u2} -> {$roomId}\n";

            // Kirim MATCH_FOUND ke masing-masing dengan info lawan yang benar
            // PENTING: akses array dengan [], bukan object ->
            $p1['conn']->send($this->encode([
                'type'         => 'MATCH_FOUND',
                'opponent'     => $u2,
                'opponentIcon' => $p2['icon'] ?? 'fa-robot',
            ]));
            $p2['conn']->send($this->encode([
                'type'         => 'MATCH_FOUND',
                'opponent'     => $u1,
                'opponentIcon' => $p1['icon'] ?? 'fa-robot',
            ]));

        } else {
            $conn->send($this->encode(['type'=>'MATCH_SEARCHING','message'=>'Mencari lawan...']));
        }
    }

    private function handlePlayerReady(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';
        $roomId   = $this->getPlayerRoomId($conn);

        if (!$roomId || !isset($this->rooms[$roomId])) {
            echo "[WARN]   PLAYER_READY dari {$username} tanpa room.\n";
            return;
        }

        foreach ($this->players as &$p) {
            if ($p['username'] === $username) { $p['isReady'] = true; break; }
        }
        unset($p);

        $this->broadcastRoom($roomId, ['type'=>'PLAYER_READY_UPDATE','username'=>$username,'isReady'=>true]);
        echo "[READY]  {$username} @ {$roomId}\n";

        $roomPlayers = $this->getRoomPlayers($roomId);
        $readyCount  = count(array_filter($roomPlayers, fn($p) => $p['isReady']));

        if ($readyCount >= 2 && !$this->rooms[$roomId]['gameStarted']) {
            $this->scheduleCall(1000, fn() => $this->startGame($roomId));
        }
    }

    // =========================================================
    //  ITEM CLICKED — Anti-Cheat Per Room
    // =========================================================
    private function handleItemClicked(ConnectionInterface $from, array $data): void {
        $username = $data['username'] ?? '?';
        $itemId   = $data['itemId']   ?? '';

        $roomId = $this->getPlayerRoomId($from);
        if (!$roomId || !isset($this->rooms[$roomId])) return;

        $room = &$this->rooms[$roomId];
        if (!$room['gameStarted']) return;

        if (!isset($room['activeItems'][$itemId])) {
            echo "[IGNORE] {$username}: {$itemId} tidak ada di {$roomId}\n";
            return;
        }

        $item       = $room['activeItems'][$itemId];
        $nowMs      = round(microtime(true) * 1000, 2);
        $elapsedMs  = $nowMs - $item['spawnedAt'];
        $maxAllowed = $item['duration'] + self::LATENCY_GRACE_MS;

        if ($elapsedMs > $maxAllowed) {
            echo "[LATE]   {$username}: {$elapsedMs}ms > {$maxAllowed}ms\n";
            return;
        }

        unset($room['activeItems'][$itemId]);
        $reactionMs = round($elapsedMs, 2);
        echo "[CLICK]  {$username} @ {$roomId}: {$itemId} ({$item['type']}) {$reactionMs}ms\n";

        foreach ($this->players as &$p) {
            if ($p['username'] !== $username) continue;
            if ($item['type'] === 'bad') {
                $p['score']     = max(0, $p['score'] - self::PENALTY_SCORE);
                $p['combo']     = 0;
                $p['penalties']++;
            } elseif ($item['type'] === 'bonus') {
                $p['score']         += 250;
                $p['combo']++;
                $p['reactionLog'][] = $reactionMs;
            } else {
                $comboBonus          = min($p['combo'], 10) * 10;
                $p['score']         += (100 + $comboBonus);
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
    //  GAME FLOW — Semua fungsi menerima $roomId
    // =========================================================
    private function startGame(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];

        $room['gameStarted']   = true;
        $room['currentRound']  = 0;
        $room['activeItems']   = [];
        $room['roundLog']      = [];
        $room['itemIdCounter'] = 0;
        $room['roundEnding']   = false;

        foreach ($this->players as &$p) {
            if (($p['roomId'] ?? null) !== $roomId) continue;
            $p['score'] = 0; $p['reactionLog'] = []; $p['combo'] = 0;
            $p['penalties'] = 0; $p['isReady'] = false;
        }
        unset($p);

        echo "[GAME]   Dimulai! {$roomId}\n";
        $this->broadcastRoom($roomId, ['type'=>'START_GAME']);
        $this->scheduleCall(1500, fn() => $this->startRound($roomId));
    }

    private function startRound(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];
        if (!$room['gameStarted']) return; // guard stale timer

        $room['currentRound']++;
        $room['roundEnding'] = false;

        if ($room['currentRound'] > self::MAX_ROUNDS) {
            $this->endGame($roomId);
            return;
        }

        $room['activeItems'] = [];

        echo "[ROUND]  {$roomId}: Ronde {$room['currentRound']}\n";
        $this->broadcastRoom($roomId, ['type'=>'ROUND_UPDATE','round'=>$room['currentRound']]);
        $this->broadcastRoom($roomId, ['type'=>'WAIT']);

        $delay = rand(self::ROUND_DELAY_MIN, self::ROUND_DELAY_MAX);
        $this->scheduleCall($delay, fn() => $this->spawnItems($roomId));
    }

    private function spawnItems(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];
        if (!$room['gameStarted']) return; // guard stale timer

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
            $id   = "item_{$roomId}_r{$round}_{$room['itemIdCounter']}";
            $rand = mt_rand() / mt_getrandmax();

            if ($rand < $bombChance) {
                $type = 'bad';   $itemDur = $duration + 500;
            } elseif ($rand > (1 - $bonusChance)) {
                $type = 'bonus'; $itemDur = 1200;
            } else {
                $type = 'good';  $itemDur = $duration + mt_rand(-200, 200);
            }

            $itemData = [
                'id'=>$id,'type'=>$type,
                'top'=>mt_rand(15,75),'left'=>mt_rand(10,80),
                'duration'=>$itemDur,'spawnedAt'=>$spawnedAt,'round'=>$round,
            ];
            $room['activeItems'][$id] = $itemData;
            $items[] = ['id'=>$id,'type'=>$type,'top'=>$itemData['top'],'left'=>$itemData['left'],'duration'=>$itemDur];
            $this->scheduleItemExpiry($roomId, $id, $itemDur);
        }

        echo "[SPAWN]  {$roomId} Ronde {$round}: {$count} item\n";
        $this->broadcastRoom($roomId, ['type'=>'SPAWN_ITEMS','items'=>$items,'round'=>$round]);
    }

    private function scheduleItemExpiry(string $roomId, string $itemId, int $durationMs): void {
        $this->scheduleCall($durationMs, function () use ($roomId, $itemId) {
            if (!isset($this->rooms[$roomId])) return;
            $room = &$this->rooms[$roomId];
            if (!isset($room['activeItems'][$itemId])) return;

            $item = $room['activeItems'][$itemId];
            unset($room['activeItems'][$itemId]);
            echo "[EXPIRY] {$roomId}: {$itemId} expired\n";

            $resetCombo = ($item['type'] === 'good');
            $this->broadcastRoom($roomId, ['type'=>'ITEM_EXPIRED','itemId'=>$itemId,'resetCombo'=>$resetCombo]);

            if ($resetCombo) {
                foreach ($this->players as &$p) {
                    if (($p['roomId'] ?? null) === $roomId) $p['combo'] = 0;
                }
                unset($p);
            }
            $this->checkRoundEnd($roomId);
        });
    }

    private function checkRoundEnd(string $roomId): void {
        if (!isset($this->rooms[$roomId])) return;
        $room = &$this->rooms[$roomId];
        if (!empty($room['activeItems'])) return;
        if ($room['roundEnding'])         return; // lock anti double-trigger

        $room['roundEnding'] = true;
        echo "[ROUND]  {$roomId}: Ronde {$room['currentRound']} selesai.\n";

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
        echo "[GAME]   Selesai! {$roomId}\n";

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
                'reactionLog' => $log,
            ];
        }

        usort($statsPerPlayer, fn($a,$b) => $b['score'] <=> $a['score']);

        $this->broadcastRoom($roomId, [
            'type'     => 'GAME_OVER',
            'stats'    => $statsPerPlayer,
            'roundLog' => $room['roundLog'],
        ]);

        echo "[STATS]  " . json_encode($statsPerPlayer, JSON_PRETTY_PRINT) . "\n";
        $this->saveToDatabase($statsPerPlayer, $room['roundLog']);

        // Hapus room setelah 5 detik
        $this->scheduleCall(5000, function () use ($roomId) {
            if (!isset($this->rooms[$roomId])) return;
            foreach ($this->players as &$p) {
                if (($p['roomId'] ?? null) === $roomId) {
                    $p['roomId'] = null; $p['isReady'] = false;
                    $p['score'] = 0; $p['reactionLog'] = [];
                    $p['combo'] = 0; $p['penalties'] = 0;
                }
            }
            unset($p);
            unset($this->rooms[$roomId]);
            echo "[ROOM]   {$roomId} dihapus (game selesai).\n";
            $this->broadcastPlayerList();
        });
    }

    // =========================================================
    //  BROADCAST SCORE UPDATE (hanya ke pemain di room)
    // =========================================================
    private function broadcastScoreUpdate(string $roomId): void {
        $roomPlayers = $this->getRoomPlayers($roomId);
        foreach ($roomPlayers as $me) {
            $log    = $me['reactionLog'];
            $myAvg  = count($log) ? round(array_sum($log)/count($log), 0) : null;
            $myBest = count($log) ? round(min($log), 0) : null;
            $oppScore = 0;
            foreach ($roomPlayers as $opp) {
                if ($opp['username'] !== $me['username']) { $oppScore = $opp['score']; break; }
            }
            $me['conn']->send($this->encode([
                'type'           => 'SCORE_UPDATE',
                'myScore'        => $me['score'],
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
        if (!$db) { $from->send($this->encode(['type'=>'leaderboard_data','data'=>[]])); return; }
        try {
            $stmt = $db->query("SELECT username,icon,total_score,avg_reaction_time,best_time,games_played,level
                                FROM players_stats ORDER BY total_score DESC LIMIT 10");
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>$stmt->fetchAll()]));
        } catch (\PDOException $e) {
            echo "[DB ERR] Leaderboard: {$e->getMessage()}\n";
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>[]]));
        }
    }

    // =========================================================
    //  PLAYER STATS — untuk dashboard (data per ronde dari DB)
    // =========================================================
    private function handleGetPlayerStats(ConnectionInterface $from, array $data): void {
        $username = trim($data['username'] ?? '');
        if (!$username) {
            $from->send($this->encode(['type'=>'player_stats_data','data'=>[],'error'=>'Username diperlukan']));
            return;
        }
        $db = getDB();
        if (!$db) {
            $from->send($this->encode(['type'=>'player_stats_data','data'=>[],'error'=>'DB tidak tersedia']));
            return;
        }
        try {
            // Ambil round_logs milik user ini, JOIN dengan players_stats untuk summary
            $stmt = $db->prepare("
                SELECT rl.reaction_time, rl.round_score, rl.created_at,
                       ps.total_score, ps.avg_reaction_time, ps.best_time,
                       ps.games_played, ps.level
                FROM round_logs rl
                LEFT JOIN players_stats ps ON ps.username = rl.username
                WHERE rl.username = :u
                ORDER BY rl.created_at ASC
                LIMIT 200
            ");
            $stmt->execute([':u' => $username]);
            $rows = $stmt->fetchAll();
            $from->send($this->encode(['type'=>'player_stats_data','username'=>$username,'data'=>$rows]));
            echo "[DB]     Kirim player_stats untuk {$username}: " . count($rows) . " baris\n";
        } catch (\PDOException $e) {
            echo "[DB ERR] PlayerStats: {$e->getMessage()}\n";
            $from->send($this->encode(['type'=>'player_stats_data','data'=>[],'error'=>$e->getMessage()]));
        }
    }

    // =========================================================
    //  DATABASE
    // =========================================================
    private function saveToDatabase(array $stats, array $roundLog): void {
        $db = getDB();
        if (!$db) { echo "[DB ERR] Skip — koneksi mati.\n"; return; }
        echo "\n[DB] === MULAI SAVE ===\n";
        try {
            foreach ($stats as $index => $p) {
                if ($p['isGuest']) { echo "[DB] SKIP: {$p['username']} (Guest)\n"; continue; }

                $xpGained = ($index === 0) ? 500 : 100;
                $stmtGet  = $db->prepare("SELECT total_xp FROM users WHERE username=:u");
                $stmtGet->execute([':u'=>$p['username']]);
                $row    = $stmtGet->fetch();
                $oldXp  = $row ? (int)$row['total_xp'] : 0;
                $newXp  = $oldXp + $xpGained;
                $newLvl = $this->calculateLevel($newXp);

                $db->prepare("UPDATE users SET games_played=games_played+1, level=:lvl, total_xp=:xp WHERE username=:u")
                   ->execute([':lvl'=>$newLvl,':xp'=>$newXp,':u'=>$p['username']]);

                if ($p['bestTime'] !== null) {
                    $db->prepare("UPDATE users SET best_time=IF(best_time IS NULL OR :b1<best_time,:b2,best_time) WHERE username=:u")
                       ->execute([':b1'=>$p['bestTime'],':b2'=>$p['bestTime'],':u'=>$p['username']]);
                }

                $db->prepare("
                    INSERT INTO players_stats (username,icon,total_score,avg_reaction_time,best_time,games_played,level)
                    VALUES (:u,:i,:s,:a,:b,1,:lvl)
                    ON DUPLICATE KEY UPDATE
                        icon              = VALUES(icon),
                        total_score       = total_score + VALUES(total_score),
                        avg_reaction_time = IF(avg_reaction_time IS NULL, VALUES(avg_reaction_time),
                                            ROUND((avg_reaction_time+VALUES(avg_reaction_time))/2,2)),
                        best_time         = IF(best_time IS NULL OR VALUES(best_time)<best_time, VALUES(best_time), best_time),
                        games_played      = games_played+1,
                        level             = VALUES(level)
                ")->execute([
                    ':u'=>$p['username'],':i'=>$p['icon'],':s'=>$p['score'],
                    ':a'=>$p['avgTime'],':b'=>$p['bestTime'],':lvl'=>$newLvl,
                ]);

                echo "[DB] OK: {$p['username']} XP={$newXp} Lv={$newLvl} Skor={$p['score']}\n";
            }

            if (!empty($roundLog)) {
                $stmtRound = $db->prepare(
                    "INSERT INTO round_logs (username, reaction_time, round_score, is_foul) VALUES (:u,:t,:s,0)"
                );
                foreach ($roundLog as $r) {
                    foreach ($stats as $p) {
                        if ($p['isGuest']) continue;
                        $stmtRound->execute([
                            ':u'=>$p['username'],
                            ':t'=>$p['avgTime'] ?? 0,
                            ':s'=>$r['scores'][$p['username']] ?? 0,
                        ]);
                    }
                }
                echo "[DB] Round logs: " . count($roundLog) . "\n";
            }
            echo "[DB] === SAVE SELESAI ===\n\n";
        } catch (\PDOException $e) {
            echo "\n[DB ERR] GAGAL SAVE: {$e->getMessage()}\n\n";
        }
    }

    private function calculateLevel(int $xp): int {
        $table = [
            [1,0],[2,200],[3,600],[4,1100],[5,1700],[6,2500],[7,3500],
            [8,4700],[9,6200],[10,8000],[11,10100],[12,12500],[13,15200],
            [14,18200],[15,21500],[16,25100],[17,29000],[18,33200],[19,37700],[20,45000]
        ];
        $level = 1;
        foreach ($table as $row) {
            if ($xp >= $row[1]) $level = $row[0]; else break;
        }
        return $level;
    }

    // =========================================================
    //  HELPERS
    // =========================================================
    /** Kirim ke SEMUA client (lobby global: chat, player list) */
    private function broadcastLobby(array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->clients as $c) { $c->send($json); }
    }

    /** Kirim HANYA ke 2 pemain dalam 1 room */
    private function broadcastRoom(string $roomId, array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->players as $p) {
            if (($p['roomId'] ?? null) === $roomId) $p['conn']->send($json);
        }
    }

    private function broadcastPlayerList(): void {
        $list = array_map(fn($p) => [
            'name'=>$p['username'],'icon'=>$p['icon'],'score'=>$p['score'],
            'isGuest'=>$p['isGuest'],'ready'=>$p['isReady'],'level'=>$p['level'] ?? 1,
        ], $this->players);
        $this->broadcastLobby(['type'=>'PLAYER_LIST','players'=>$list]);
    }

    private function getRoomPlayers(string $roomId): array {
        return array_values(array_filter($this->players, fn($p) => ($p['roomId'] ?? null) === $roomId));
    }

    private function getPlayerRoomId(ConnectionInterface $conn): ?string {
        foreach ($this->players as $p) {
            if ($p['conn'] === $conn) return $p['roomId'] ?? null;
        }
        return null;
    }

    private function findPlayerByUsername(string $username): ?array {
        foreach ($this->players as $p) {
            if ($p['username'] === $username) return $p;
        }
        return null;
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
        try { $db->prepare("INSERT INTO chat_messages (username,message) VALUES (:u,:m)")->execute([':u'=>$username,':m'=>$message]); }
        catch (\PDOException $e) { echo "[DB ERR] Chat: {$e->getMessage()}\n"; }
    }

    private function sendChatHistory(ConnectionInterface $conn): void {
        $db = getDB();
        if (!$db) return;
        try {
            $rows = array_reverse($db->query(
                "SELECT username,message,created_at FROM chat_messages ORDER BY created_at DESC LIMIT 20"
            )->fetchAll());
            foreach ($rows as $r) {
                $conn->send($this->encode([
                    'type'=>'CHAT_MESSAGE','username'=>$r['username'],
                    'message'=>$r['message'],'time'=>date('H:i',strtotime($r['created_at'])),
                ]));
            }
        } catch (\PDOException $e) {}
    }
}