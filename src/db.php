<?php

function getDB(): ?PDO {
    static $pdo = null;

    // Reconnect logic yang benar 
    if ($pdo !== null) {
        try {
            $pdo->query("SELECT 1");
            return $pdo; // Koneksi masih sehat
        } catch (\PDOException $e) {
            echo "[DB WARN] Koneksi terputus. Mencoba reconnect...\n";
            $pdo = null;
            // Tidak return di sini — lanjut buat koneksi baru
        }
    }

    // Env var fleksibel, support dua format 
    $host = getenv('MYSQLHOST')     ?: getenv('MYSQL_HOST')     ?: getenv('DB_HOST') ?: 'localhost';
    $port = getenv('MYSQLPORT')     ?: getenv('MYSQL_PORT')     ?: getenv('DB_PORT') ?: '3306';
    $db   = getenv('MYSQLDATABASE') ?: getenv('MYSQL_DATABASE') ?: getenv('DB_NAME') ?: 'railway';
    $user = getenv('MYSQLUSER')     ?: getenv('MYSQL_USER')     ?: getenv('DB_USER') ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('MYSQL_PASSWORD') ?: getenv('DB_PASS') ?: '';

    // Warning jika env var belum terhubung 
    if ($host === 'localhost') {
        echo "[DB WARN] Host masih localhost — env var MySQL belum dihubungkan!\n";
        echo "[DB WARN] Buka Railway -> Variables -> Add Reference dari MySQL service.\n";
    }

    // Debug info ringkas
    echo "[DB] Connecting: {$db}@{$host}:{$port}\n";

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 10, 
            ]
        );
        echo "[DB] Koneksi berhasil!\n";
        return $pdo;

    } catch (\PDOException $e) {
        echo "[DB ERR] Gagal: {$e->getMessage()}\n";
        return null;
    }
}