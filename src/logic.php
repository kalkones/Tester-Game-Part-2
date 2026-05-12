<?php
// ============================================================
//  REACTION DUEL — logic.php (Authoritative Server) [REFACTORED]
//  Prinsip: Server = Sutradara + Hakim + Kalkulasi
//  Client hanya kirim: ITEM_CLICKED {itemId}
//  Server yang spawn, validasi, dan hitung skor
// ============================================================

require_once __DIR__ . '/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Logic implements MessageComponentInterface {

    // ── Konstanta game ────────────────────────────────────────
    const MAX_PLAYERS      = 2;
    const MAX_ROUNDS       = 5;
    const ROUND_DELAY_MIN  = 2000;  // ms delay sebelum spawn
    const ROUND_DELAY_MAX  = 4000;
    const LATENCY_GRACE_MS = 200;   // toleransi latency (200ms grace period)
    const PENALTY_SCORE    = 50;

    // Item config per ronde
    const ITEM_CONFIG = [
        1 => ['count'=>5,  'duration'=>3200, 'bomb_chance'=>0.20, 'bonus_chance'=>0.15],
        2 => ['count'=>7,  'duration'=>2800, 'bomb_chance'=>0.22, 'bonus_chance'=>0.15],
        3 => ['count'=>9,  'duration'=>2400, 'bomb_chance'=>0.25, 'bonus_chance'=>0.15],
        4 => ['count'=>11, 'duration'=>2000, 'bomb_chance'=>0.28, 'bonus_chance'=>0.13],
        5 => ['count'=>13, 'duration'=>1600, 'bomb_chance'=>0.30, 'bonus_chance'=>0.12],
    ];

    // ── State ─────────────────────────────────────────────────
    private \SplObjectStorage $clients;

    private array $players = [];
    /*
     * Format per player:
     * [
     *   'conn'        => ConnectionInterface,
     *   'username'    => string,
     *   'icon'        => string,
     *   'isGuest'     => bool,
     *   'score'       => int,
     *   'reactionLog' => float[],
     *   'combo'       => int,
     *   'penalties'   => int,
     *   'isReady'     => bool,
     *   'findingMatch'=> bool,
     * ]
     */

    // ── Room & Spectator State ────────────────────────────────
    // roomPlayers: max MAX_PLAYERS koneksi aktif dalam room
    // roomSpectators: koneksi overflow (penonton)
    private array $roomPlayers    = [];
    private array $roomSpectators = [];

    private bool   $gameStarted  = false;
    private int    $currentRound = 0;
    private array  $roundLog     = [];

    // ── Active Items: dikelola server ─────────────────────────
    private array  $activeItems  = [];

    private int $itemIdCounter = 0;

    // ── Constructor ───────────────────────────────────────────
    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[SERVER] Logic siap. Max " . self::MAX_PLAYERS . " pemain per room.\n";
    }

    // ── Ratchet Callbacks ─────────────────────────────────────
    public function onOpen(ConnectionInterface $conn): void {
        $this->clients->attach($conn);
        echo "[OPEN]   #" . spl_object_id($conn) . "\n";

        // Kirim status server saat koneksi baru masuk
        $conn->send($this->encode([
            'type'         => 'SERVER_HELLO',
            'message'      => 'Terhubung ke Reaction Duel Server.',
            'playersOnline'=> count($this->players),
            'roomSlots'    => self::MAX_PLAYERS - count($this->roomPlayers),
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) return;

        echo "[MSG]    #" . spl_object_id($from) . " → {$data['type']}\n";

        switch ($data['type']) {

            // ── Auth (mendukung kedua alias) ───────────────────
            case 'LOGIN':
            case 'AUTH_LOGIN':    $this->handleAuthLogin($from, $data);    break;

            case 'REGISTER':
            case 'AUTH_REGISTER': $this->handleAuthRegister($from, $data); break;

            // ── Lobby ──────────────────────────────────────────
            case 'JOIN':          $this->handleJoin($from, $data);         break;
            case 'CHAT':          $this->handleChat($from, $data);         break;
            case 'UPDATE_ICON':   $this->handleUpdateIcon($from, $data);   break;

            // ── Matchmaking ────────────────────────────────────
            case 'FIND_MATCH':    $this->handleFindMatch($from, $data);    break;
            case 'PLAYER_READY':  $this->handlePlayerReady($from, $data);  break;

            // ── GAME: client hanya kirim itemId ───────────────
            case 'ITEM_CLICKED':  $this->handleItemClicked($from, $data);  break;

            // ── Data / Analytics ───────────────────────────────
            case 'get_leaderboard':     $this->handleGetLeaderboard($from);     break;
            case 'get_dashboard_stats': $this->handleGetDashboardStats($from);  break;

            default:
                echo "[WARN]   Unknown type: {$data['type']}\n";
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? '#' . spl_object_id($conn);

        // Bersihkan room & spectator sebelum hapus player
        $this->removeFromRoom($conn);
        $this->removePlayer($conn);

        echo "[CLOSE]  {$username}\n";

        if ($this->gameStarted) {
            $this->cancelAllItemTimers();
            $this->resetGame();
            $this->broadcastAll(['type' => 'SYSTEM', 'message' => "{$username} keluar. Game dibatalkan."]);
        }

        // Broadcast daftar pemain terbaru ke semua koneksi
        $this->broadcastPlayerList();
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[ERROR]  #" . spl_object_id($conn) . ": {$e->getMessage()}\n";
        $conn->close();
    }

    // =========================================================
    //  AUTH HANDLERS
    //  LOGIN/REGISTER: EXP & Level SELALU dari DB, tidak percaya client
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

            // ── Sinkron EXP & Level dari DB (Authoritative) ───
            $statsStmt = $db->prepare("SELECT total_score, avg_reaction_time, best_time, games_played, level FROM players_stats WHERE username = :u LIMIT 1");
            $statsStmt->execute([':u' => $user['username']]);
            $stats = $statsStmt->fetch();

            // Hitung level dari games_played agar konsisten
            $gamesPlayed  = (int)($stats['games_played'] ?? $user['games_played'] ?? 0);
            $authoritative_level = max(1, (int)floor($gamesPlayed / 5) + 1);
            $authoritative_xp    = (int)($stats['total_score'] ?? 0);

            // Update level di users table jika ada perubahan
            $db->prepare("UPDATE users SET level = :l WHERE username = :u")
               ->execute([':l' => $authoritative_level, ':u' => $user['username']]);

            echo "[AUTH]   Login berhasil: {$user['username']} | Level: {$authoritative_level} | XP: {$authoritative_xp}\n";

            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>true,'user'=>[
                'username'   => $user['username'],
                'email'      => $user['email'],
                'icon'       => $user['icon'] ?? 'fa-user',
                'level'      => $authoritative_level,
                'totalXP'    => $authoritative_xp,
                'gamesPlayed'=> $gamesPlayed,
                'bestTime'   => $stats['best_time'] ?? $user['best_time'] ?? null,
                'avgReaction'=> $stats['avg_reaction_time'] ?? null,
                'type'       => 'registered',
            ]]));

            // ── Load chat history segera setelah login berhasil ─
            $this->sendChatHistory($conn);

        } catch (\PDOException $e) {
            echo "[DB ERR] Login: {$e->getMessage()}\n";
            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'Error server.']));
        }
    }

    private function handleAuthRegister(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email']    ?? '');
        $pass     = $data['password']       ?? '';
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
            $db->prepare("INSERT INTO users (username, email, password_hash, level, total_xp, games_played) VALUES (:u, :e, :p, 1, 0, 0)")
               ->execute([':u'=>$username, ':e'=>$email, ':p'=>$hash]);

            echo "[AUTH]   Register berhasil: {$username}\n";

            $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>true,'user'=>[
                'username'   => $username,
                'email'      => $email,
                'icon'       => 'fa-user',
                'level'      => 1,
                'totalXP'    => 0,
                'gamesPlayed'=> 0,
                'bestTime'   => null,
                'type'       => 'registered',
            ]]));

            // ── Load chat history setelah register berhasil ───
            $this->sendChatHistory($conn);

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
            'conn'        => $conn,
            'username'    => $username,
            'icon'        => $icon,
            'isGuest'     => $isGuest,
            'score'       => 0,
            'reactionLog' => [],
            'combo'       => 0,
            'penalties'   => 0,
            'isReady'     => false,
            'findingMatch'=> false,
        ];

        echo "[JOIN]   {$username} bergabung. Total online: " . count($this->players) . "\n";

        // ── Broadcast PLAYER_LIST ke semua saat ada pemain baru ─
        $this->broadcastPlayerList();

        // ── Load chat history saat JOIN (untuk guest tanpa login) ─
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
        $this->broadcastPlayerList();
    }

    // =========================================================
    //  MATCHMAKING — Room & Spectator System
    // =========================================================
    /**
     * Alur matchmaking baru:
     *  1. Cek apakah koneksi sudah ada di roomPlayers atau roomSpectators
     *  2. Jika roomPlayers < MAX_PLAYERS → tambah ke roomPlayers
     *  3. Jika roomPlayers penuh → tambah ke roomSpectators, kirim SPECTATOR_MODE
     *  4. Jika roomPlayers sudah penuh setelah join → MATCH_FOUND ke kedua pemain
     */
    private function handleFindMatch(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';

        // ── Cegah duplikat ────────────────────────────────────
        if (in_array($conn, $this->roomPlayers, true) || in_array($conn, $this->roomSpectators, true)) {
            $conn->send($this->encode(['type'=>'ERROR','message'=>'Kamu sudah dalam antrian.']));
            return;
        }

        // ── Tandai player sebagai sedang mencari ──────────────
        foreach ($this->players as &$p) {
            if ($p['conn'] === $conn) { $p['findingMatch'] = true; break; }
        }
        unset($p);

        // ── Penempatan room vs spectator ──────────────────────
        if (count($this->roomPlayers) < self::MAX_PLAYERS) {
            $this->roomPlayers[] = $conn;
            $slotInfo = count($this->roomPlayers) . '/' . self::MAX_PLAYERS;
            echo "[ROOM]   {$username} masuk sebagai PEMAIN. Slot: {$slotInfo}\n";
            $conn->send($this->encode(['type'=>'MATCH_SEARCHING','message'=>'Mencari lawan... menunggu pemain lain.']));
        } else {
            // Room penuh — masuk sebagai penonton
            $this->roomSpectators[] = $conn;
            echo "[ROOM]   {$username} masuk sebagai PENONTON. Spectators: " . count($this->roomSpectators) . "\n";
            $conn->send($this->encode([
                'type'       => 'SPECTATOR_MODE',
                'message'    => 'Room penuh. Kamu akan menonton pertandingan ini.',
                'spectators' => count($this->roomSpectators),
            ]));
            return; // Penonton tidak trigger match
        }

        // ── Cek apakah room sudah penuh untuk memulai match ───
        if (count($this->roomPlayers) >= self::MAX_PLAYERS) {
            $matchedConns   = $this->roomPlayers;
            $matchedPlayers = [];

            foreach ($this->players as &$p) {
                if (in_array($p['conn'], $matchedConns, true)) {
                    $p['findingMatch'] = false;
                    $matchedPlayers[]  = $p;
                }
            }
            unset($p);

            // Kirim MATCH_FOUND ke masing-masing pemain
            foreach ($matchedPlayers as $mp) {
                $opp = array_values(
                    array_filter($matchedPlayers, fn($x) => $x['username'] !== $mp['username'])
                )[0] ?? null;

                $mp['conn']->send($this->encode([
                    'type'         => 'MATCH_FOUND',
                    'opponent'     => $opp['username'] ?? '?',
                    'opponentIcon' => $opp['icon']     ?? 'fa-robot',
                    'spectators'   => count($this->roomSpectators),
                ]));
            }

            if (isset($matchedPlayers[0], $matchedPlayers[1])) {
                echo "[MATCH]  {$matchedPlayers[0]['username']} vs {$matchedPlayers[1]['username']} | Penonton: " . count($this->roomSpectators) . "\n";
            }
        }
    }

    private function handlePlayerReady(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) { $p['isReady'] = true; break; }
        }
        unset($p);

        $this->broadcastAll(['type'=>'PLAYER_READY_UPDATE','username'=>$username,'isReady'=>true]);
        echo "[READY]  {$username}\n";

        $readyCount = count(array_filter($this->players, fn($p) => $p['isReady']));
        if ($readyCount >= self::MAX_PLAYERS && !$this->gameStarted) {
            $this->scheduleCall(1000, fn() => $this->startGame());
        }
    }

    // =========================================================
    //  ITEM CLICKED — The Heart of Anti-Cheat
    // =========================================================
    private function handleItemClicked(ConnectionInterface $from, array $data): void {
        if (!$this->gameStarted) return;

        $username  = $data['username']  ?? '?';
        $itemId    = $data['itemId']    ?? '';
        $clickTime = (float)($data['clickTime'] ?? 0);

        if (!isset($this->activeItems[$itemId])) {
            echo "[IGNORE] Item {$itemId} tidak ada (expired/sudah diklik)\n";
            return;
        }

        $item        = $this->activeItems[$itemId];
        $serverNowMs = round(microtime(true) * 1000, 2);
        $elapsedMs   = $serverNowMs - $item['spawnedAt'];
        $maxAllowed  = $item['duration'] + self::LATENCY_GRACE_MS;

        if ($elapsedMs > $maxAllowed) {
            echo "[LATE]   {$username} klik {$itemId} terlambat ({$elapsedMs}ms > {$maxAllowed}ms)\n";
            return;
        }

        unset($this->activeItems[$itemId]);

        $reactionMs = round($elapsedMs, 2);
        echo "[CLICK]  {$username} klik {$itemId} ({$item['type']}) @ {$reactionMs}ms\n";

        foreach ($this->players as &$p) {
            if ($p['username'] !== $username) continue;

            if ($item['type'] === 'bad') {
                $p['score']     = max(0, $p['score'] - self::PENALTY_SCORE);
                $p['combo']     = 0;
                $p['penalties']++;
            } elseif ($item['type'] === 'bonus') {
                $p['score']    += 250;
                $p['combo']++;
                $p['reactionLog'][] = $reactionMs;
            } else {
                $comboBonus     = min($p['combo'], 10) * 10;
                $p['score']    += (100 + $comboBonus);
                $p['combo']++;
                $p['reactionLog'][] = $reactionMs;
            }
            break;
        }
        unset($p);

        $this->broadcastScoreUpdate();
        $this->checkRoundEnd();
    }

    // =========================================================
    //  GAME FLOW
    // =========================================================
    private function startGame(): void {
        $this->gameStarted   = true;
        $this->currentRound  = 0;
        $this->roundLog      = [];
        $this->activeItems   = [];
        $this->itemIdCounter = 0;

        foreach ($this->players as &$p) {
            $p['score']=0; $p['reactionLog']=[]; $p['combo']=0;
            $p['penalties']=0; $p['isReady']=false;
        }
        unset($p);

        echo "[GAME]   Dimulai!\n";
        $this->broadcastAll(['type' => 'START_GAME']);
        $this->scheduleCall(1500, fn() => $this->startRound());
    }

    private function startRound(): void {
        $this->currentRound++;
        if ($this->currentRound > self::MAX_ROUNDS) { $this->endGame(); return; }

        $this->activeItems = [];

        echo "[ROUND]  Ronde {$this->currentRound}\n";
        $this->broadcastAll(['type'=>'ROUND_UPDATE','round'=>$this->currentRound]);
        $this->broadcastAll(['type'=>'WAIT']);

        $delay = rand(self::ROUND_DELAY_MIN, self::ROUND_DELAY_MAX);
        $this->scheduleCall($delay, fn() => $this->spawnItems());
    }

    private function spawnItems(): void {
        $round       = $this->currentRound;
        $cfg         = self::ITEM_CONFIG[$round] ?? self::ITEM_CONFIG[5];
        $count       = $cfg['count'];
        $duration    = $cfg['duration'];
        $bombChance  = $cfg['bomb_chance'];
        $bonusChance = $cfg['bonus_chance'];

        $items     = [];
        $spawnedAt = round(microtime(true) * 1000, 2);

        for ($i = 0; $i < $count; $i++) {
            $this->itemIdCounter++;
            $id   = "item_{$this->currentRound}_{$this->itemIdCounter}";
            $rand = mt_rand() / mt_getrandmax();

            if ($rand < $bombChance) {
                $type    = 'bad';
                $itemDur = $duration + 500;
            } elseif ($rand > (1 - $bonusChance)) {
                $type    = 'bonus';
                $itemDur = 1200;
            } else {
                $type    = 'good';
                $itemDur = $duration + mt_rand(-200, 200);
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

            $this->activeItems[$id] = $itemData;

            $items[] = [
                'id'       => $id,
                'type'     => $type,
                'top'      => $itemData['top'],
                'left'     => $itemData['left'],
                'duration' => $itemDur,
            ];

            $this->scheduleItemExpiry($id, $itemDur);
        }

        echo "[SPAWN]  Ronde {$round}: {$count} item\n";
        $this->broadcastAll(['type'=>'SPAWN_ITEMS','items'=>$items,'round'=>$round]);
    }

    private function scheduleItemExpiry(string $itemId, int $durationMs): void {
        $this->scheduleCall($durationMs, function () use ($itemId) {
            if (!isset($this->activeItems[$itemId])) return;

            $item = $this->activeItems[$itemId];
            unset($this->activeItems[$itemId]);

            echo "[EXPIRY] Item {$itemId} expired.\n";

            $resetCombo = ($item['type'] === 'good');
            $this->broadcastAll(['type'=>'ITEM_EXPIRED','itemId'=>$itemId,'resetCombo'=>$resetCombo]);

            if ($resetCombo) {
                foreach ($this->players as &$p) { $p['combo'] = 0; }
                unset($p);
            }

            $this->checkRoundEnd();
        });
    }

    private function checkRoundEnd(): void {
        if (!empty($this->activeItems)) return;

        echo "[ROUND]  Ronde {$this->currentRound} selesai.\n";

        $scores = [];
        foreach ($this->players as $p) {
            $scores[$p['username']] = $p['score'];
        }
        arsort($scores);
        $roundWinner = array_key_first($scores);
        $this->broadcastAll(['type'=>'ROUND_RESULT','roundWinner'=>$roundWinner,'scores'=>$scores]);

        $this->roundLog[] = ['round'=>$this->currentRound,'winner'=>$roundWinner,'scores'=>$scores];

        if ($this->currentRound >= self::MAX_ROUNDS) {
            $this->scheduleCall(2500, fn() => $this->endGame());
        } else {
            $this->scheduleCall(2500, fn() => $this->startRound());
        }
    }

    private function endGame(): void {
        $this->gameStarted = false;
        $this->cancelAllItemTimers();
        echo "[GAME]   Selesai!\n";

        $statsPerPlayer = [];
        foreach ($this->players as $p) {
            $log  = $p['reactionLog'];
            $avg  = count($log) ? round(array_sum($log) / count($log), 2) : null;
            $best = count($log) ? round(min($log), 2) : null;
            $cons = count($log) > 1 ? round(max($log) - min($log), 2) : null;

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

        usort($statsPerPlayer, fn($a, $b) => $b['score'] <=> $a['score']);

        $this->broadcastAll(['type'=>'GAME_OVER','stats'=>$statsPerPlayer]);
        echo "[STATS]  " . json_encode($statsPerPlayer, JSON_PRETTY_PRINT) . "\n";

        $this->saveToDatabase($statsPerPlayer);
        $this->scheduleCall(5000, fn() => $this->resetGame());
    }

    private function resetGame(): void {
        $this->gameStarted   = false;
        $this->currentRound  = 0;
        $this->activeItems   = [];
        $this->roundLog      = [];
        $this->itemIdCounter = 0;

        // ── Kosongkan room agar matchmaking bisa dimulai lagi ─
        $this->roomPlayers    = [];
        $this->roomSpectators = [];

        foreach ($this->players as &$p) {
            $p['score']=0; $p['reactionLog']=[]; $p['combo']=0;
            $p['penalties']=0; $p['isReady']=false; $p['findingMatch']=false;
        }
        unset($p);

        echo "[RESET]  Sesi direset. Room & spectators dikosongkan.\n";
        $this->broadcastPlayerList();
    }

    private function cancelAllItemTimers(): void {
        $this->activeItems = [];
    }

    // =========================================================
    //  BROADCAST SCORE UPDATE
    // =========================================================
    private function broadcastScoreUpdate(): void {
        foreach ($this->players as $me) {
            $myAvg  = count($me['reactionLog']) ? round(array_sum($me['reactionLog']) / count($me['reactionLog']), 0) : null;
            $myBest = count($me['reactionLog']) ? round(min($me['reactionLog']), 0) : null;

            $oppScore = 0;
            foreach ($this->players as $opp) {
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
        if (!$db) {
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>[]]));
            return;
        }
        try {
            $stmt = $db->query("
                SELECT username, icon, total_score, avg_reaction_time,
                       best_time, games_played, level
                FROM players_stats
                ORDER BY total_score DESC
                LIMIT 10
            ");
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>$stmt->fetchAll(\PDO::FETCH_ASSOC)]));
        } catch (\PDOException $e) {
            echo "[DB ERR] Leaderboard: {$e->getMessage()}\n";
            $from->send($this->encode(['type'=>'leaderboard_data','data'=>[]]));
        }
    }

    // =========================================================
    //  DASHBOARD STATS — Aggregat untuk halaman analytics
    // =========================================================
    private function handleGetDashboardStats(ConnectionInterface $from): void {
        $db = getDB();
        if (!$db) {
            $from->send($this->encode([
                'type'          => 'dashboard_stats',
                'totalSessions' => 0, 'totalRounds' => 0,
                'avgReaction'   => null, 'bestReaction' => null,
                'trend'         => [], 'dist' => [],
                'consistency'   => [], 'recentActivity' => [],
                'insight'       => 'Koneksi DB tidak tersedia.',
            ]));
            return;
        }
        try {
            // ── Summary ──────────────────────────────────────
            $summary = $db->query("
                SELECT
                    SUM(games_played)        AS total_sessions,
                    AVG(avg_reaction_time)   AS avg_reaction,
                    MIN(best_time)           AS best_reaction
                FROM players_stats
            ")->fetch(\PDO::FETCH_ASSOC);

            $totalRounds = (int)$db->query("SELECT COUNT(*) FROM round_logs")->fetchColumn();

            // ── Trend: 50 reaction time terakhir per username ─
            $trendRows = $db->query("
                SELECT username, reaction_time
                FROM round_logs
                WHERE reaction_time > 0
                ORDER BY created_at DESC
                LIMIT 50
            ")->fetchAll(\PDO::FETCH_ASSOC);

            // ── Distribusi reaction time (bucket 50ms) ────────
            $distRows = $db->query("
                SELECT FLOOR(reaction_time / 50) * 50 AS bucket, COUNT(*) AS cnt
                FROM round_logs
                WHERE reaction_time > 0
                GROUP BY bucket
                ORDER BY bucket
                LIMIT 20
            ")->fetchAll(\PDO::FETCH_ASSOC);

            // ── Konsistensi per pemain ────────────────────────
            $consRows = $db->query("
                SELECT
                    username,
                    ROUND(AVG(reaction_time), 1)                    AS avg_t,
                    ROUND(MAX(reaction_time) - MIN(reaction_time), 1) AS spread
                FROM round_logs
                WHERE reaction_time > 0
                GROUP BY username
                ORDER BY avg_t ASC
                LIMIT 10
            ")->fetchAll(\PDO::FETCH_ASSOC);

            // ── Aktivitas terkini ─────────────────────────────
            $activityRows = $db->query("
                SELECT username, reaction_time, round_score, created_at
                FROM round_logs
                ORDER BY created_at DESC
                LIMIT 15
            ")->fetchAll(\PDO::FETCH_ASSOC);

            // ── Insight otomatis dari data ────────────────────
            $insight    = 'Mulai bermain untuk melihat analisis peningkatan skill!';
            $topPlayer  = $db->query("
                SELECT username, avg_reaction_time, games_played
                FROM players_stats
                ORDER BY total_score DESC
                LIMIT 1
            ")->fetch(\PDO::FETCH_ASSOC);

            if ($topPlayer) {
                $avgMs   = round((float)($topPlayer['avg_reaction_time'] ?? 999), 0);
                $games   = (int)$topPlayer['games_played'];
                $insight = "Pemain teratas: <strong>{$topPlayer['username']}</strong> "
                         . "— rata-rata reaksi {$avgMs}ms dalam {$games} game. "
                         . ($avgMs < 250 ? "Performa luar biasa! 🔥" : "Terus latihan untuk mempertajam reaksimu!");
            }

            $from->send($this->encode([
                'type'          => 'dashboard_stats',
                'totalSessions' => (int)($summary['total_sessions'] ?? 0),
                'totalRounds'   => $totalRounds,
                'avgReaction'   => $summary['avg_reaction']  ? round((float)$summary['avg_reaction'],  1) : null,
                'bestReaction'  => $summary['best_reaction'] ? round((float)$summary['best_reaction'], 2) : null,
                'trend'         => array_values($trendRows),
                'dist'          => array_values($distRows),
                'consistency'   => array_values($consRows),
                'recentActivity'=> array_values($activityRows),
                'insight'       => $insight,
            ]));

        } catch (\PDOException $e) {
            echo "[DB ERR] DashboardStats: {$e->getMessage()}\n";
            $from->send($this->encode(['type'=>'dashboard_stats','error'=>$e->getMessage()]));
        }
    }

    // =========================================================
    //  DATABASE
    // =========================================================
    private function saveToDatabase(array $stats): void {
        $db = getDB();
        if (!$db) { echo "[DB ERR] Skip save.\n"; return; }
        try {
            $stmtStats = $db->prepare("
                INSERT INTO players_stats (username, icon, total_score, avg_reaction_time, best_time, games_played, level)
                VALUES (:u, :i, :s, :a, :b, 1, 1)
                ON DUPLICATE KEY UPDATE
                    icon              = VALUES(icon),
                    total_score       = total_score + VALUES(total_score),
                    avg_reaction_time = IF(avg_reaction_time IS NULL, VALUES(avg_reaction_time),
                                          ROUND((avg_reaction_time + VALUES(avg_reaction_time)) / 2, 2)),
                    best_time         = IF(best_time IS NULL OR VALUES(best_time) < best_time, VALUES(best_time), best_time),
                    games_played      = games_played + 1,
                    level             = GREATEST(1, FLOOR((games_played + 1) / 5) + 1)
            ");

            foreach ($stats as $p) {
                if ($p['isGuest']) continue;

                $stmtStats->execute([
                    ':u' => $p['username'],
                    ':i' => $p['icon'],
                    ':s' => $p['score'],
                    ':a' => $p['avgTime'],
                    ':b' => $p['bestTime'],
                ]);

                // Sync users table: best_time, games_played, level (Authoritative)
                $db->prepare("
                    UPDATE users
                    SET best_time    = IF(best_time IS NULL OR :b < best_time, :b, best_time),
                        games_played = games_played + 1,
                        total_xp     = total_xp + :s,
                        level        = GREATEST(1, FLOOR((games_played + 1) / 5) + 1)
                    WHERE username = :u
                ")->execute([':b'=>$p['bestTime'], ':s'=>$p['score'], ':u'=>$p['username']]);

                echo "[DB]     {$p['username']} | Skor: {$p['score']} | Best: {$p['bestTime']}ms\n";
            }

            // Round logs
            if (!empty($this->roundLog)) {
                $stmtRound = $db->prepare("INSERT INTO round_logs (username, reaction_time, round_score, is_foul) VALUES (:u, :t, :s, 0)");
                foreach ($this->roundLog as $r) {
                    foreach ($stats as $p) {
                        $stmtRound->execute([
                            ':u' => $p['username'],
                            ':t' => $p['avgTime'] ?? 0,
                            ':s' => $r['scores'][$p['username']] ?? 0,
                        ]);
                    }
                }
                echo "[DB]     Round logs disimpan: " . count($this->roundLog) . " ronde\n";
            }
        } catch (\PDOException $e) {
            echo "[DB ERR] saveToDatabase: {$e->getMessage()}\n";
        }
    }

    // =========================================================
    //  HELPERS
    // =========================================================
    private function broadcastAll(array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->clients as $c) { $c->send($json); }
    }

    /**
     * Broadcast PLAYER_LIST ke semua koneksi.
     * Dipanggil setiap kali ada pemain join / close / update icon.
     */
    private function broadcastPlayerList(): void {
        $list = array_map(fn($p) => [
            'name'        => $p['username'],
            'icon'        => $p['icon'],
            'score'       => $p['score'],
            'isGuest'     => $p['isGuest'],
            'ready'       => $p['isReady'],
            'findingMatch'=> $p['findingMatch'],
            'isInRoom'    => in_array($p['conn'], $this->roomPlayers, true),
            'isSpectator' => in_array($p['conn'], $this->roomSpectators, true),
            'level'       => 1, // bisa ditambah query DB jika perlu
        ], $this->players);

        $this->broadcastAll([
            'type'       => 'PLAYER_LIST',
            'players'    => $list,
            'totalOnline'=> count($this->players),
            'roomSlots'  => self::MAX_PLAYERS - count($this->roomPlayers),
        ]);
    }

    /**
     * Hapus koneksi dari roomPlayers atau roomSpectators
     * (dipanggil sebelum removePlayer saat onClose)
     */
    private function removeFromRoom(ConnectionInterface $conn): void {
        $this->roomPlayers    = array_values(array_filter($this->roomPlayers,    fn($c) => $c !== $conn));
        $this->roomSpectators = array_values(array_filter($this->roomSpectators, fn($c) => $c !== $conn));
    }

    private function removePlayer(ConnectionInterface $conn): void {
        $this->players = array_values(array_filter($this->players, fn($p) => $p['conn'] !== $conn));
    }

    private function getUsernameByConn(ConnectionInterface $conn): ?string {
        foreach ($this->players as $p) { if ($p['conn'] === $conn) return $p['username']; }
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
            $db->prepare("INSERT INTO chat_messages (username, message) VALUES (:u, :m)")
               ->execute([':u' => $username, ':m' => $message]);
        } catch (\PDOException $e) {
            echo "[DB ERR] Chat: {$e->getMessage()}\n";
        }
    }

    private function sendChatHistory(ConnectionInterface $conn): void {
        $db = getDB();
        if (!$db) return;
        try {
            $rows = array_reverse(
                $db->query("SELECT username, message, created_at FROM chat_messages ORDER BY created_at DESC LIMIT 20")
                   ->fetchAll(\PDO::FETCH_ASSOC)
            );
            foreach ($rows as $r) {
                $conn->send($this->encode([
                    'type'     => 'CHAT_MESSAGE',
                    'username' => $r['username'],
                    'message'  => $r['message'],
                    'time'     => date('H:i', strtotime($r['created_at'])),
                    'isHistory'=> true,
                ]));
            }
        } catch (\PDOException $e) {
            echo "[DB ERR] sendChatHistory: {$e->getMessage()}\n";
        }
    }
}