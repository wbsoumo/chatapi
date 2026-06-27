<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Utils\Response;
use App\Utils\Logger;
use App\Middleware\AuthMiddleware;
use App\Middleware\RateLimitingMiddleware;
use App\Controllers\AuthController;
use App\Controllers\ContactController;
use App\Controllers\ChatController;
use App\Controllers\SyncController;
use App\Controllers\MediaController;
use App\Controllers\MessageController;
use App\Controllers\AdminController;

// 1. CORS Headers Setup
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 2. Global Exception Handler
set_exception_handler(function ($e) {
    Logger::error("Unhandled Exception: " . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    Response::error("An internal server error occurred: " . $e->getMessage(), 500, 5000);
});

// 3. Routing
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Strip subdirectory prefix if deployed under folder
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptName !== '/') {
    $requestUri = str_replace($scriptName, '', $requestUri);
}
$requestUri = '/' . trim($requestUri, '/');

$method = $_SERVER['REQUEST_METHOD'];

// Rate limit all requests
RateLimitingMiddleware::handle();

// Simple Router Map
// [path => [method => [ControllerClass, method, requiresAuth]]]
$routes = [
    '/api/auth/otp/generate' => ['POST' => [AuthController::class, 'generateOtp', false]],
    '/api/auth/otp/verify' => ['POST' => [AuthController::class, 'verifyOtp', false]],
    '/api/auth/refresh' => ['POST' => [AuthController::class, 'refreshToken', false]],
    
    '/api/profile/update' => ['POST' => [AuthController::class, 'updateProfile', true]],
    '/api/profile/token' => ['POST' => [AuthController::class, 'registerDeviceToken', true]],
    
    '/api/contact/sync' => ['POST' => [ContactController::class, 'syncContacts', true]],
    
    '/api/chat/start' => ['POST' => [ChatController::class, 'startChat', true]],
    '/api/chat/conversations' => ['GET' => [ChatController::class, 'getConversations', true]],
    '/api/chat/messages' => ['GET' => [ChatController::class, 'getMessages', true]],
    '/api/chat/search' => ['GET' => [ChatController::class, 'searchMessages', true]],
    '/api/chat/sync' => ['POST' => [SyncController::class, 'sync', true]],
    
    '/api/message/send' => ['POST' => [MessageController::class, 'sendMessage', true]],
    '/api/message/status' => ['POST' => [MessageController::class, 'updateMessageStatus', true]],
    '/api/message/react' => ['POST' => [MessageController::class, 'reactToMessage', true]],
    '/api/message/delete' => ['POST' => [MessageController::class, 'deleteMessage', true]],
    
    '/api/conversation/delete' => ['POST' => [MessageController::class, 'deleteConversation', true]],
    
    '/api/media/upload' => ['POST' => [MediaController::class, 'uploadMedia', true]],
    '/api/gif/search' => ['GET' => [MediaController::class, 'searchGifs', true]],
    '/api/gif/trending' => ['GET' => [MediaController::class, 'getTrendingGifs', true]],
    '/api/stickers/packs' => ['GET' => [MediaController::class, 'getStickerPacks', true]],
    
    '/api/admin/stats' => ['GET' => [AdminController::class, 'getStats', true]],
    '/api/admin/users' => ['GET' => [AdminController::class, 'getUsers', true]],
    '/api/admin/broadcast' => ['POST' => [AdminController::class, 'broadcastNotification', true]],
];

if (isset($routes[$requestUri]) && isset($routes[$requestUri][$method])) {
    list($controllerClass, $action, $requiresAuth) = $routes[$requestUri][$method];
    
    $userContext = [];
    if ($requiresAuth) {
        $userContext = AuthMiddleware::handle();
    }
    
    $controller = new $controllerClass();
    if ($requiresAuth) {
        $controller->$action($userContext);
    } else {
        $controller->$action();
    }
} else {
    Response::error("Resource not found: $method $requestUri", 404, 4040);
}
