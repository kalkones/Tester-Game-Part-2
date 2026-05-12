<?php
// src/db.php — Koneksi MySQL PDO

function getDB(): ?PDO {
    static $pdo = null;

    if ($pdo !== null) {
        try { $pdo->query("SELECT 1"); }
        catch (\PDOException $e) { echo "[DB] Reconnecting...\n"; $pdo = null; }
    }

    if ($pdo !== null) return $pdo;

    // 1. Coba ambil dari brankas Railway (Hanya berfungsi jika di-deploy di Railway)
    $envHost = getenv('MYSQLHOST')     ?: getenv('MYSQL_HOST');
    $envPort = getenv('MYSQLPORT')     ?: getenv('MYSQL_PORT');
    $envDb   = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE');
    $envUser = getenv('MYSQLUSER')     ?: getenv('MYSQL_USER');
    $envPass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD');

    // 2. Jika brankas kosong (berarti sedang jalan di Localhost), gunakan data manual ini:
    // Pastikan HOST dan PORT ini persis sama dengan tab "TCP Proxy" di Railway Anda!
    $host = $envHost ?: 'mainline.proxy.rlwy.net';
    $port = $envPort ?: '46463'; 
    $db   = $envDb   ?: 'railway';
    $user = $envUser ?: 'root';
    $pass = $envPass ?: 'NCYdsxbJvSbepwCdcUnwUYkHnmdRcQmV';

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