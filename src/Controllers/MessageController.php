<?php

namespace App\Controllers;

use App\Database\Database;
use App\Models\Message;
use App\Models\Conversation;
use App\Models\User;
use App\Services\FCMService;
use App\Utils\Response;
use App\Utils\Validator;
use App\Utils\Logger;
use PDO;

class MessageController {

    public function sendMessage(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        $errors = Validator::validate($input, [
            'message_uuid' => 'required|uuid',
            'conversation_id' => 'required|uuid',
            'type' => 'required'
        ]);

        if (!empty($errors)) {
            Response::error('Validation Failed', 400, 4001);
        }

        $uuid = trim($input['message_uuid']);
        $conversationId = trim($input['conversation_id']);
        $type = trim($input['type']);
        $content = isset($input['content']) ? trim($input['content']) : null;
        $replyTo = isset($input['reply_to_message_id']) ? (int)$input['reply_to_message_id'] : null;
        $mediaDetails = $input['media_details'] ?? null; // array: file_path, file_name, file_size, mime_type, etc.

        // 1. Verify conversation exists and user is a member
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT 1 FROM conversation_members 
            WHERE conversation_id = :conv_id AND user_id = :user_id
        ");
        $stmt->execute(['conv_id' => $conversationId, 'user_id' => $userId]);
        if (!$stmt->fetchColumn()) {
            Response::error('Unauthorized: You are not a member of this conversation', 403, 4032);
        }

        // Check if receiver has blocked user
        $stmtRec = $db->prepare("
            SELECT user_id FROM conversation_members 
            WHERE conversation_id = :conv_id AND user_id != :user_id
        ");
        $stmtRec->execute(['conv_id' => $conversationId, 'user_id' => $userId]);
        $recipientId = $stmtRec->fetchColumn();

        if ($recipientId && User::isBlocked($recipientId, $userId)) {
            Response::error('You cannot send messages to this user', 403, 4033);
        }

        // 2. Persist message using Model
        try {
            $msgData = [
                'message_uuid' => $uuid,
                'conversation_id' => $conversationId,
                'sender_id' => $userId,
                'type' => $type,
                'content' => $content,
                'reply_to_message_id' => $replyTo,
                'forwarded' => $input['forwarded'] ?? false
            ];

            $messageId = Message::create($msgData, $mediaDetails);

            // Fetch newly created message for the response
            $newMessage = [
                'id' => $messageId,
                'message_uuid' => $uuid,
                'conversation_id' => $conversationId,
                'sender_id' => $userId,
                'type' => $type,
                'content' => $content,
                'reply_to_message_id' => $replyTo,
                'forwarded' => $input['forwarded'] ?? false,
                'media_details' => $mediaDetails,
                'created_at' => date('Y-m-d H:i:s.u')
            ];

            // 3. Send Firebase Push Notification in the background/sync
            if ($recipientId) {
                // Fetch recipient's device tokens
                $tokens = User::getDeviceTokens($recipientId);
                if (!empty($tokens)) {
                    // Send FCM notification
                    FCMService::sendNotification(
                        $tokens,
                        $userContext['mobile'] ?? 'New Message',
                        $type === 'text' ? $content : "[Media: $type]",
                        [
                            'conversation_id' => $conversationId,
                            'message_uuid' => $uuid,
                            'sender_id' => $userId,
                            'type' => $type
                        ]
                    );
                }
            }

            Response::success('Message sent successfully', ['message' => $newMessage]);

        } catch (\Exception $e) {
            Logger::error("Failed to create message: " . $e->getMessage());
            Response::error('Failed to send message: ' . $e->getMessage(), 500, 5006);
        }
    }

    public function updateMessageStatus(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        if (empty($input['message_uuid']) || empty($input['status'])) {
            Response::error('message_uuid and status parameters are required', 400, 4018);
        }

        $uuid = trim($input['message_uuid']);
        $status = trim($input['status']); // delivered, seen

        if (!in_array($status, ['delivered', 'seen'])) {
            Response::error('Invalid message status', 400, 4019);
        }

        $message = Message::findByUuid($uuid);
        if (!$message) {
            Response::error('Message not found', 404, 4043);
        }

        $updated = Message::updateStatus($message['id'], $userId, $status);
        
        if ($updated) {
            Response::success('Message status updated successfully', [
                'message_uuid' => $uuid,
                'status' => $status,
                'user_id' => $userId
            ]);
        } else {
            Response::error('Failed to update status', 500, 5007);
        }
    }

    public function reactToMessage(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        if (empty($input['message_uuid']) || empty($input['reaction'])) {
            Response::error('message_uuid and reaction parameters are required', 400, 4020);
        }

        $uuid = trim($input['message_uuid']);
        $reaction = trim($input['reaction']); // 👍, ❤️, 😂, 😮, 😢, 😡

        $message = Message::findByUuid($uuid);
        if (!$message) {
            Response::error('Message not found', 404, 4043);
        }

        $added = Message::addReaction($message['id'], $userId, $reaction);
        if ($added) {
            Response::success('Reaction saved successfully', [
                'message_uuid' => $uuid,
                'reaction' => $reaction,
                'user_id' => $userId
            ]);
        } else {
            Response::error('Failed to save reaction', 500, 5008);
        }
    }

    public function deleteMessage(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        if (empty($input['message_uuid']) || empty($input['delete_type'])) {
            Response::error('message_uuid and delete_type parameters are required', 400, 4021);
        }

        $uuid = trim($input['message_uuid']);
        $deleteType = trim($input['delete_type']); // 'me' or 'everyone'

        if (!in_array($deleteType, ['me', 'everyone'])) {
            Response::error('Invalid delete_type. Must be me or everyone.', 400, 4022);
        }

        $message = Message::findByUuid($uuid);
        if (!$message) {
            Response::error('Message not found', 404, 4043);
        }

        if ($deleteType === 'everyone') {
            if ($message['sender_id'] !== $userId) {
                Response::error('Unauthorized: You can only delete your own messages for everyone', 403, 4034);
            }
            $deleted = Message::deleteForEveryone($message['id'], $userId);
        } else {
            $deleted = Message::deleteForMe($message['id'], $userId);
        }

        if ($deleted) {
            Response::success('Message deleted successfully', [
                'message_uuid' => $uuid,
                'delete_type' => $deleteType
            ]);
        } else {
            Response::error('Failed to delete message', 500, 5009);
        }
    }

    public function deleteConversation(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        if (empty($input['conversation_id']) || !Validator::validateUuid($input['conversation_id'])) {
            Response::error('conversation_id is required and must be a valid UUID', 400, 4023);
        }

        $conversationId = trim($input['conversation_id']);

        $deleted = Conversation::deleteConversation($conversationId, $userId);
        if ($deleted) {
            Response::success('Conversation deleted successfully', [
                'conversation_id' => $conversationId
            ]);
        } else {
            Response::error('Failed to delete conversation', 500, 5010);
        }
    }
}
