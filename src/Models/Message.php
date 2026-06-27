<?php

namespace App\Models;

use App\Database\Database;
use PDO;

class Message {
    
    public static function create(array $data, ?array $media = null): int {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // 1. Insert core message
            $stmt = $db->prepare("
                INSERT INTO messages (message_uuid, conversation_id, sender_id, type, content, reply_to_message_id, forwarded) 
                VALUES (:uuid, :conv_id, :sender_id, :type, :content, :reply_to, :forwarded)
            ");
            $stmt->execute([
                'uuid' => $data['message_uuid'],
                'conv_id' => $data['conversation_id'],
                'sender_id' => $data['sender_id'],
                'type' => $data['type'],
                'content' => $data['content'] ?? null,
                'reply_to' => $data['reply_to_message_id'] ?? null,
                'forwarded' => $data['forwarded'] ? 1 : 0
            ]);

            $messageId = (int)$db->lastInsertId();

            // 2. Insert media details if present
            if ($media && !empty($media['file_path'])) {
                $stmtMedia = $db->prepare("
                    INSERT INTO message_media (message_id, file_path, file_name, file_size, mime_type, duration, width, height, thumbnail_path, waveform, download_status) 
                    VALUES (:msg_id, :path, :name, :size, :mime, :duration, :width, :height, :thumb, :waveform, 'completed')
                ");
                $stmtMedia->execute([
                    'msg_id' => $messageId,
                    'path' => $media['file_path'],
                    'name' => $media['file_name'],
                    'size' => $media['file_size'],
                    'mime' => $media['mime_type'] ?? null,
                    'duration' => $media['duration'] ?? null,
                    'width' => $media['width'] ?? null,
                    'height' => $media['height'] ?? null,
                    'thumb' => $media['thumbnail_path'] ?? null,
                    'waveform' => $media['waveform'] ?? null
                ]);
            }

            // 3. Initialize message_status for recipient(s)
            // Fetch recipient ID
            $stmtMembers = $db->prepare("
                SELECT user_id FROM conversation_members 
                WHERE conversation_id = :conv_id AND user_id != :sender_id
            ");
            $stmtMembers->execute([
                'conv_id' => $data['conversation_id'],
                'sender_id' => $data['sender_id']
            ]);
            $recipientId = $stmtMembers->fetchColumn();

            if ($recipientId) {
                $stmtStatus = $db->prepare("
                    INSERT INTO message_status (message_id, user_id, status) 
                    VALUES (:msg_id, :user_id, 'sent')
                ");
                $stmtStatus->execute([
                    'msg_id' => $messageId,
                    'user_id' => $recipientId
                ]);

                // Increment recipient's unread counts
                Conversation::incrementUnreadCount($data['conversation_id'], $recipientId);
            }

            // 4. Update conversation updated_at
            $stmtConv = $db->prepare("UPDATE conversations SET updated_at = CURRENT_TIMESTAMP WHERE id = :conv_id");
            $stmtConv->execute(['conv_id' => $data['conversation_id']]);

            $db->commit();
            return $messageId;

        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function findByUuid(string $uuid): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT m.id, m.message_uuid, m.conversation_id, m.sender_id, m.type, m.content, m.created_at,
                   mm.file_path, mm.file_name, mm.file_size, mm.mime_type
            FROM messages m
            LEFT JOIN message_media mm ON m.id = mm.message_id
            WHERE m.message_uuid = :uuid
        ");
        $stmt->execute(['uuid' => $uuid]);
        $msg = $stmt->fetch();
        return $msg ?: null;
    }

    public static function updateStatus(int $messageId, string $userId, string $status): bool {
        $db = Database::getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = ($driver === 'sqlite')
            ? "INSERT INTO message_status (message_id, user_id, status) VALUES (:msg_id, :user_id, :status) ON CONFLICT(message_id, user_id) DO UPDATE SET status = :status, updated_at = CURRENT_TIMESTAMP"
            : "INSERT INTO message_status (message_id, user_id, status) VALUES (:msg_id, :user_id, :status) ON DUPLICATE KEY UPDATE status = :status, updated_at = CURRENT_TIMESTAMP";
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            'msg_id' => $messageId,
            'user_id' => $userId,
            'status' => $status
        ]);
    }

    public static function addReaction(int $messageId, string $userId, string $reaction): bool {
        $db = Database::getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = ($driver === 'sqlite')
            ? "INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (:msg_id, :user_id, :reaction) ON CONFLICT(message_id, user_id) DO UPDATE SET reaction = :reaction, created_at = CURRENT_TIMESTAMP"
            : "INSERT INTO message_reactions (message_id, user_id, reaction) VALUES (:msg_id, :user_id, :reaction) ON DUPLICATE KEY UPDATE reaction = :reaction, created_at = CURRENT_TIMESTAMP";
        $stmt = $db->prepare($sql);
        return $stmt->execute([
            'msg_id' => $messageId,
            'user_id' => $userId,
            'reaction' => $reaction
        ]);
    }

    public static function deleteForMe(int $messageId, string $userId): bool {
        $db = Database::getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insertKeyword = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $stmt = $db->prepare("
            $insertKeyword INTO deleted_messages (message_id, user_id, delete_type) 
            VALUES (:msg_id, :user_id, 'me')
        ");
        return $stmt->execute([
            'msg_id' => $messageId,
            'user_id' => $userId
        ]);
    }

    public static function deleteForEveryone(int $messageId, string $userId): bool {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // Verify ownership
            $stmt = $db->prepare("SELECT sender_id, conversation_id FROM messages WHERE id = :id");
            $stmt->execute(['id' => $messageId]);
            $msgInfo = $stmt->fetch();
            
            if (!$msgInfo || $msgInfo['sender_id'] !== $userId) {
                $db->rollBack();
                return false;
            }

            // 1. Fetch media path to delete files
            $stmtMedia = $db->prepare("SELECT file_path, thumbnail_path FROM message_media WHERE message_id = :msg_id");
            $stmtMedia->execute(['msg_id' => $messageId]);
            $media = $stmtMedia->fetch();

            if ($media) {
                $base = dirname(__DIR__, 2) . '/public/';
                if (!empty($media['file_path']) && file_exists($base . $media['file_path'])) {
                    unlink($base . $media['file_path']);
                }
                if (!empty($media['thumbnail_path']) && file_exists($base . $media['thumbnail_path'])) {
                    unlink($base . $media['thumbnail_path']);
                }
                // Delete media DB entry
                $stmtDelMedia = $db->prepare("DELETE FROM message_media WHERE message_id = :msg_id");
                $stmtDelMedia->execute(['msg_id' => $messageId]);
            }

            // 2. Soft delete: update content/type in DB to prevent broken sequence but scrub trace
            $stmtUpdate = $db->prepare("
                UPDATE messages 
                SET content = NULL, type = 'deleted', updated_at = CURRENT_TIMESTAMP(6) 
                WHERE id = :id
            ");
            $stmtUpdate->execute(['id' => $messageId]);

            // 3. Insert deletion trace for members in deleted_messages so that it syncs
            // Fetch recipient ID
            $stmtMembers = $db->prepare("
                SELECT user_id FROM conversation_members 
                WHERE conversation_id = :conv_id AND user_id != :sender_id
            ");
            $stmtMembers->execute([
                'conv_id' => $msgInfo['conversation_id'],
                'sender_id' => $userId
            ]);
            $recipientId = $stmtMembers->fetchColumn();

            if ($recipientId) {
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                $sql = ($driver === 'sqlite')
                    ? "INSERT INTO deleted_messages (message_id, user_id, delete_type) VALUES (:msg_id, :user_id, 'everyone') ON CONFLICT(message_id, user_id) DO UPDATE SET delete_type = 'everyone', deleted_at = CURRENT_TIMESTAMP"
                    : "INSERT INTO deleted_messages (message_id, user_id, delete_type) VALUES (:msg_id, :user_id, 'everyone') ON DUPLICATE KEY UPDATE delete_type = 'everyone', deleted_at = CURRENT_TIMESTAMP";
                $stmtDelTrace = $db->prepare($sql);
                $stmtDelTrace->execute([
                    'msg_id' => $messageId,
                    'user_id' => $recipientId
                ]);
            }

            $db->commit();
            return true;

        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
