<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\FCMService;
use App\Utils\Response;
use PDO;

class AdminController {

    public function getStats(array $userContext): void {
        // In a real application, check if requesting user is admin
        $db = Database::getConnection();

        // 1. Total users
        $totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();

        // 2. Online users
        $onlineUsers = (int)$db->query("SELECT COUNT(*) FROM profiles WHERE online_status = 'online'")->fetchColumn();

        // 3. Message count
        $totalMessages = (int)$db->query("SELECT COUNT(*) FROM messages")->fetchColumn();

        // 4. Media uploads count & size
        $mediaStats = $db->query("SELECT COUNT(*) AS count, SUM(file_size) AS size FROM media_uploads")->fetch();
        $totalMedia = (int)($mediaStats['count'] ?? 0);
        $totalMediaSize = (int)($mediaStats['size'] ?? 0);
        $totalMediaSizeMb = round($totalMediaSize / (1024 * 1024), 2);

        // 5. Daily Active Users (DAU - users who sent a message or updated status in last 24h)
        $dau = (int)$db->query("
            SELECT COUNT(DISTINCT user_id) FROM (
                SELECT sender_id AS user_id FROM messages WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
                UNION
                SELECT user_id FROM profiles WHERE last_seen > DATE_SUB(NOW(), INTERVAL 1 DAY)
            ) as active
        ")->fetchColumn();

        // 6. Reports (mock list of user reports or recent logs)
        $reports = [
            ['id' => 1, 'reporter' => 'Alice', 'reported_user' => 'SpammerBob', 'reason' => 'Spamming links', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]
        ];

        Response::success('Admin statistics retrieved successfully', [
            'total_users' => $totalUsers,
            'online_users' => $onlineUsers,
            'total_messages' => $totalMessages,
            'total_media_count' => $totalMedia,
            'total_media_storage_mb' => $totalMediaSizeMb,
            'daily_active_users' => $dau,
            'reports' => $reports
        ]);
    }

    public function getUsers(array $userContext): void {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT u.id, u.mobile_number, u.created_at,
                   p.display_name, p.about, p.profile_picture, p.online_status, p.last_seen
            FROM users u
            LEFT JOIN profiles p ON u.id = p.user_id
            ORDER BY u.created_at DESC
        ");
        $users = $stmt->fetchAll();
        Response::success('Users list retrieved successfully', ['users' => $users]);
    }

    public function broadcastNotification(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($input['title']) || empty($input['body'])) {
            Response::error('Title and body are required for broadcasting', 400, 4024);
        }

        $title = trim($input['title']);
        $body = trim($input['body']);

        $db = Database::getConnection();
        // Fetch all active device tokens
        $stmt = $db->query("SELECT device_token, platform FROM device_tokens");
        $tokens = $stmt->fetchAll();

        if (empty($tokens)) {
            Response::success('No device tokens registered. Broadcast completed.', ['sent_count' => 0]);
        }

        $sent = FCMService::sendNotification($tokens, $title, $body, [
            'type' => 'broadcast',
            'click_action' => 'MAIN_ACTIVITY'
        ]);

        if ($sent) {
            Response::success('Broadcast sent successfully', ['sent_count' => count($tokens)]);
        } else {
            Response::error('Failed to send broadcast notifications completely', 500, 5011);
        }
    }
}
