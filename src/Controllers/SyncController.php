<?php

namespace App\Controllers;

use App\Database\Database;
use App\Models\Conversation;
use App\Utils\Response;
use App\Utils\Validator;
use PDO;

class SyncController {

    public function sync(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        $syncItems = $input['sync_items'] ?? [];
        // last_sync_time should be ISO 8601 or standard SQL format, e.g. "2026-06-27 19:35:24.000000"
        $lastSyncTime = trim($input['last_sync_time'] ?? '');

        if (!is_array($syncItems)) {
            Response::error('sync_items must be an array of objects containing conversation_id and last_message_id', 400, 4014);
        }

        $db = Database::getConnection();

        // If no items, just sync changes since lastSyncTime for all user conversations
        if (empty($syncItems)) {
            // Get all user conversation IDs to build list
            $stmt = $db->prepare("SELECT conversation_id FROM conversation_members WHERE user_id = ?");
            $stmt->execute([$userId]);
            $convIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($convIds as $cid) {
                $syncItems[] = [
                    'conversation_id' => $cid,
                    'last_message_id' => 0
                ];
            }
        }

        $responseItems = [];
        $hasUpdates = false;

        // Collect new messages for each requested conversation
        foreach ($syncItems as $item) {
            $convId = trim($item['conversation_id'] ?? '');
            $lastMsgId = (int)($item['last_message_id'] ?? 0);

            if (empty($convId) || !Validator::validateUuid($convId)) {
                continue;
            }

            // Verify membership
            $stmt = $db->prepare("
                SELECT 1 FROM conversation_members 
                WHERE conversation_id = :conv_id AND user_id = :user_id
            ");
            $stmt->execute(['conv_id' => $convId, 'user_id' => $userId]);
            if (!$stmt->fetchColumn()) {
                continue; // Skip conversations they are not in
            }

            // Get new messages where id > lastMsgId, excluding messages deleted for this user
            $sql = "
                SELECT m.id, m.message_uuid, m.conversation_id, m.sender_id, m.type, m.content, m.reply_to_message_id, m.forwarded, m.created_at,
                       mm.file_path, mm.file_name, mm.file_size, mm.mime_type, mm.duration, mm.width, mm.height, mm.thumbnail_path, mm.waveform,
                       ms.status AS delivery_status
                FROM messages m
                LEFT JOIN message_media mm ON m.id = mm.message_id
                LEFT JOIN message_status ms ON m.id = ms.message_id AND ms.user_id != m.sender_id
                LEFT JOIN deleted_messages dm ON m.id = dm.message_id AND dm.user_id = :user_id
                WHERE m.conversation_id = :conv_id AND m.id > :last_msg_id AND dm.id IS NULL
                ORDER BY m.id ASC
            ";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                'conv_id' => $convId,
                'last_msg_id' => $lastMsgId,
                'user_id' => $userId
            ]);
            $newMessages = $stmt->fetchAll();

            // Format replies
            foreach ($newMessages as &$msg) {
                $msg['reply_to_message'] = null;
                if ($msg['reply_to_message_id']) {
                    $stmtReply = $db->prepare("SELECT id, message_uuid, sender_id, type, content FROM messages WHERE id = ?");
                    $stmtReply->execute([$msg['reply_to_message_id']]);
                    $msg['reply_to_message'] = $stmtReply->fetch() ?: null;
                }
                $msg['forwarded'] = (bool)$msg['forwarded'];
            }

            if (!empty($newMessages)) {
                $hasUpdates = true;
                // Update local synced status metadata on the server
                $maxId = end($newMessages)['id'];
                Conversation::updateLastSyncedId($convId, $userId, $maxId);
            }

            $responseItems[$convId] = [
                'new_messages' => $newMessages,
                'latest_message_id' => !empty($newMessages) ? end($newMessages)['id'] : $lastMsgId
            ];
        }

        // Fetch changes that occurred after lastSyncTime (e.g. read status ticks, deletions, reactions)
        $deletions = [];
        $statusUpdates = [];
        $reactions = [];

        if (!empty($lastSyncTime)) {
            // 1. Fetch deletions (Delete for Everyone or Delete for Me) since lastSyncTime
            $stmt = $db->prepare("
                SELECT dm.message_id, m.message_uuid, dm.delete_type, dm.user_id
                FROM deleted_messages dm
                JOIN messages m ON dm.message_id = m.id
                JOIN conversation_members cm ON m.conversation_id = cm.conversation_id AND cm.user_id = :user_id
                WHERE dm.deleted_at > :last_sync
            ");
            $stmt->execute(['user_id' => $userId, 'last_sync' => $lastSyncTime]);
            $deletions = $stmt->fetchAll();

            // 2. Fetch updated read ticks since lastSyncTime
            $stmt = $db->prepare("
                SELECT ms.message_id, m.message_uuid, ms.user_id, ms.status, ms.updated_at
                FROM message_status ms
                JOIN messages m ON ms.message_id = m.id
                JOIN conversation_members cm ON m.conversation_id = cm.conversation_id AND cm.user_id = :user_id
                WHERE ms.updated_at > :last_sync AND ms.user_id != m.sender_id
            ");
            $stmt->execute(['user_id' => $userId, 'last_sync' => $lastSyncTime]);
            $statusUpdates = $stmt->fetchAll();

            // 3. Fetch reactions added since lastSyncTime
            $stmt = $db->prepare("
                SELECT mr.message_id, m.message_uuid, mr.user_id, mr.reaction, mr.created_at
                FROM message_reactions mr
                JOIN messages m ON mr.message_id = m.id
                JOIN conversation_members cm ON m.conversation_id = cm.conversation_id AND cm.user_id = :user_id
                WHERE mr.created_at > :last_sync
            ");
            $stmt->execute(['user_id' => $userId, 'last_sync' => $lastSyncTime]);
            $reactions = $stmt->fetchAll();

            if (!empty($deletions) || !empty($statusUpdates) || !empty($reactions)) {
                $hasUpdates = true;
            }
        }

        // If no updates at all, return HTTP 200 with status message
        if (!$hasUpdates && !empty($lastSyncTime)) {
            Response::success('No Updates', [
                'sync_items' => $responseItems,
                'deletions' => [],
                'status_updates' => [],
                'reactions' => [],
                'server_time' => date('Y-m-d H:i:s.u')
            ]);
        }

        Response::success('Sync completed successfully', [
            'sync_items' => $responseItems,
            'deletions' => $deletions,
            'status_updates' => $statusUpdates,
            'reactions' => $reactions,
            'server_time' => date('Y-m-d H:i:s.u')
        ]);
    }
}
