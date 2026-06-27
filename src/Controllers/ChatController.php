<?php

namespace App\Controllers;

use App\Database\Database;
use App\Models\Conversation;
use App\Models\User;
use App\Utils\Response;
use App\Utils\Validator;
use PDO;

class ChatController {
    
    public function startChat(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        if (empty($input['receiver_id']) && empty($input['receiver_phone'])) {
            Response::error('Either receiver_id or receiver_phone is required', 400, 4010);
        }

        $receiverId = trim($input['receiver_id'] ?? '');
        $receiverPhone = trim($input['receiver_phone'] ?? '');

        // 1. Verify receiver exists
        $receiver = null;
        if (!empty($receiverId)) {
            $receiver = User::findById($receiverId);
        } elseif (!empty($receiverPhone)) {
            $receiver = User::findByMobile($receiverPhone);
        }

        if (!$receiver) {
            Response::error('User is not registered', 404, 4042);
        }

        $receiverId = $receiver['id'];

        if ($userId === $receiverId) {
            Response::error('You cannot start a chat with yourself', 400, 4011);
        }

        // 2. Check if user is blocked
        if (User::isBlocked($userId, $receiverId)) {
            Response::error('Unable to start chat with this user', 403, 4031);
        }

        // 3. Find or create conversation
        $conversation = Conversation::findBetweenUsers($userId, $receiverId);

        if (!$conversation) {
            $conversationId = self::generateUuid();
            Conversation::create($conversationId, $userId, $receiverId);
            $conversation = Conversation::findById($conversationId, $userId);
        }

        Response::success('Chat initialized successfully', ['conversation' => $conversation]);
    }

    public function getConversations(array $userContext): void {
        $userId = $userContext['sub'];
        $conversations = Conversation::getUserConversations($userId);
        Response::success('Conversations retrieved successfully', ['conversations' => $conversations]);
    }

    public function getMessages(array $userContext): void {
        $userId = $userContext['sub'];
        
        $conversationId = $_GET['conversation_id'] ?? '';
        $limit = (int)($_GET['limit'] ?? 50);
        $beforeId = isset($_GET['before_id']) ? (int)$_GET['before_id'] : null;

        if (empty($conversationId) || !Validator::validateUuid($conversationId)) {
            Response::error('Valid conversation_id is required', 400, 4012);
        }

        $db = Database::getConnection();

        // Verify membership
        $stmt = $db->prepare("
            SELECT 1 FROM conversation_members 
            WHERE conversation_id = :conv_id AND user_id = :user_id
        ");
        $stmt->execute(['conv_id' => $conversationId, 'user_id' => $userId]);
        if (!$stmt->fetchColumn()) {
            Response::error('Unauthorized: You are not a member of this conversation', 403, 4032);
        }

        // Fetch messages, excluding those deleted by this user
        $sql = "
            SELECT m.id, m.message_uuid, m.sender_id, m.type, m.content, m.reply_to_message_id, m.forwarded, m.created_at,
                   mm.file_path, mm.file_name, mm.file_size, mm.mime_type, mm.duration, mm.width, mm.height, mm.thumbnail_path, mm.waveform,
                   ms.status AS delivery_status,
                   GROUP_CONCAT(CONCAT(mr.user_id, ':', mr.reaction)) AS reactions
            FROM messages m
            LEFT JOIN message_media mm ON m.id = mm.message_id
            LEFT JOIN message_status ms ON m.id = ms.message_id AND ms.user_id != m.sender_id
            LEFT JOIN message_reactions mr ON m.id = mr.message_id
            LEFT JOIN deleted_messages dm ON m.id = dm.message_id AND dm.user_id = :user_id
            WHERE m.conversation_id = :conv_id AND dm.id IS NULL
        ";

        $params = [
            'conv_id' => $conversationId,
            'user_id' => $userId
        ];

        if ($beforeId) {
            $sql .= " AND m.id < :before_id";
            $params['before_id'] = $beforeId;
        }

        $sql .= " GROUP BY m.id ORDER BY m.id DESC LIMIT :limit";
        
        $stmt = $db->prepare($sql);
        // Note: BindParam is used to ensure limit remains integer when prepared
        $stmt->bindValue(':conv_id', $conversationId, PDO::PARAM_STR);
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_STR);
        if ($beforeId) {
            $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $messages = $stmt->fetchAll();

        // Format reactions and media details nicely
        foreach ($messages as &$msg) {
            $msg['reply_to_message'] = null;
            if ($msg['reply_to_message_id']) {
                $stmtReply = $db->prepare("SELECT id, message_uuid, sender_id, type, content FROM messages WHERE id = ?");
                $stmtReply->execute([$msg['reply_to_message_id']]);
                $msg['reply_to_message'] = $stmtReply->fetch() ?: null;
            }

            $reactionsArr = [];
            if ($msg['reactions']) {
                $parts = explode(',', $msg['reactions']);
                foreach ($parts as $p) {
                    $pair = explode(':', $p);
                    if (count($pair) === 2) {
                        $reactionsArr[] = [
                            'user_id' => $pair[0],
                            'reaction' => $pair[1]
                        ];
                    }
                }
            }
            $msg['reactions'] = $reactionsArr;
            $msg['forwarded'] = (bool)$msg['forwarded'];
        }

        // Reset user's unread count for this conversation when pulling messages
        Conversation::resetUnreadCount($conversationId, $userId);

        Response::success('Messages retrieved successfully', [
            'messages' => array_reverse($messages)
        ]);
    }

    public function searchMessages(array $userContext): void {
        $userId = $userContext['sub'];
        $query = trim($_GET['query'] ?? '');
        $type = trim($_GET['type'] ?? ''); // text, image, video, voice, doc, gif, location
        
        if (empty($query) && empty($type)) {
            Response::error('Search query or type parameter is required', 400, 4013);
        }

        $db = Database::getConnection();

        // Search messages in conversations where the user is a member, excluding those deleted by the user
        $sql = "
            SELECT m.id, m.message_uuid, m.conversation_id, m.sender_id, m.type, m.content, m.created_at,
                   mm.file_path, mm.file_name, mm.file_size, mm.mime_type
            FROM messages m
            JOIN conversation_members cm ON m.conversation_id = cm.conversation_id AND cm.user_id = :user_id
            LEFT JOIN message_media mm ON m.id = mm.message_id
            LEFT JOIN deleted_messages dm ON m.id = dm.message_id AND dm.user_id = :user_id
            WHERE dm.id IS NULL
        ";

        $params = ['user_id' => $userId];

        if (!empty($query)) {
            $sql .= " AND (m.content LIKE :query OR mm.file_name LIKE :query)";
            $params['query'] = '%' . $query . '%';
        }

        if (!empty($type)) {
            $sql .= " AND m.type = :type";
            $params['type'] = $type;
        }

        $sql .= " ORDER BY m.id DESC LIMIT 100";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll();

        Response::success('Search results retrieved successfully', ['results' => $results]);
    }

    private static function generateUuid(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
