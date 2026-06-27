<?php

namespace App\Database;

use PDO;
use PDOException;
use Dotenv\Dotenv;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            // Load environment variables if not already loaded
            if (!getenv('DB_HOST')) {
                $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
                $dotenv->safeLoad();
            }

            $host = $_ENV['DB_HOST'] ?? '127.0.0.1';
            $port = $_ENV['DB_PORT'] ?? '3306';
            $db   = $_ENV['DB_DATABASE'] ?? 'whatsapp_db';
            $user = $_ENV['DB_USERNAME'] ?? 'root';
            $pass = $_ENV['DB_PASSWORD'] ?? '';
            $charset = 'utf8mb4';

            if (str_starts_with($host, 'sqlite:')) {
                $dsn = $host;
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ];
            } else {
                $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ];
            }

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
                if (str_starts_with($host, 'sqlite:')) {
                    // Enable foreign keys for SQLite
                    self::$instance->exec("PRAGMA foreign_keys = ON;");
                }
            } catch (PDOException $e) {
                // Return descriptive error or log it
                throw new PDOException("Database connection failed: " . $e->getMessage(), (int)$e->getCode());
            }
        }

        return self::$instance;
    }

    // A helper method to run migrations or reset connections for tests
    public static function resetConnection(): void {
        self::$instance = null;
    }
}
