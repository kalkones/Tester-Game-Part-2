<?php

require_once __DIR__ . '/db.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class Logic implements MessageComponentInterface {

     
    //  KONSTANTA GAME
     
    const MAX_PLAYERS    = 2;
    const MAX_ROUNDS     = 5;
    const DELAY_MIN_MS   = 2000;
    const DELAY_MAX_MS   = 5000;
    const PENALTY_POINTS = 50;
    const ANTI_CHEAT_MS  = 500;

     
    //  STATE SERVER
     
    private \SplObjectStorage $clients;

    private array $players = [];
    /*
     * Struktur per entry $players:
     * [
     *   'conn'        => ConnectionInterface,
     *   'username'    => string,
     *   'icon'        => string,        // icon FA class
     *   'isGuest'     => bool,
     *   'score'       => int,           // skor total match ini
     *   'roundScores' => int[],         // skor per ronde
     *   'reactionLog' => float[],
     *   'penalties'   => int,
     *   'isReady'     => bool,          // untuk ready room
     *   'combo'       => int,           // combo saat ini
     * ]
     */

    private bool   $gameStarted   = false;
    private bool   $waitingForGo  = false;
    private bool   $goSignalSent  = false;
    private int    $currentRound  = 0;
    private ?float $goTimerStart  = null;
    private array  $roundLog      = [];

     
    //  KONSTRUKTOR
     
    public function __construct() {
        $this->clients = new \SplObjectStorage();
        echo "[SERVER] Logic siap. Menunggu " . self::MAX_PLAYERS . " pemain...\n";
    }

     
    //  RATCHET CALLBACKS
     
    public function onOpen(ConnectionInterface $conn): void {
        $this->clients->attach($conn);
        echo "[OPEN]   Koneksi baru: #{$conn->resourceId}\n";
    }

    public function onMessage(ConnectionInterface $from, $msg): void {
        $data = json_decode($msg, true);

        if (!$data || !isset($data['type'])) {
            echo "[WARN]   Pesan tidak valid dari #{$from->resourceId}\n";
            return;
        }

        echo "[MSG]    Dari #{$from->resourceId} → type: {$data['type']}\n";

        switch ($data['type']) {

            // ── Auth ──────────────────────────────────────────────────
            case 'AUTH_LOGIN':
                $this->handleAuthLogin($from, $data);
                break;

            case 'AUTH_REGISTER':
                $this->handleAuthRegister($from, $data);
                break;

            // ── Lobby ─────────────────────────────────────────────────
            case 'JOIN':
                $this->handleJoin($from, $data);
                break;

            case 'CHAT':
                $this->handleChat($from, $data);
                break;

            case 'FIND_MATCH':
                $this->handleFindMatch($from, $data);
                break;

            case 'PLAYER_READY':
                $this->handlePlayerReady($from, $data);
                break;

            // ── Gameplay OSU-like ──────────────────────────────────────
            case 'ROUND_SCORE':
                // Client kirim hasil skor ronde (item hit, miss, bomb, combo)
                $this->handleRoundScore($from, $data);
                break;

            case 'REACTION_TIME':
                // Backward compat — mode lama
                $this->handleReactionTime($from, $data);
                break;

            case 'TOO_EARLY':
                $this->handleTooEarly($from, $data);
                break;

            // ── Leaderboard ───────────────────────────────────────────
            case 'get_leaderboard':
                $this->handleGetLeaderboard($from);
                break;

            default:
                echo "[WARN]   Tipe pesan tidak dikenal: {$data['type']}\n";
        }
    }

    public function onClose(ConnectionInterface $conn): void {
        $this->clients->detach($conn);
        $username = $this->getUsernameByConn($conn) ?? "#{$conn->resourceId}";
        $this->removePlayer($conn);
        echo "[CLOSE]  {$username} telah keluar.\n";

        if ($this->gameStarted) {
            echo "[RESET]  Pemain keluar saat game aktif. Mereset sesi...\n";
            $this->resetGame();
            $this->broadcastAll([
                'type'    => 'SYSTEM',
                'message' => "{$username} keluar. Game dibatalkan."
            ]);
        }

        $this->broadcastPlayerList();
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void {
        echo "[ERROR]  #{$conn->resourceId}: {$e->getMessage()}\n";
        $conn->close();
    }

     
    //  HANDLER — AUTH LOGIN
     
    private function handleAuthLogin(ConnectionInterface $conn, array $data): void {
        $identifier = trim($data['identifier'] ?? '');
        $password   = $data['password'] ?? '';

        if (!$identifier || !$password) {
            $conn->send($this->encode(['type' => 'AUTH_RESULT', 'success' => false, 'message' => 'Username/email dan password wajib diisi.']));
            return;
        }

        $db = getDB();
        if (!$db) {
            $conn->send($this->encode(['type' => 'AUTH_RESULT', 'success' => false, 'message' => 'Server database tidak tersedia.']));
            return;
        }

        try {
            // Cari berdasarkan username ATAU email
            $stmt = $db->prepare("SELECT * FROM users WHERE username = :id OR email = :id LIMIT 1");
            $stmt->execute([':id' => $identifier]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $conn->send($this->encode(['type' => 'AUTH_RESULT', 'success' => false, 'message' => 'Username/email atau password salah.']));
                return;
            }

            echo "[AUTH]   Login berhasil: {$user['username']}\n";
            $conn->send($this->encode([
                'type'    => 'AUTH_RESULT',
                'success' => true,
                'user'    => [
                    'username'    => $user['username'],
                    'email'       => $user['email'],
                    'icon'        => $user['icon'],
                    'level'       => $user['level'],
                    'gamesPlayed' => $user['games_played'],
                    'bestTime'    => $user['best_time'],
                    'type'        => 'registered',
                ],
            ]));

        } catch (\PDOException $e) {
            echo "[DB ERR] Login: {$e->getMessage()}\n";
            $conn->send($this->encode(['type' => 'AUTH_RESULT', 'success' => false, 'message' => 'Error server.']));
        }
    }

     
    //  HANDLER — AUTH REGISTER
     
    private function handleAuthRegister(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        $email    = trim($data['email']    ?? '');
        $password = $data['password']      ?? '';

        if (!$username || !$password) {
            $conn->send($this->encode(['type' => 'REGISTER_RESULT', 'success' => false, 'message' => 'Username dan password wajib diisi.']));
            return;
        }

        $db = getDB();
        if (!$db) {
            $conn->send($this->encode(['type' => 'REGISTER_RESULT', 'success' => false, 'message' => 'Server database tidak tersedia.']));
            return;
        }

        try {
            // Cek duplikat username
            $stmt = $db->prepare("SELECT id FROM users WHERE username = :u OR (email != '' AND email = :e) LIMIT 1");
            $stmt->execute([':u' => $username, ':e' => $email]);
            if ($stmt->fetch()) {
                $conn->send($this->encode(['type' => 'REGISTER_RESULT', 'success' => false, 'message' => 'Username atau email sudah terdaftar.']));
                return;
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (username, email, password_hash) VALUES (:u, :e, :p)");
            $stmt->execute([':u' => $username, ':e' => $email, ':p' => $hash]);

            echo "[AUTH]   Register berhasil: {$username}\n";
            $conn->send($this->encode([
                'type'    => 'REGISTER_RESULT',
                'success' => true,
                'user'    => [
                    'username'    => $username,
                    'email'       => $email,
                    'icon'        => 'fa-user',
                    'level'       => 1,
                    'gamesPlayed' => 0,
                    'bestTime'    => null,
                    'type'        => 'registered',
                ],
            ]));

        } catch (\PDOException $e) {
            echo "[DB ERR] Register: {$e->getMessage()}\n";
            $conn->send($this->encode(['type' => 'REGISTER_RESULT', 'success' => false, 'message' => 'Error server.']));
        }
    }

     
    //  HANDLER — JOIN LOBBY
     
    private function handleJoin(ConnectionInterface $conn, array $data): void {
        $username = trim($data['username'] ?? '');
        $icon     = $data['icon']     ?? 'fa-user';
        $isGuest  = $data['isGuest']  ?? false;

        if ($username === '') {
            $conn->send($this->encode(['type' => 'ERROR', 'message' => 'Username tidak boleh kosong.']));
            return;
        }

        // Cek duplikat
        foreach ($this->players as $p) {
            if (strtolower($p['username']) === strtolower($username)) {
                $conn->send($this->encode(['type' => 'ERROR', 'message' => "Nama '{$username}' sudah dipakai."]));
                return;
            }
        }

        $this->players[] = [
            'conn'        => $conn,
            'username'    => $username,
            'icon'        => $icon,
            'isGuest'     => $isGuest,
            'score'       => 0,
            'roundScores' => [],
            'reactionLog' => [],
            'penalties'   => 0,
            'isReady'     => false,
            'combo'       => 0,
        ];

        echo "[JOIN]   {$username} bergabung. Total: " . count($this->players) . "\n";
        $this->broadcastPlayerList();

        // Kirim chat history ke pemain baru
        $this->sendChatHistory($conn);
    }

     
    //  HANDLER — CHAT
     
    private function handleChat(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';
        $message  = trim($data['message'] ?? '');

        if ($message === '' || strlen($message) > 200) return;

        $time = date('H:i');

        // Broadcast ke semua
        $this->broadcastAll([
            'type'     => 'CHAT_MESSAGE',
            'username' => $username,
            'message'  => $message,
            'time'     => $time,
        ]);

        // Simpan ke DB
        $this->saveChatMessage($username, $message);

        echo "[CHAT]   {$username}: {$message}\n";
    }

    private function saveChatMessage(string $username, string $message): void {
        $db = getDB();
        if (!$db) return;
        try {
            $stmt = $db->prepare("INSERT INTO chat_messages (username, message) VALUES (:u, :m)");
            $stmt->execute([':u' => $username, ':m' => $message]);
        } catch (\PDOException $e) {
            echo "[DB ERR] Chat save: {$e->getMessage()}\n";
        }
    }

    private function sendChatHistory(ConnectionInterface $conn): void {
        $db = getDB();
        if (!$db) return;
        try {
            $stmt = $db->query("SELECT username, message, created_at FROM chat_messages ORDER BY created_at DESC LIMIT 20");
            $rows = array_reverse($stmt->fetchAll());
            foreach ($rows as $row) {
                $conn->send($this->encode([
                    'type'     => 'CHAT_MESSAGE',
                    'username' => $row['username'],
                    'message'  => $row['message'],
                    'time'     => date('H:i', strtotime($row['created_at'])),
                ]));
            }
        } catch (\PDOException $e) {
            echo "[DB ERR] Chat history: {$e->getMessage()}\n";
        }
    }

     
    //  HANDLER — FIND MATCH (Matchmaking)
     
    private function handleFindMatch(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';

        // Tandai pemain ini sedang mencari match
        foreach ($this->players as &$p) {
            if ($p['conn'] === $conn) {
                $p['findingMatch'] = true;
                break;
            }
        }
        unset($p);

        // Hitung berapa yang sedang mencari
        $searching = array_filter($this->players, fn($p) => $p['findingMatch'] ?? false);

        echo "[MATCH]  {$username} mencari lawan. Pencari: " . count($searching) . "\n";

        if (count($searching) >= self::MAX_PLAYERS) {
            // Ambil 2 pemain pertama yang mencari
            $matched = array_slice(array_values($searching), 0, 2);

            // Reset status findingMatch
            foreach ($this->players as &$p) {
                $p['findingMatch'] = false;
            }
            unset($p);

            // Kirim MATCH_FOUND ke kedua pemain
            foreach ($matched as $mp) {
                $opponent = array_values(array_filter($matched, fn($x) => $x['username'] !== $mp['username']))[0] ?? null;
                $mp['conn']->send($this->encode([
                    'type'         => 'MATCH_FOUND',
                    'opponent'     => $opponent ? $opponent['username'] : '?',
                    'opponentIcon' => $opponent ? $opponent['icon'] : 'fa-robot',
                ]));
            }

            echo "[MATCH]  Match ditemukan: {$matched[0]['username']} vs {$matched[1]['username']}\n";
        } else {
            // Belum cukup, beritahu client untuk menunggu
            $conn->send($this->encode([
                'type'    => 'MATCH_SEARCHING',
                'message' => 'Mencari lawan...',
            ]));
        }
    }

     
    //  HANDLER — PLAYER READY
     
    private function handlePlayerReady(ConnectionInterface $conn, array $data): void {
        $username = $data['username'] ?? '?';

        foreach ($this->players as &$p) {
            if ($p['username'] === $username) {
                $p['isReady'] = true;
                break;
            }
        }
        unset($p);

        // Broadcast status ready
        $this->broadcastAll([
            'type'     => 'PLAYER_READY_UPDATE',
            'username' => $username,
            'isReady'  => true,
        ]);

        echo "[READY]  {$username} siap!\n";

        // Cek apakah semua pemain sudah ready
        $readyCount = count(array_filter($this->players, fn($p) => $p['isReady']));
        if ($readyCount >= self::MAX_PLAYERS && !$this->gameStarted) {
            $this->scheduleCall(1000, fn() => $this->startGame());
        }
    }

     
    //  HANDLER — ROUND SCORE (OSU-like gameplay)
    //  Client kirim skor tiap ronde setelah semua item selesai
     
    private function handleRoundScore(ConnectionInterface $from, array $data): void {
        if (!$this->gameStarted) return;

        $username  = $data['username']   ?? '?';
        $roundScore = (int)($data['score']  ?? 0);
        $itemsHit  = (int)($data['itemsHit']  ?? 0);
        $itemsMiss = (int)($data['itemsMiss'] ?? 0);
        $bombsHit  = (int)($data['bombsHit']  ?? 0);
        $comboMax  = (int)($data['comboMax']  ?? 0);
        $avgReact  = (float)($data['avgReaction'] ?? 0);

        // Anti-cheat: skor max per ronde (15 item × 250 poin max)
        $maxPossible = 15 * 250;
        if ($roundScore > $maxPossible) {
            echo "[CHEAT?] Skor mencurigakan dari {$username}: {$roundScore}\n";
            $roundScore = $maxPossible;
        }

        foreach ($this->players as &$p) {
            if ($p['username'] === $username) {
                $p['score']         += $roundScore;
                $p['roundScores'][] = $roundScore;
                $p['combo']         = max($p['combo'], $comboMax);
                if ($avgReact > 0) $p['reactionLog'][] = $avgReact;
                break;
            }
        }
        unset($p);

        // Log ronde
        $this->roundLog[] = [
            'round'     => $this->currentRound,
            'username'  => $username,
            'score'     => $roundScore,
            'itemsHit'  => $itemsHit,
            'itemsMiss' => $itemsMiss,
            'bombsHit'  => $bombsHit,
            'comboMax'  => $comboMax,
            'avgReact'  => $avgReact,
        ];

        // Broadcast skor update ke semua
        $this->broadcastAll([
            'type'       => 'SCORE_UPDATE',
            'username'   => $username,
            'roundScore' => $roundScore,
            'totalScore' => $this->getPlayerScore($username),
            'round'      => $this->currentRound,
        ]);

        echo "[SCORE]  {$username} ronde {$this->currentRound}: {$roundScore} poin\n";

        // Cek apakah semua pemain sudah submit skor ronde ini
        $submittedThisRound = count(array_filter($this->roundLog, fn($r) => $r['round'] === $this->currentRound));
        if ($submittedThisRound >= count($this->players)) {
            if ($this->currentRound >= self::MAX_ROUNDS) {
                $this->scheduleCall(2000, fn() => $this->endGame());
            } else {
                $this->scheduleCall(2000, fn() => $this->startRound());
            }
        }
    }

     
    //  HANDLER — REACTION TIME (backward compat mode lama)
     
    private function handleReactionTime(ConnectionInterface $from, array $data): void {
        if (!$this->goSignalSent) return;

        $username     = $data['username'] ?? '?';
        $clientTimeMs = (float)($data['time'] ?? 0);
        $serverTimeMs = round((microtime(true) * 1000) - $this->goTimerStart, 2);

        if (abs($clientTimeMs - $serverTimeMs) > self::ANTI_CHEAT_MS) {
            $finalTime = $serverTimeMs;
        } else {
            $finalTime = round(($clientTimeMs + $serverTimeMs) / 2, 2);
        }

        $this->goSignalSent = false;
        $this->addScore($username, 100);
        $this->addReactionLog($username, $finalTime);

        $this->roundLog[] = ['round' => $this->currentRound, 'winner' => $username, 'time' => $finalTime];

        $this->broadcastAll(['type' => 'RESULT', 'winner' => $username, 'time' => $finalTime]);

        if ($this->currentRound >= self::MAX_ROUNDS) {
            $this->scheduleCall(2500, fn() => $this->endGame());
        } else {
            $this->scheduleCall(2500, fn() => $this->startRound());
        }
    }

     
    //  HANDLER — TOO EARLY
     
    private function handleTooEarly(ConnectionInterface $from, array $data): void {
        $username = $data['username'] ?? '?';
        echo "[EARLY]  {$username} klik terlalu cepat!\n";

        $this->addPenalty($username);
        $this->broadcastAll(['type' => 'TOO_EARLY', 'culprit' => $username]);

        if ($this->waitingForGo) {
            $this->waitingForGo = false;
            $this->scheduleCall(1500, fn() => $this->scheduleGoSignal());
        }
    }

     
    //  HANDLER — LEADERBOARD
     
    private function handleGetLeaderboard(ConnectionInterface $from): void {
        $db = getDB();
        if (!$db) {
            $from->send($this->encode(['type' => 'leaderboard_data', 'data' => [], 'warning' => 'DB tidak tersedia.']));
            return;
        }
        try {
            $stmt = $db->query("
                SELECT username, icon, total_score, avg_reaction_time, best_time, games_played, level
                FROM players_stats
                ORDER BY total_score DESC
                LIMIT 10
            ");
            $from->send($this->encode(['type' => 'leaderboard_data', 'data' => $stmt->fetchAll()]));
        } catch (\PDOException $e) {
            echo "[DB ERR] Leaderboard: {$e->getMessage()}\n";
            $from->send($this->encode(['type' => 'leaderboard_data', 'data' => []]));
        }
    }

     
    //  GAME FLOW
     
    private function startGame(): void {
        $this->gameStarted  = true;
        $this->currentRound = 0;
        $this->roundLog     = [];

        // Reset ready status
        foreach ($this->players as &$p) {
            $p['isReady']     = false;
            $p['score']       = 0;
            $p['roundScores'] = [];
            $p['reactionLog'] = [];
            $p['penalties']   = 0;
            $p['combo']       = 0;
        }
        unset($p);

        echo "[GAME]   Game dimulai!\n";
        $this->broadcastAll(['type' => 'START_GAME']);
        $this->scheduleCall(1500, fn() => $this->startRound());
    }

    private function startRound(): void {
        $this->currentRound++;
        $this->goSignalSent = false;
        $this->waitingForGo = false;

        echo "[ROUND]  Ronde {$this->currentRound} / " . self::MAX_ROUNDS . "\n";

        $this->broadcastAll([
            'type'  => 'ROUND_UPDATE',
            'round' => $this->currentRound,
        ]);

        $this->broadcastAll(['type' => 'WAIT']);
        $this->scheduleGoSignal();
    }

    private function scheduleGoSignal(): void {
        $this->waitingForGo = true;
        $delayMs = rand(self::DELAY_MIN_MS, self::DELAY_MAX_MS);

        $this->scheduleCall($delayMs, function () {
            if (!$this->waitingForGo) return;
            $this->waitingForGo = false;
            $this->goSignalSent = true;
            $this->goTimerStart = round(microtime(true) * 1000, 2);
            echo "[GO]     Sinyal GO dikirim!\n";
            $this->broadcastAll(['type' => 'GO']);
        });
    }

    private function endGame(): void {
        $this->gameStarted = false;
        echo "[GAME]   Game selesai!\n";

        $statsPerPlayer = [];

        foreach ($this->players as $p) {
            $log = $p['reactionLog'];
            $avg  = count($log) ? round(array_sum($log) / count($log), 2) : null;
            $best = count($log) ? round(min($log), 2) : null;
            $cons = count($log) > 1 ? round(max($log) - min($log), 2) : null;

            $statsPerPlayer[] = [
                'username'    => $p['username'],
                'icon'        => $p['icon'],
                'isGuest'     => $p['isGuest'],
                'score'       => $p['score'],
                'roundScores' => $p['roundScores'],
                'avgTime'     => $avg,
                'bestTime'    => $best,
                'consistency' => $cons,
                'penalties'   => $p['penalties'],
                'roundsWon'   => count($log),
                'comboMax'    => $p['combo'],
                'reactionLog' => $log,
            ];
        }

        usort($statsPerPlayer, fn($a, $b) => $b['score'] <=> $a['score']);

        $this->broadcastAll(['type' => 'GAME_OVER', 'stats' => $statsPerPlayer]);

        echo "[STATS]  " . json_encode($statsPerPlayer, JSON_PRETTY_PRINT) . "\n";

        $this->saveToDatabase($statsPerPlayer);

        $this->scheduleCall(5000, fn() => $this->resetGame());
    }

    private function resetGame(): void {
        $this->gameStarted  = false;
        $this->waitingForGo = false;
        $this->goSignalSent = false;
        $this->currentRound = 0;
        $this->goTimerStart = null;
        $this->roundLog     = [];

        foreach ($this->players as &$p) {
            $p['score']       = 0;
            $p['roundScores'] = [];
            $p['reactionLog'] = [];
            $p['penalties']   = 0;
            $p['isReady']     = false;
            $p['combo']       = 0;
        }
        unset($p);

        echo "[RESET]  Sesi direset.\n";
        $this->broadcastPlayerList();
    }

    
    //  DATABASE — SAVE
    
    private function saveToDatabase(array $statsPerPlayer): void {
        $db = getDB();
        if (!$db) {
            echo "[DB ERR] getDB() null, skip penyimpanan.\n";
            return;
        }

        try {
            // Update players_stats (hanya untuk non-guest)
            $stmtStats = $db->prepare("
                INSERT INTO players_stats
                    (username, icon, total_score, avg_reaction_time, best_time, games_played)
                VALUES
                    (:username, :icon, :score, :avg, :best, 1)
                ON DUPLICATE KEY UPDATE
                    icon              = VALUES(icon),
                    total_score       = total_score + VALUES(total_score),
                    avg_reaction_time = IF(
                        avg_reaction_time IS NULL,
                        VALUES(avg_reaction_time),
                        ROUND((avg_reaction_time + VALUES(avg_reaction_time)) / 2, 2)
                    ),
                    best_time         = IF(
                        best_time IS NULL OR VALUES(best_time) < best_time,
                        VALUES(best_time),
                        best_time
                    ),
                    games_played      = games_played + 1,
                    level             = GREATEST(1, FLOOR(games_played / 5) + 1)
            ");

            foreach ($statsPerPlayer as $p) {
                if ($p['isGuest']) continue; // skip guest

                $stmtStats->execute([
                    ':username' => $p['username'],
                    ':icon'     => $p['icon'],
                    ':score'    => $p['score'],
                    ':avg'      => $p['avgTime'],
                    ':best'     => $p['bestTime'],
                ]);

                // Update best_time di tabel users juga
                if ($p['bestTime'] !== null) {
                    $db->prepare("
                        UPDATE users SET
                            best_time    = IF(best_time IS NULL OR :best < best_time, :best, best_time),
                            games_played = games_played + 1,
                            level        = GREATEST(1, FLOOR(games_played / 5) + 1)
                        WHERE username = :u
                    ")->execute([':best' => $p['bestTime'], ':u' => $p['username']]);
                }

                echo "[DB]     Disimpan: {$p['username']} | Skor: {$p['score']} | Avg: {$p['avgTime']}ms\n";
            }

            // Simpan round_logs
            if (!empty($this->roundLog)) {
                $stmtRound = $db->prepare("
                    INSERT INTO round_logs (username, reaction_time, round_score, items_hit, items_miss, bombs_hit, combo_max, is_foul)
                    VALUES (:username, :time, :score, :hit, :miss, :bomb, :combo, 0)
                ");

                foreach ($this->roundLog as $r) {
                    $stmtRound->execute([
                        ':username' => $r['username']  ?? ($r['winner'] ?? '?'),
                        ':time'     => $r['avgReact']  ?? ($r['time'] ?? 0),
                        ':score'    => $r['score']     ?? 0,
                        ':hit'      => $r['itemsHit']  ?? 0,
                        ':miss'     => $r['itemsMiss'] ?? 0,
                        ':bomb'     => $r['bombsHit']  ?? 0,
                        ':combo'    => $r['comboMax']  ?? 0,
                    ]);
                }

                echo "[DB]     Round logs: " . count($this->roundLog) . " entri\n";
            }

        } catch (\PDOException $e) {
            echo "[DB ERR] Gagal simpan: {$e->getMessage()}\n";
        }
    }

    
    //  HELPER

    private function getPlayerScore(string $username): int {
        foreach ($this->players as $p) {
            if ($p['username'] === $username) return $p['score'];
        }
        return 0;
    }

    private function addScore(string $username, int $points): void {
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) { $p['score'] += $points; break; }
        }
        unset($p);
    }

    private function addReactionLog(string $username, float $time): void {
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) { $p['reactionLog'][] = $time; break; }
        }
        unset($p);
    }

    private function addPenalty(string $username): void {
        foreach ($this->players as &$p) {
            if ($p['username'] === $username) {
                $p['penalties']++;
                $p['score'] = max(0, $p['score'] - self::PENALTY_POINTS);
                break;
            }
        }
        unset($p);
    }

    private function broadcastAll(array $payload): void {
        $json = $this->encode($payload);
        foreach ($this->clients as $client) {
            $client->send($json);
        }
    }

    private function broadcastPlayerList(): void {
        $list = array_map(fn($p) => [
            'name'    => $p['username'],
            'icon'    => $p['icon'],
            'score'   => $p['score'],
            'isGuest' => $p['isGuest'],
            'ready'   => $p['isReady'],
        ], $this->players);

        $this->broadcastAll(['type' => 'PLAYER_LIST', 'players' => $list]);
    }

    private function removePlayer(ConnectionInterface $conn): void {
        $this->players = array_values(
            array_filter($this->players, fn($p) => $p['conn'] !== $conn)
        );
    }

    private function getUsernameByConn(ConnectionInterface $conn): ?string {
        foreach ($this->players as $p) {
            if ($p['conn'] === $conn) return $p['username'];
        }
        return null;
    }

    private function encode(array $data): string {
        return json_encode($data);
    }

    private function scheduleCall(int $ms, callable $callback): void {
        \React\EventLoop\Loop::get()->addTimer($ms / 1000.0, $callback);
    }
}