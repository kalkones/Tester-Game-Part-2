<?php
// src/db.php — Koneksi MySQL PDO

function getDB(): ?PDO {
    static $pdo = null;

    if ($pdo !== null) {
        try { $pdo->query("SELECT 1"); }
        catch (\PDOException $e) { echo "[DB] Reconnecting...\n"; $pdo = null; }
    }

    if ($pdo !== null) return $pdo;

    // Railway env vars (prioritas) → fallback lokal
    $host = getenv('MYSQLHOST')     ?: getenv('mainline.proxy.rlwy.net')     ?: 'mainline.proxy.rlwy.net';
    $port = getenv('MYSQLPORT')     ?: getenv('46463')     ?: '3306';
    $db   = getenv('MYSQLDATABASE') ?: getenv('railway') ?: 'railway';
    $user = getenv('MYSQLUSER')     ?: getenv('root')     ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('NCYdsxbJvSbepwCdcUnwUYkHnmdRcQmV') ?: 'NCYdsxbJvSbepwCdcUnwUYkHnmdRcQmV';

    echo "[DB] Konek: host={$host} port={$port} db={$db} user={$user}\n";

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user, $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10, // Timeout 10 detik agar tidak hang
            ]
        );
        echo "[DB] ✅ Koneksi berhasil! ({$db}@{$host}:{$port})\n";
        return $pdo;

    } catch (\PDOException $e) {
        echo "[DB ERR] ❌ Gagal: {$e->getMessage()}\n";
        return null;
    }
}