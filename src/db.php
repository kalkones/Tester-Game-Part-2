<?php
// src/db.php — Koneksi MySQL PDO (Auto-Detect Path)

function getDB(): ?PDO {
    static $pdo = null;

    if ($pdo !== null) {
        try { $pdo->query("SELECT 1"); }
        catch (\PDOException $e) { $pdo = null; }
    }

    if ($pdo !== null) return $pdo;

    // 1. CEK VARIABEL INTERNAL RAILWAY (Prioritas Utama)
    $host = getenv('MYSQLHOST')     ?: getenv('MYSQL_HOST');
    $port = getenv('MYSQLPORT')     ?: getenv('MYSQL_PORT');
    $db   = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE');
    $user = getenv('MYSQLUSER')     ?: getenv('MYSQL_USER');
    $pass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD');

    // 2. FALLBACK KE PROXY EKSTERNAL (Jika Anda jalankan dari Laptop/Localhost)
    if (!$host) {
        echo "[DB] Menggunakan Jalur Eksternal (Localhost detected)...\n";
        $host = 'mainline.proxy.rlwy.net';
        $port = '46463'; // <-- Pastikan ini angka terbaru dari tab Connect MySQL
        $db   = 'railway';
        $user = 'root';
        $pass = 'NCYdsxbJvSbepwCdcUnwUYkHnmdRcQmV';
    } else {
        echo "[DB] Menggunakan Jalur Internal Railway...\n";
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
        echo "[DB] ✅ Berhasil Konek ke: {$host}:{$port}\n";
        return $pdo;

    } catch (\PDOException $e) {
        echo "[DB ERR] ❌ Gagal Total: " . $e->getMessage() . "\n";
        return null;
    }
}