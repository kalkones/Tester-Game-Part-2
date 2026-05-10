<?php
// src/db.php — Koneksi MySQL PDO

function getDB(): ?PDO {
    static $pdo = null;

    if ($pdo !== null) {
        try { $pdo->query("SELECT 1"); }
        catch (\PDOException $e) { echo "[DB] Reconnecting...\n"; $pdo = null; }
    }

    if ($pdo !== null) return $pdo;

    // Railway env vars — WAJIB di-set di Variables service project-game
    $host = getenv('MYSQLHOST')     ?: getenv('MYSQL_HOST')     ?: 'localhost';
    $port = getenv('MYSQLPORT')     ?: getenv('MYSQL_PORT')     ?: '3306';
    $db   = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: 'railway';
    $user = getenv('MYSQLUSER')     ?: getenv('MYSQL_USER')     ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: '';

    echo "[DB] Konek: host={$host} port={$port} db={$db} user={$user}\n";

    // Deteksi apakah masih pakai localhost (env var belum terhubung)
    if ($host === 'localhost') {
        echo "[DB WARN] ⚠️ Host masih localhost — env var MySQL belum dihubungkan ke service ini!\n";
        echo "[DB WARN] Buka Railway → project-game → Variables → Add Reference dari MySQL.\n";
    }

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10,
            ]
        );
        echo "[DB] ✅ Koneksi berhasil! ({$db}@{$host}:{$port})\n";
        return $pdo;

    } catch (\PDOException $e) {
        echo "[DB ERR] ❌ Gagal: {$e->getMessage()}\n";
        return null;
    }
}