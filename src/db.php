<?php
// src/db.php — Koneksi MySQL PDO

function getDB(): ?PDO {
    static $pdo = null;

    // Cek koneksi masih hidup (idle reconnect)
    if ($pdo !== null) {
        try {
            $pdo->query("SELECT 1");
        } catch (\PDOException $e) {
            echo "[DB]     Koneksi terputus (idle), reconnecting...\n";
            $pdo = null;
        }
    }

    if ($pdo !== null) return $pdo;

    // Railway env vars (prioritas) → fallback lokal
    $host = getenv('mainline.proxy.rlwy.net')     ?: getenv('MYSQL_HOST')     ?: 'localhost';
    $port = getenv('46463')     ?: getenv('MYSQL_PORT')     ?: '3306';
    $db   = getenv('railway') ?: getenv('MYSQL_DATABASE') ?: 'railway';
    $user = getenv('root')     ?: getenv('MYSQL_USER')     ?: 'root';
    $pass = getenv('NCYdsxbJvSbepwCdcUnwUYkHnmdRcQmV') ?: getenv('MYSQL_PASSWORD') ?: '';

    echo "[DB]     Konek: host={$host} port={$port} db={$db} user={$user}\n";

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        echo "[DB]     ✅ Koneksi berhasil! ({$db}@{$host})\n";
        return $pdo;

    } catch (\PDOException $e) {
        echo "[DB ERR] ❌ Gagal: {$e->getMessage()}\n";
        return null; // server tidak crash
    }
}