#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use App\WebSocket\ChatHandler;
use Dotenv\Dotenv;

// Load environment variables
if (!getenv('WEBSOCKET_PORT')) {
    $dotenv = Dotenv::createImmutable(dirname(__DIR__));
    $dotenv->safeLoad();
}

$host = $_ENV['WEBSOCKET_HOST'] ?? '0.0.0.0';
$port = (int)($_ENV['WEBSOCKET_PORT'] ?? 8080);

echo "Starting WebSocket server on $host:$port...\n";

try {
    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new ChatHandler()
            )
        ),
        $port,
        $host
    );

    $server->run();
} catch (\Exception $e) {
    echo "Failed to start WebSocket server: " . $e->getMessage() . "\n";
    exit(1);
}
