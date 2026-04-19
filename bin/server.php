<?php

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;


require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/logic.php';


const PORT_LOCAL = 8080;
$port = (int)(getenv('PORT') ?: PORT_LOCAL);

$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new Logic()
        )
    ),
    $port,
    '0.0.0.0'   // bind ke semua interface (wajib untuk deploy online)
);

echo "------------------------------------------\n";
echo "🚀 SERVER WEBSOCKET REACTION DUEL NYALA!\n";
echo "📍 Berjalan di  : ws://0.0.0.0:{$port}\n";
echo "🌐 Lokal akses  : ws://localhost:{$port}\n";
echo "------------------------------------------\n";
echo "Menunggu pemain konek...\n\n";

$server->run();