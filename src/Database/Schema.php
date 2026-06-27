<?php

namespace App\Database;

use PDO;
use PDOException;

class Schema {
    public static function getTables(): array {
        return [
            'users' => "
                CREATE TABLE IF NOT EXISTS users (
                    id VARCHAR(36) PRIMARY KEY,
                    mobile_number VARCHAR(20) UNIQUE NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'profiles' => "
                CREATE TABLE IF NOT EXISTS profiles (
                    user_id VARCHAR(36) PRIMARY KEY,
                    display_name VARCHAR(100) DEFAULT NULL,
                    about VARCHAR(255) DEFAULT 'Hey there! I am using WhatsApp.',
                    profile_picture VARCHAR(255) DEFAULT NULL,
                    last_seen TIMESTAMP NULL DEFAULT NULL,
                    online_status VARCHAR(20) DEFAULT 'offline',
                    device_token VARCHAR(255) DEFAULT NULL,
                    privacy_last_seen VARCHAR(20) DEFAULT 'everyone',
                    privacy_profile_picture VARCHAR(20) DEFAULT 'everyone',
                    privacy_about VARCHAR(20) DEFAULT 'everyone',
                    privacy_online_status VARCHAR(20) DEFAULT 'everyone',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'otp' => "
                CREATE TABLE IF NOT EXISTS otp (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    mobile_number VARCHAR(20) NOT NULL,
                    otp_hash VARCHAR(255) NOT NULL,
                    attempts INT DEFAULT 0,
                    resend_at TIMESTAMP NULL DEFAULT NULL,
                    expires_at TIMESTAMP NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'contacts' => "
                CREATE TABLE IF NOT EXISTS contacts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id VARCHAR(36) NOT NULL,
                    contact_name VARCHAR(100) DEFAULT NULL,
                    contact_phone VARCHAR(20) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    UNIQUE (user_id, contact_phone)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'conversations' => "
                CREATE TABLE IF NOT EXISTS conversations (
                    id VARCHAR(36) PRIMARY KEY,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'conversation_members' => "
                CREATE TABLE IF NOT EXISTS conversation_members (
                    conversation_id VARCHAR(36) NOT NULL,
                    user_id VARCHAR(36) NOT NULL,
                    last_synced_message_id INT DEFAULT 0,
                    unread_count INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (conversation_id, user_id),
                    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'messages' => "
                CREATE TABLE IF NOT EXISTS messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_uuid VARCHAR(36) UNIQUE NOT NULL,
                    conversation_id VARCHAR(36) NOT NULL,
                    sender_id VARCHAR(36) NOT NULL,
                    type VARCHAR(20) NOT NULL DEFAULT 'text',
                    content TEXT DEFAULT NULL,
                    reply_to_message_id INT DEFAULT NULL,
                    forwarded BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6),
                    updated_at TIMESTAMP(6) DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
                    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'message_media' => "
                CREATE TABLE IF NOT EXISTS message_media (
                    message_id INT PRIMARY KEY,
                    file_path VARCHAR(255) NOT NULL,
                    file_name VARCHAR(255) NOT NULL,
                    file_size INT NOT NULL,
                    mime_type VARCHAR(100) DEFAULT NULL,
                    duration INT DEFAULT NULL,
                    width INT DEFAULT NULL,
                    height INT DEFAULT NULL,
                    thumbnail_path VARCHAR(255) DEFAULT NULL,
                    waveform TEXT DEFAULT NULL,
                    download_status VARCHAR(20) DEFAULT 'pending',
                    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'message_status' => "
                CREATE TABLE IF NOT EXISTS message_status (
                    message_id INT NOT NULL,
                    user_id VARCHAR(36) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'sent',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (message_id, user_id),
                    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'message_reactions' => "
                CREATE TABLE IF NOT EXISTS message_reactions (
                    message_id INT NOT NULL,
                    user_id VARCHAR(36) NOT NULL,
                    reaction VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (message_id, user_id),
                    FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'deleted_messages' => "
                CREATE TABLE IF NOT EXISTS deleted_messages (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    message_id INT NOT NULL,
                    user_id VARCHAR(36) NOT NULL,
                    delete_type VARCHAR(20) NOT NULL, -- 'me' or 'everyone'
                    deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (message_id, user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'media_uploads' => "
                CREATE TABLE IF NOT EXISTS media_uploads (
                    id VARCHAR(36) PRIMARY KEY,
                    uploader_id VARCHAR(36) NOT NULL,
                    file_path VARCHAR(255) NOT NULL,
                    file_size INT NOT NULL,
                    mime_type VARCHAR(100) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'notifications' => "
                CREATE TABLE IF NOT EXISTS notifications (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id VARCHAR(36) NOT NULL,
                    title VARCHAR(255) DEFAULT NULL,
                    body TEXT DEFAULT NULL,
                    payload TEXT DEFAULT NULL,
                    read_at TIMESTAMP NULL DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'device_tokens' => "
                CREATE TABLE IF NOT EXISTS device_tokens (
                    user_id VARCHAR(36) NOT NULL,
                    device_token VARCHAR(255) NOT NULL,
                    platform VARCHAR(20) DEFAULT 'android',
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, device_token),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'typing_status' => "
                CREATE TABLE IF NOT EXISTS typing_status (
                    conversation_id VARCHAR(36) NOT NULL,
                    user_id VARCHAR(36) NOT NULL,
                    is_typing BOOLEAN DEFAULT FALSE,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (conversation_id, user_id),
                    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'online_status' => "
                CREATE TABLE IF NOT EXISTS online_status (
                    user_id VARCHAR(36) PRIMARY KEY,
                    status VARCHAR(20) DEFAULT 'offline',
                    last_seen TIMESTAMP NULL DEFAULT NULL,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'blocked_users' => "
                CREATE TABLE IF NOT EXISTS blocked_users (
                    user_id VARCHAR(36) NOT NULL,
                    blocked_user_id VARCHAR(36) NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (user_id, blocked_user_id),
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    FOREIGN KEY (blocked_user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'sync_logs' => "
                CREATE TABLE IF NOT EXISTS sync_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id VARCHAR(36) NOT NULL,
                    device_id VARCHAR(255) DEFAULT NULL,
                    last_synced_message_id INT DEFAULT 0,
                    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'audit_logs' => "
                CREATE TABLE IF NOT EXISTS audit_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id VARCHAR(36) DEFAULT NULL,
                    action VARCHAR(100) NOT NULL,
                    details TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'queues' => "
                CREATE TABLE IF NOT EXISTS queues (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_type VARCHAR(50) NOT NULL,
                    payload TEXT NOT NULL,
                    attempts INT DEFAULT 0,
                    status VARCHAR(20) DEFAULT 'pending',
                    error_message TEXT DEFAULT NULL,
                    run_at TIMESTAMP NULL DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            "
        ];
    }

    public static function getIndexes(): array {
        return [
            "CREATE INDEX IF NOT EXISTS idx_otp_mobile ON otp (mobile_number);",
            "CREATE INDEX IF NOT EXISTS idx_msg_conversation_id ON messages (conversation_id, id);",
            "CREATE INDEX IF NOT EXISTS idx_msg_status_lookup ON message_status (user_id, status);"
        ];
    }

    public static function createTables(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        foreach (self::getTables() as $name => $sql) {
            if ($driver === 'sqlite') {
                // Strip MySQL-specific definitions to run on SQLite
                $sql = preg_replace('/ENGINE=InnoDB/i', '', $sql);
                $sql = preg_replace('/DEFAULT CHARSET=\w+/i', '', $sql);
                $sql = preg_replace('/COLLATE=\w+/i', '', $sql);
                $sql = preg_replace('/COLLATE\s+\w+/i', '', $sql);
                $sql = preg_replace('/CHARACTER\s+SET\s+\w+/i', '', $sql);
                $sql = preg_replace('/TIMESTAMP\(6\)/i', 'TIMESTAMP', $sql);
                $sql = preg_replace('/CURRENT_TIMESTAMP\(6\)/i', 'CURRENT_TIMESTAMP', $sql);
                $sql = preg_replace('/ON UPDATE CURRENT_TIMESTAMP\(6\)/i', '', $sql);
                $sql = preg_replace('/ON UPDATE CURRENT_TIMESTAMP/i', '', $sql);
                $sql = preg_replace('/AUTO_INCREMENT/i', 'AUTOINCREMENT', $sql);
                $sql = preg_replace('/INT AUTOINCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            }
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                throw new PDOException("Error creating table '$name': " . $e->getMessage(), (int)$e->getCode());
            }
        }

        // Create indexes
        foreach (self::getIndexes() as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $e) {
                // Fail silently or log if index fails to create
            }
        }
    }

    public static function dropTables(PDO $pdo): void {
        $tables = array_keys(self::getTables());
        // Disable foreign keys checks before dropping
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("PRAGMA foreign_keys = OFF;");
        } else {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
        }

        foreach ($tables as $table) {
            $pdo->exec("DROP TABLE IF EXISTS $table;");
        }

        if ($driver === 'sqlite') {
            $pdo->exec("PRAGMA foreign_keys = ON;");
        } else {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
        }
    }
}
