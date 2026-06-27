<?php

namespace App\Models;

use App\Database\Database;
use PDO;

class Conversation {
    
    public static function findBetweenUsers(string $user1, string $user2): ?array {
        $db = Database::getConnection();
        // Since it is 1-to-1, we look for a conversation ID that has exactly both users as members
        $stmt = $db->prepare("
            SELECT cm1.conversation_id 
            FROM conversation_members cm1
            JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
            WHERE cm1.user_id = :user1 AND cm2.user_id = :user2
            LIMIT 1
        ");
        $stmt->execute(['user1' => $user1, 'user2' => $user2]);
        $conversationId = $stmt->fetchColumn();

        if ($conversationId) {
            return self::findById($conversationId, $user1);
        }

        return null;
    }

    public static function findById(string $id, string $requestingUserId): ?array {
        $db = Database::getConnection();
        
        // Fetch conversation and the other member's profile
        $stmt = $db->prepare("
            SELECT c.id AS conversation_id, c.created_at, c.updated_at,
                   cm.unread_count, cm.last_synced_message_id,
                   other_u.id AS partner_id, other_p.display_name AS partner_name,
                   other_p.profile_picture AS partner_avatar, other_p.online_status AS partner_status,
                   other_p.last_seen AS partner_last_seen
            FROM conversations c
            JOIN conversation_members cm ON c.id = cm.conversation_id AND cm.user_id = :user_id
            JOIN conversation_members other_cm ON c.id = other_cm.conversation_id AND other_cm.user_id != :user_id_2
            JOIN users other_u ON other_cm.user_id = other_u.id
            JOIN profiles other_p ON other_u.id = other_p.user_id
            WHERE c.id = :id
        ");
        $stmt->execute(['id' => $id, 'user_id' => $requestingUserId, 'user_id_2' => $requestingUserId]);
        $conversation = $stmt->fetch();

        if ($conversation) {
            // Fetch last message in the conversation
            $lastMsg = self::getLastMessage($id, $requestingUserId);
            $conversation['last_message'] = $lastMsg;
            return $conversation;
        }

        return null;
    }

    public static function create(string $id, string $user1, string $user2): bool {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // Create conversation container
            $stmt = $db->prepare("INSERT INTO conversations (id) VALUES (:id)");
            $stmt->execute(['id' => $id]);

            // Add members
            $stmt = $db->prepare("
                INSERT INTO conversation_members (conversation_id, user_id, last_synced_message_id, unread_count) 
                VALUES (:conversation_id, :user_id, 0, 0)
            ");
            
            $stmt->execute(['conversation_id' => $id, 'user_id' => $user1]);
            $stmt->execute(['conversation_id' => $id, 'user_id' => $user2]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function getUserConversations(string $userId): array {
        $db = Database::getConnection();
        
        $stmt = $db->prepare("
            SELECT c.id AS conversation_id, c.created_at, c.updated_at,
                   cm.unread_count, cm.last_synced_message_id,
                   other_u.id AS partner_id, other_p.display_name AS partner_name,
                   other_p.profile_picture AS partner_avatar, other_p.online_status AS partner_status,
                   other_p.last_seen AS partner_last_seen
            FROM conversations c
            JOIN conversation_members cm ON c.id = cm.conversation_id AND cm.user_id = :user_id
            JOIN conversation_members other_cm ON c.id = other_cm.conversation_id AND other_cm.user_id != :user_id_2
            JOIN users other_u ON other_cm.user_id = other_u.id
            JOIN profiles other_p ON other_u.id = other_p.user_id
            ORDER BY c.updated_at DESC
        ");
        $stmt->execute(['user_id' => $userId, 'user_id_2' => $userId]);
        $conversations = $stmt->fetchAll();

        foreach ($conversations as &$conv) {
            $conv['last_message'] = self::getLastMessage($conv['conversation_id'], $userId);
        }

        return $conversations;
    }

    public static function getLastMessage(string $conversationId, string $userId): ?array {
        $db = Database::getConnection();
        // Return last message that has not been deleted by the user
        $stmt = $db->prepare("
            SELECT m.id, m.message_uuid, m.sender_id, m.type, m.content, m.created_at
            FROM messages m
            LEFT JOIN deleted_messages dm ON m.id = dm.message_id AND dm.user_id = :user_id
            WHERE m.conversation_id = :conversation_id AND dm.id IS NULL
            ORDER BY m.id DESC LIMIT 1
        ");
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId
        ]);
        $msg = $stmt->fetch();
        return $msg ?: null;
    }

    public static function incrementUnreadCount(string $conversationId, string $recipientId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE conversation_members 
            SET unread_count = unread_count + 1 
            WHERE conversation_id = :conversation_id AND user_id = :recipient_id
        ");
        $stmt->execute([
            'conversation_id' => $conversationId,
            'recipient_id' => $recipientId
        ]);
    }

    public static function resetUnreadCount(string $conversationId, string $userId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            UPDATE conversation_members 
            SET unread_count = 0 
            WHERE conversation_id = :conversation_id AND user_id = :user_id
        ");
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId
        ]);
    }

    public static function updateLastSyncedId(string $conversationId, string $userId, int $lastMessageId): void {
        $db = Database::getConnection();
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $greatestFunc = ($driver === 'sqlite') ? 'MAX' : 'GREATEST';
        $stmt = $db->prepare("
            UPDATE conversation_members 
            SET last_synced_message_id = {$greatestFunc}(last_synced_message_id, :last_msg_id)
            WHERE conversation_id = :conversation_id AND user_id = :user_id
        ");
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'last_msg_id' => $lastMessageId
        ]);
    }

    public static function deleteConversation(string $conversationId, string $userId): bool {
        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // WhatsApp-like delete: delete for me
            // Find all messages in the conversation
            $stmt = $db->prepare("SELECT id FROM messages WHERE conversation_id = :conversation_id");
            $stmt->execute(['conversation_id' => $conversationId]);
            $msgIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (!empty($msgIds)) {
                $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
                $insertKeyword = ($driver === 'sqlite') ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
                $insertDeleted = $db->prepare("
                    $insertKeyword INTO deleted_messages (message_id, user_id, delete_type) 
                    VALUES (:message_id, :user_id, 'me')
                ");
                foreach ($msgIds as $id) {
                    $insertDeleted->execute(['message_id' => $id, 'user_id' => $userId]);
                }
            }

            // Check if the other member has also deleted all messages.
            // In a pure WhatsApp implementation, a delete chat clears the history locally.
            // On the server, we remove the conversation if both users delete it.
            // For now, let's keep it simple: clear the user's unread counts.
            $stmt = $db->prepare("
                UPDATE conversation_members 
                SET unread_count = 0, last_synced_message_id = 0
                WHERE conversation_id = :conversation_id AND user_id = :user_id
            ");
            $stmt->execute(['conversation_id' => $conversationId, 'user_id' => $userId]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            return false;
        }
    }
}
