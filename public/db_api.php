<?php
// public/db_api.php — REST API untuk dashboard
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../src/db.php';

$action = $_GET['action'] ?? '';
$db     = getDB();

if (!$db) {
    echo json_encode(['status' => 'error', 'message' => 'Database tidak tersedia']);
    exit;
}

switch ($action) {

    // Leaderboard top 10
    case 'get_leaderboard':
        try {
            $stmt = $db->query("
                SELECT username, icon, total_score, avg_reaction_time, best_time, games_played, level
                FROM players_stats
                ORDER BY total_score DESC
                LIMIT 10
            ");
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // Riwayat ronde terbaru
    case 'get_recent_logs':
        try {
            $username = $_GET['username'] ?? null;
            if ($username) {
                $stmt = $db->prepare("SELECT * FROM round_logs WHERE username = :u ORDER BY created_at DESC LIMIT 50");
                $stmt->execute([':u' => $username]);
            } else {
                $stmt = $db->query("SELECT * FROM round_logs ORDER BY created_at DESC LIMIT 50");
            }
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // Chat history terbaru
    case 'get_chat':
        try {
            $stmt = $db->query("SELECT username, message, created_at FROM chat_messages ORDER BY created_at DESC LIMIT 50");
            echo json_encode(['status' => 'success', 'data' => array_reverse($stmt->fetchAll())]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    // Statistik per pemain untuk dashboard
    case 'get_player_stats':
        try {
            $username = $_GET['username'] ?? '';
            if (!$username) { echo json_encode(['status' => 'error', 'message' => 'Username required']); break; }
            $stmt = $db->prepare("
                SELECT rl.*, ps.total_score, ps.level
                FROM round_logs rl
                JOIN players_stats ps ON ps.username = rl.username
                WHERE rl.username = :u
                ORDER BY rl.created_at ASC
            ");
            $stmt->execute([':u' => $username]);
            echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll()]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => 'Action tidak dikenal. Tersedia: get_leaderboard, get_recent_logs, get_chat, get_player_stats']);
}