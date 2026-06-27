<?php

namespace App\Services;

use Redis;
use Dotenv\Dotenv;

class RedisService {
    private static ?Redis $client = null;
    private static bool $attempted = false;

    public static function getClient(): ?Redis {
        if (self::$client !== null) {
            return self::$client;
        }

        if (self::$attempted) {
            return null;
        }

        self::$attempted = true;

        if (!class_exists('Redis')) {
            return null;
        }

        if (!getenv('REDIS_HOST')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $host = $_ENV['REDIS_HOST'] ?? '127.0.0.1';
        $port = (int)($_ENV['REDIS_PORT'] ?? 6379);
        $pass = $_ENV['REDIS_PASSWORD'] ?? null;

        try {
            $redis = new Redis();
            // Connect with a 1 second timeout to avoid blocking if Redis is down
            if ($redis->connect($host, $port, 1.0)) {
                if ($pass) {
                    $redis->auth($pass);
                }
                self::$client = $redis;
                return self::$client;
            }
        } catch (\Exception $e) {
            // Log Redis connection error or fail silently
        }

        return null;
    }
}
