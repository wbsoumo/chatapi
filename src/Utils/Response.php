<?php

namespace App\Utils;

class Response {
    public static function json(bool $status, string $message, array $data = [], int $statusCode = 200, ?int $errorCode = null): void {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($statusCode);
        
        $response = [
            'status' => $status,
            'message' => $message
        ];

        if ($status) {
            $response['data'] = $data;
        } else {
            $response['error_code'] = $errorCode ?? $statusCode;
        }

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success(string $message = 'Success', array $data = [], int $statusCode = 200): void {
        self::json(true, $message, $data, $statusCode);
    }

    public static function error(string $message, int $statusCode = 400, ?int $errorCode = null): void {
        self::json(false, $message, [], $statusCode, $errorCode);
    }
}
