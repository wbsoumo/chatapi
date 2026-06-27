<?php

namespace App\Middleware;

use App\Utils\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use Exception;

class AuthMiddleware {
    public static function handle(): array {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader) {
            Response::error('Unauthorized: Authorization header is missing', 401);
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            Response::error('Unauthorized: Invalid authorization format', 401);
        }

        $token = substr($authHeader, 7);
        
        if (!getenv('JWT_SECRET')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $jwtSecret = $_ENV['JWT_SECRET'] ?? 'super_secret_access_token_key_12345';

        try {
            $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
            return (array) $decoded;
        } catch (Exception $e) {
            Response::error('Unauthorized: Token has expired or is invalid', 401);
        }
        
        return [];
    }
}
