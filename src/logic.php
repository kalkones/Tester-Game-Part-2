<?php
// ============================================================
//  REACTION DUEL — logic.php (Authoritative Server)
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

    private bool   $gameStarted  = false;
    private int    $currentRound = 0;
    private array  $roundLog     = [];

    // ── Active Items: dikelola server ─────────────────────────
    // key = itemId (string), value = item data
    private array  $activeItems  = [];
    /*
     * Format per item:
     * [
     *   'id'       => string,
     *   'type'     => 'good'|'bad'|'bonus',
     *   'top'      => float,
     *   'left'     => float,
     *   'duration' => int (ms),
     *   'spawnedAt'=> float (microtime*1000),
     *   'round'    => int,
     * ]
     */

    private int $itemIdCounter = 0;

    // ── Constructor ───────────────────────────────────────────
    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[SERVER] Logic siap. Max " . self::MAX_PLAYERS . " pemain.\n";
    }

    // ── Ratchet Callbacks ─────────────────────────────────────
    public function onOpen(ConnectionInterface $conn): void {
        $this->clients->attach($conn);
        echo "[OPEN]   #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $data = json_decode($msg, true);
        if (!$data || !isset($data['type'])) return;

        echo "[MSG]    #{$from->resourceId} → {$data['type']}\n";

        switch ($data['type']) {

            // Auth
            case 'AUTH_LOGIN':    $this->handleAuthLogin($from, $data);    break;
            case 'AUTH_REGISTER': $this->handleAuthRegister($from, $data); break;

            // Lobby
            case 'JOIN':          $this->handleJoin($from, $data);         break;
            case 'CHAT':          $this->handleChat($from, $data);         break;
            case 'UPDATE_ICON':   $this->handleUpdateIcon($from, $data);   break;

            // Matchmaking
            case 'FIND_MATCH':    $this->handleFindMatch($from, $data);    break;
            case 'PLAYER_READY':  $this->handlePlayerReady($from, $data);  break;

            // ── GAME: client hanya kirim itemId ───────────────
            case 'ITEM_CLICKED':  $this->handleItemClicked($from, $data);  break;

            // Leaderboard
            case 'get_leaderboard': $this->handleGetLeaderboard($from);    break;

            default:
                echo "[WARN]   Unknown type: {$data['type']}\n";
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? "#{$conn->resourceId}";
        $this->removePlayer($conn);
        echo "[CLOSE]  {$username}\n";

        if ($this->gameStarted) {
            $this->cancelAllItemTimers();
            $this->resetGame();
            $this->broadcastAll(['type' => 'SYSTEM', 'message' => "{$username} keluar. Game dibatalkan."]);
        }
        $this->broadcastPlayerList();
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
        if (!$db) { $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'DB tidak tersedia.'])); return; }
      try {
            // Perhatikan: Ada 2 parameter unik (:id_user dan :id_email)
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :id_user OR email = :id_email LIMIT 1");
            
            // Perhatikan: Array memiliki 2 kunci yang cocok persis dengan yang di atas
            $stmt->execute([
                ':id_user'  => $id, 
                ':id_email' => $id
            ]);
            
            $user = $stmt->fetch();

            if (!$user || !password_verify($pass, $user['password_hash'])) {
                $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>false,'message'=>'Username atau password salah.']));
                return;
            }

            echo "[AUTH]   Login berhasil: {$user['username']}\n";
            $conn->send($this->encode(['type'=>'AUTH_RESULT','success'=>true,'user'=>[
                'username'=>$user['username'],
                'email'=>$user['email'],
                'icon'=>$user['icon'],
                'level'=>$user['level'],
                'totalXP'=>$user['total_xp']??0,
                'gamesPlayed'=>$user['games_played'],
                'bestTime'=>$user['best_time'],
                'type'=>'registered',
            ]]));

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
        if (!$db) { $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'DB tidak tersedia.'])); return; }
        try {
            $stmt = $db->prepare("SELECT id FROM users WHERE username=:u OR (email!='' AND email=:e) LIMIT 1");
            $stmt->execute([':u'=>$username,':e'=>$email]);
            if ($stmt->fetch()) {
                $conn->send($this->encode(['type'=>'REGISTER_RESULT','success'=>false,'message'=>'Username/email sudah dipakai.']));
                return;
            }
            $hash = password_hash($pass, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO users (username,email,password_hash) VALUES (:u,:e,:p)")->execute([':u'=>$username,':e'=>$email,':p'=>$hash]);
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
    //  LOBBY HANDLERS
    // =========================================================
    private function handleJoin(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        $icon     = $data['icon']          ?? 'fa-user';
        $isGuest  = $data['isGuest']        ?? false;
        $level    = $data['level']          ?? 1;

        if (!$username) return;

        foreach ($this->players as $p) {
            if (strtolower($p['username']) === strtolower($username)) {
                $conn->send($this->encode(['type'=>'ERROR','message'=>"Nama '{$username}' sudah dipakai."]));
                return;
            }
        }

        $this->players[] = [
            'conn'=>$conn,'username'=>$username,'icon'=>$icon,'isGuest'=>$isGuest,
            'level'=>$level,
            'score'=>0,'reactionLog'=>[],'combo'=>0,'penalties'=>0,'isReady'=>false,'findingMatch'=>false,
        ];

        echo "[JOIN]   {$username} bergabung. Total: " . count($this->players) . "\n";
        $this->broadcastPlayerList();
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
        // Update di DB
        $db = getDB();
        if ($db) {
            try { $db->prepare("UPDATE users SET icon=:i WHERE username=:u")->execute([':i'=>$icon,':u'=>$username]); } catch (\PDOException $e) {}
        }
    }

    // =========================================================
    //  MATCHMAKING
    // =========================================================
    private function handleFindMatch(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';
        foreach ($this->players as &$p) {
            if ($p['conn'] === $conn) { $p['findingMatch'] = true; break; }
        }
        unset($p);

        $searching = array_values(array_filter($this->players, fn($p) => $p['findingMatch']));
        echo "[MATCH]  {$username} mencari. Total: " . count($searching) . "\n";

        if (count($searching) >= self::MAX_PLAYERS) {
            $matched = array_slice($searching, 0, 2);
            foreach ($this->players as &$p) { $p['findingMatch'] = false; }
            unset($p);

            foreach ($matched as $mp) {
                $opp = array_values(array_filter($matched, fn($x) => $x['username'] !== $mp['username']))[0] ?? null;
                $mp['conn']->send($this->encode([
                    'type'=>'MATCH_FOUND','opponent'=>$opp?->username??'?','opponentIcon'=>$opp?->icon??'fa-robot'
                ]));
            }
            echo "[MATCH]  {$matched[0]['username']} vs {$matched[1]['username']}\n";
        } else {
            $conn->send($this->encode(['type'=>'MATCH_SEARCHING','message'=>'Mencari lawan...']));
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
        $clickTime = (float)($data['clickTime'] ?? 0); // timestamp dari client (ms)

        // ── Validasi 1: Apakah item ada di state server? ──────
        if (!isset($this->activeItems[$itemId])) {
            echo "[IGNORE] Item {$itemId} tidak ada (expired/sudah diklik)\n";
            return;
        }

        $item = $this->activeItems[$itemId];

        // ── Validasi 2: Apakah item sudah expired? ────────────
        $serverNowMs = round(microtime(true) * 1000, 2);
        $elapsedMs   = $serverNowMs - $item['spawnedAt'];
        $maxAllowed  = $item['duration'] + self::LATENCY_GRACE_MS; // Grace period 200ms

        if ($elapsedMs > $maxAllowed) {
            echo "[LATE]   {$username} klik {$itemId} terlambat ({$elapsedMs}ms > {$maxAllowed}ms)\n";
            // Klik ditolak karena benar-benar terlalu telat (melebihi grace period)
            return;
        }

        // ── Validasi 3: Hapus item agar tidak bisa diklik 2x ──
        unset($this->activeItems[$itemId]);

        // ── Kalkulasi Reaction Time (dari server, bukan client) ──
        $reactionMs = round($elapsedMs, 2);

        echo "[CLICK]  {$username} klik {$itemId} ({$item['type']}) @ {$reactionMs}ms\n";

        // ── Hitung Skor & Update State ─────────────────────────
        foreach ($this->players as &$p) {
            if ($p['username'] !== $username) continue;

            if ($item['type'] === 'bad') {
                // Bomb: kurangi skor, reset combo
                $p['score']    = max(0, $p['score'] - self::PENALTY_SCORE);
                $p['combo']    = 0;
                $p['penalties']++;
            } elseif ($item['type'] === 'bonus') {
                // Bonus: +250, naik combo
                $p['score'] += 250;
                $p['combo']++;
                $p['reactionLog'][] = $reactionMs;
            } else {
                // Good: skor base + combo bonus, reaction time dicatat
                $comboBonus = min($p['combo'], 10) * 10;
                $p['score'] += (100 + $comboBonus);
                $p['combo']++;
                $p['reactionLog'][] = $reactionMs;
            }
            break;
        }
        unset($p);

        // ── Broadcast SCORE_UPDATE ke semua ───────────────────
        $this->broadcastScoreUpdate();

        // ── Cek apakah semua item habis → akhiri ronde ────────
        $this->checkRoundEnd();
    }

    // =========================================================
    //  GAME FLOW
    // =========================================================
    private function startGame(): void {
        $this->gameStarted  = true;
        $this->currentRound = 0;
        $this->roundLog     = [];
        $this->activeItems  = [];
        $this->itemIdCounter = 0;

        foreach ($this->players as &$p) {
            $p['score']='0'; $p['reactionLog']=[]; $p['combo']=0;
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

        // Delay acak sebelum spawn
        $delay = rand(self::ROUND_DELAY_MIN, self::ROUND_DELAY_MAX);
        $this->scheduleCall($delay, fn() => $this->spawnItems());
    }

    // ── Sutradara: Server yang generate & broadcast item ──────
    private function spawnItems(): void {
        $round      = $this->currentRound;
        $cfg        = self::ITEM_CONFIG[$round] ?? self::ITEM_CONFIG[5];
        $count      = $cfg['count'];
        $duration   = $cfg['duration'];
        $bombChance = $cfg['bomb_chance'];
        $bonusChance= $cfg['bonus_chance'];

        $items = [];
        $spawnedAt = round(microtime(true) * 1000, 2);

        for ($i = 0; $i < $count; $i++) {
            $this->itemIdCounter++;
            $id   = "item_{$this->currentRound}_{$this->itemIdCounter}";
            $rand = mt_rand() / mt_getrandmax();

            if ($rand < $bombChance) {
                $type     = 'bad';
                $itemDur  = $duration + 500;
            } elseif ($rand > (1 - $bonusChance)) {
                $type     = 'bonus';
                $itemDur  = 1200;
            } else {
                $type     = 'good';
                $itemDur  = $duration + mt_rand(-200, 200);
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

            // Simpan di state server
            $this->activeItems[$id] = $itemData;

            // Data yang dikirim ke client (tanpa spawnedAt — itu rahasia server)
            $items[] = [
                'id'       => $id,
                'type'     => $type,
                'top'      => $itemData['top'],
                'left'     => $itemData['left'],
                'duration' => $itemDur,
            ];

            // ── Jadwalkan expiry timer per item ──────────────
            $this->scheduleItemExpiry($id, $itemDur);
        }

        echo "[SPAWN]  Ronde {$round}: {$count} item\n";
        $this->broadcastAll(['type'=>'SPAWN_ITEMS','items'=>$items,'round'=>$round]);
    }

    // ── Penjaga Waktu: item kedaluwarsa di server ─────────────
    private function scheduleItemExpiry(string $itemId, int $durationMs): void {
        $this->scheduleCall($durationMs, function () use ($itemId) {
            if (!isset($this->activeItems[$itemId])) return; // sudah diklik

            $item = $this->activeItems[$itemId];
            unset($this->activeItems[$itemId]);

            echo "[EXPIRY] Item {$itemId} expired.\n";

            // Broadcast ke FE agar hapus item dari layar
            // Jika item 'good', reset combo semua pemain
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
        if (!empty($this->activeItems)) return; // masih ada item

        echo "[ROUND]  Ronde {$this->currentRound} selesai.\n";

        // Broadcast hasil ronde
        $scores = [];
        foreach ($this->players as $p) {
            $scores[$p['username']] = $p['score'];
        }
        arsort($scores);
        $roundWinner = array_key_first($scores);
        $this->broadcastAll(['type'=>'ROUND_RESULT','roundWinner'=>$roundWinner,'scores'=>$scores]);

        // Log ronde
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

        foreach ($this->players as &$p) {
            $p['score']=0; $p['reactionLog']=[]; $p['combo']=0;
            $p['penalties']=0; $p['isReady']=false;
        }
        unset($p);

        echo "[RESET]  Sesi direset.\n";
        $this->broadcastPlayerList();
    }

    private function cancelAllItemTimers(): void {
        // ReactPHP tidak bisa cancel timer tanpa reference.
        // Kita cukup kosongkan activeItems — scheduleCall akan cek isset() dan skip.
        $this->activeItems = [];
    }

    // =========================================================
    //  BROADCAST SCORE UPDATE
    // =========================================================
    private function broadcastScoreUpdate(): void {
        // Kirim skor individual ke masing-masing pemain
        foreach ($this->players as $me) {
            $myAvg  = count($me['reactionLog']) ? round(array_sum($me['reactionLog'])/count($me['reactionLog']), 0) : null;
            $myBest = count($me['reactionLog']) ? round(min($me['reactionLog']), 0) : null;

            // Skor lawan
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
        if (!$db) { $from->send($this->encode(['type'=>'leaderboard_data','data'=>[]])); return; }
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
    private function saveToDatabase(array $stats): void {
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

                // Update users table
                if ($p['bestTime'] !== null) {
                    $db->prepare("UPDATE users SET best_time=IF(best_time IS NULL OR :b<best_time,:b,best_time), games_played=games_played+1, level=GREATEST(1,FLOOR(games_played/5)+1) WHERE username=:u")
                       ->execute([':b'=>$p['bestTime'],':u'=>$p['username']]);
                }
                echo "[DB]     {$p['username']} | Skor: {$p['score']}\n";
            }

            // Round logs
            if (!empty($this->roundLog)) {
                $stmtRound = $db->prepare("INSERT INTO round_logs (username, reaction_time, round_score, is_foul) VALUES (:u,:t,:s,0)");
                foreach ($this->roundLog as $r) {
                    foreach ($stats as $p) {
                        $stmtRound->execute([':u'=>$p['username'],':t'=>$p['avgTime']??0,':s'=>$r['scores'][$p['username']]??0]);
                    }
                }
                echo "[DB]     Round logs: " . count($this->roundLog) . "\n";
            }
        } catch (\PDOException $e) {
            echo "[DB ERR] {$e->getMessage()}\n";
        }
    }

    // =========================================================
    //  HELPERS
    // =========================================================
    private function broadcastAll(array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->clients as $c) { $c->send($json); }
    }

    private function broadcastPlayerList(): void {
        $list = array_map(fn($p) => [
            'name'=>$p['username'],'icon'=>$p['icon'],'score'=>$p['score'],
            'isGuest'=>$p['isGuest'],'ready'=>$p['isReady'],
            'level'=>$p['level'] ?? 1,
        ], $this->players);
        $this->broadcastAll(['type'=>'PLAYER_LIST','players'=>$list]);
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
        try { $db->prepare("INSERT INTO chat_messages (username,message) VALUES (:u,:m)")->execute([':u'=>$username,':m'=>$message]); }
        catch (\PDOException $e) { echo "[DB ERR] Chat: {$e->getMessage()}\n"; }
    }

    private function sendChatHistory(ConnectionInterface $conn): void {
        $db = getDB();
        if (!$db) return;
        try {
            $rows = array_reverse($db->query("SELECT username,message,created_at FROM chat_messages ORDER BY created_at DESC LIMIT 20")->fetchAll());
            foreach ($rows as $r) {
                $conn->send($this->encode(['type'=>'CHAT_MESSAGE','username'=>$r['username'],'message'=>$r['message'],'time'=>date('H:i',strtotime($r['created_at']))]));
            }
        } catch (\PDOException $e) {}
    }
}