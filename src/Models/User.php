<?php

namespace App\Models;

use App\Database\Database;
use PDO;

class User {
    public static function findById(string $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.id, u.mobile_number, u.created_at, u.updated_at,
                   p.display_name, p.about, p.profile_picture, p.last_seen, p.online_status, p.device_token,
                   p.privacy_last_seen, p.privacy_profile_picture, p.privacy_about, p.privacy_online_status
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.id = :id
        ");
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function findByMobile(string $mobileNumber): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.id, u.mobile_number, u.created_at, u.updated_at,
                   p.display_name, p.about, p.profile_picture, p.last_seen, p.online_status, p.device_token,
                   p.privacy_last_seen, p.privacy_profile_picture, p.privacy_about, p.privacy_online_status
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            WHERE u.mobile_number = :mobile_number
        ");
        $stmt->execute(['mobile_number' => $mobileNumber]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function create(string $id, string $mobileNumber, string $displayName = null): bool {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // Create user
            $stmt = $db->prepare("INSERT INTO users (id, mobile_number) VALUES (:id, :mobile_number)");
            $stmt->execute(['id' => $id, 'mobile_number' => $mobileNumber]);

            // Create default profile
            $stmt = $db->prepare("
                INSERT INTO profiles (user_id, display_name) 
                VALUES (:user_id, :display_name)
            ");
            $stmt->execute([
                'user_id' => $id,
                'display_name' => $displayName ?: substr($mobileNumber, -4)
            ]);

            // Create initial online status
            $stmt = $db->prepare("
                INSERT INTO online_status (user_id, status, last_seen) 
                VALUES (:user_id, 'offline', NULL)
            ");
            $stmt->execute(['user_id' => $id]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function updateProfile(string $userId, array $data): bool {
        $db = Database::getConnection();
        
        $fields = [];
        $params = ['user_id' => $userId];

        $allowedFields = [
            'display_name', 'about', 'profile_picture', 'last_seen', 
            'online_status', 'device_token', 'privacy_last_seen', 
            'privacy_profile_picture', 'privacy_about', 'privacy_online_status'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE profiles SET " . implode(', ', $fields) . " WHERE user_id = :user_id";
        $stmt = $db->prepare($sql);
        return $stmt->execute($params);
    }

    public static function setPresence(string $userId, string $status, ?string $lastSeen = null): bool {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Update profile
            $stmt1 = $db->prepare("
                UPDATE profiles 
                SET online_status = :status, last_seen = :last_seen 
                WHERE user_id = :user_id
            ");
            $stmt1->execute([
                'user_id' => $userId,
                'status' => $status,
                'last_seen' => $lastSeen
            ]);

            // Update online_status table
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql = ($driver === 'sqlite')
                ? "INSERT INTO online_status (user_id, status, last_seen) VALUES (:user_id, :status, :last_seen) ON CONFLICT(user_id) DO UPDATE SET status = :status_update, last_seen = :last_seen_update"
                : "INSERT INTO online_status (user_id, status, last_seen) VALUES (:user_id, :status, :last_seen) ON DUPLICATE KEY UPDATE status = :status_update, last_seen = :last_seen_update";
            $stmt2 = $db->prepare($sql);
            $stmt2->execute([
                'user_id' => $userId,
                'status' => $status,
                'last_seen' => $lastSeen,
                'status_update' => $status,
                'last_seen_update' => $lastSeen
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            return false;
        }
    }

    public static function updateDeviceToken(string $userId, string $token, string $platform = 'android'): bool {
        $db = Database::getConnection();
        $db->beginTransaction();
        try {
            // Update profiles
            $stmt1 = $db->prepare("UPDATE profiles SET device_token = :token WHERE user_id = :user_id");
            $stmt1->execute(['user_id' => $userId, 'token' => $token]);

            // Update device_tokens table
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql = ($driver === 'sqlite')
                ? "INSERT INTO device_tokens (user_id, device_token, platform) VALUES (:user_id, :token, :platform) ON CONFLICT(user_id, device_token) DO UPDATE SET platform = :platform_update, updated_at = CURRENT_TIMESTAMP"
                : "INSERT INTO device_tokens (user_id, device_token, platform) VALUES (:user_id, :token, :platform) ON DUPLICATE KEY UPDATE platform = :platform_update, updated_at = CURRENT_TIMESTAMP";
            $stmt2 = $db->prepare($sql);
            $stmt2->execute([
                'user_id' => $userId, 
                'token' => $token, 
                'platform' => $platform,
                'platform_update' => $platform
            ]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            return false;
        }
    }

    public static function getDeviceTokens(string $userId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT device_token, platform FROM device_tokens WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public static function isBlocked(string $userId, string $targetUserId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT 1 FROM blocked_users 
            WHERE (user_id = :user_id AND blocked_user_id = :target_id)
               OR (user_id = :target_id_2 AND blocked_user_id = :user_id_2)
        ");
        $stmt->execute([
            'user_id' => $userId,
            'target_id' => $targetUserId,
            'user_id_2' => $userId,
            'target_id_2' => $targetUserId
        ]);
        return (bool)$stmt->fetchColumn();
    }

    public static function blockUser(string $userId, string $blockedUserId): bool {
        $db = Database::getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertKeyword = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $db->prepare("
            $insertKeyword INTO blocked_users (user_id, blocked_user_id) 
            VALUES (:user_id, :blocked_user_id)
        ");
        return $stmt->execute(['user_id' => $userId, 'blocked_user_id' => $blockedUserId]);
    }

    public static function unblockUser(string $userId, string $blockedUserId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            DELETE FROM blocked_users 
            WHERE user_id = :user_id AND blocked_user_id = :blocked_user_id
        ");
        return $stmt->execute(['user_id' => $userId, 'blocked_user_id' => $blockedUserId]);
    }
}
