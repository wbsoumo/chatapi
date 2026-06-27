<?php

namespace App\Middleware;

use App\Utils\Response;
use App\Services\RedisService;

class RateLimitingMiddleware {
    private const LIMIT = 60; // 60 requests
    private const WINDOW = 60; // per 60 seconds

    public static function handle(): void {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $key = "rate_limit:" . md5($ip);

        $redis = RedisService::getClient();
        if ($redis) {
            try {
                $current = $redis->get($key);
                if ($current !== false && (int)$current >= self::LIMIT) {
                    Response::error('Too Many Requests', 429);
                }
                
                if ($current === false) {
                    $redis->set($key, 1, self::WINDOW);
                } else {
                    $redis->incr($key);
                }
                return;
            } catch (\Exception $e) {
                // Fail-safe to file based rate limiting
            }
        }

        // File-based rate limiting fallback
        self::handleFileBased($key);
    }

    private static function handleFileBased(string $key): void {
        $dir = dirname(__DIR__, 2) . '/storage/rate_limits';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file = $dir . '/' . $key;
        $now = time();

        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && ($now - $data['start']) < self::WINDOW) {
                if ($data['count'] >= self::LIMIT) {
                    Response::error('Too Many Requests', 429);
                }
                $data['count']++;
                file_put_contents($file, json_encode($data));
                return;
            }
        }

        // Initialize or reset window
        $data = [
            'start' => $now,
            'count' => 1
        ];
        file_put_contents($file, json_encode($data));
    }
}
