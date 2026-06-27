<?php

namespace App\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use App\WebSocket\ConnectionManager;
use App\Models\User;
use App\Models\Message;
use App\Models\Conversation;
use App\Services\FCMService;
use App\Database\Database;
use App\Utils\Logger;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use PDO;

class ChatHandler implements MessageComponentInterface {
    protected ConnectionManager $manager;
    protected array $jwtConfig = [];

    public function __construct() {
        $this->manager = new ConnectionManager();
        
        // Load dotenv config
        if (!getenv('JWT_SECRET')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }
        $this->jwtConfig['secret'] = $_ENV['JWT_SECRET'] ?? 'super_secret_access_token_key_12345';
    }

    public function onOpen(ConnectionInterface $conn) {
        // Authenticate from WebSocket connection parameters (e.g. ws://localhost:8080?token=JWT_TOKEN)
        $query = $conn->httpRequest->getUri()->getQuery();
        parse_str($query, $queryParams);
        $token = $queryParams['token'] ?? null;

        if (!$token) {
            Logger::warning("WebSocket connection rejected: token missing.");
            $conn->send(json_encode(['event' => 'error', 'message' => 'Unauthorized: token missing']));
            $conn->close();
            return;
        }

        try {
            $decoded = JWT::decode($token, new Key($this->jwtConfig['secret'], 'HS256'));
            $userId = $decoded->sub;

            // Map connection
            $this->manager->addConnection($userId, $conn);
            
            // Set user online in database
            User::setPresence($userId, 'online');
            
            // Broadcast user online status
            $this->broadcastPresence($userId, 'online');

            Logger::info("WebSocket client connected. User: $userId. Connection: {$conn->resourceId}");
            
            $conn->send(json_encode([
                'event' => 'authenticated',
                'user_id' => $userId,
                'status' => 'online'
            ]));
        } catch (\Exception $e) {
            Logger::error("WebSocket connection authentication failed: " . $e->getMessage());
            $conn->send(json_encode(['event' => 'error', 'message' => 'Unauthorized: token invalid']));
            $conn->close();
        }
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        $connId = $from->resourceId;
        $userId = $this->getUserIdByConn($from);

        if (!$userId) {
            $from->send(json_encode(['event' => 'error', 'message' => 'Unauthenticated connection']));
            $from->close();
            return;
        }

        $data = json_decode($msg, true);
        if (!$data || empty($data['event'])) {
            $from->send(json_encode(['event' => 'error', 'message' => 'Invalid payload format']));
            return;
        }

        $event = $data['event'];
        Logger::info("WebSocket event received: '$event' from user: $userId");

        switch ($event) {
            case 'typing':
            case 'stop_typing':
                $this->handleTypingEvent($userId, $data);
                break;
            case 'message':
                $this->handleMessageEvent($userId, $data, $from);
                break;
            case 'status_update':
                $this->handleStatusUpdateEvent($userId, $data);
                break;
            case 'reaction':
                $this->handleReactionEvent($userId, $data);
                break;
            case 'delete':
                $this->handleDeleteEvent($userId, $data);
                break;
            case 'ping':
                $from->send(json_encode(['event' => 'pong']));
                break;
            default:
                $from->send(json_encode(['event' => 'error', 'message' => "Unsupported event: '$event'"]));
                break;
        }
    }

    public function onClose(ConnectionInterface $conn) {
        $userId = $this->manager->removeConnection($conn);
        if ($userId) {
            $now = date('Y-m-d H:i:s');
            // Update last seen presence in database
            User::setPresence($userId, 'offline', $now);
            // Broadcast presence
            $this->broadcastPresence($userId, 'offline', $now);
            Logger::info("WebSocket client disconnected. User: $userId. Connection: {$conn->resourceId}");
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        Logger::error("WebSocket error: " . $e->getMessage());
        $conn->close();
    }

    // --- Event Handlers ---

    private function handleTypingEvent(string $userId, array $data): void {
        $convId = $data['conversation_id'] ?? '';
        if (empty($convId)) return;

        // Retrieve partner ID
        $recipientId = $this->getRecipientId($convId, $userId);
        if ($recipientId) {
            $this->manager->sendToUser($recipientId, [
                'event' => $data['event'], // typing or stop_typing
                'conversation_id' => $convId,
                'user_id' => $userId
            ]);
        }
    }

    private function handleMessageEvent(string $userId, array $data, ConnectionInterface $from): void {
        $uuid = $data['message_uuid'] ?? '';
        $convId = $data['conversation_id'] ?? '';
        $type = $data['type'] ?? 'text';
        $content = $data['content'] ?? null;
        $replyTo = $data['reply_to_message_id'] ?? null;
        $mediaDetails = $data['media_details'] ?? null;
        $forwarded = $data['forwarded'] ?? false;

        if (empty($uuid) || empty($convId)) {
            $from->send(json_encode(['event' => 'error', 'message' => 'message_uuid and conversation_id required']));
            return;
        }

        $recipientId = $this->getRecipientId($convId, $userId);

        if ($recipientId && User::isBlocked($recipientId, $userId)) {
            $from->send(json_encode(['event' => 'error', 'message' => 'Blocked by recipient']));
            return;
        }

        try {
            // Save to DB
            $msgData = [
                'message_uuid' => $uuid,
                'conversation_id' => $convId,
                'sender_id' => $userId,
                'type' => $type,
                'content' => $content,
                'reply_to_message_id' => $replyTo,
                'forwarded' => $forwarded
            ];
            
            $messageId = Message::create($msgData, $mediaDetails);

            $payload = [
                'event' => 'message',
                'id' => $messageId,
                'message_uuid' => $uuid,
                'conversation_id' => $convId,
                'sender_id' => $userId,
                'type' => $type,
                'content' => $content,
                'reply_to_message_id' => $replyTo,
                'forwarded' => (bool)$forwarded,
                'media_details' => $mediaDetails,
                'created_at' => date('Y-m-d H:i:s.u')
            ];

            // Send confirmation back to sender
            $from->send(json_encode([
                'event' => 'message_ack',
                'message_uuid' => $uuid,
                'id' => $messageId,
                'status' => 'sent'
            ]));

            // Deliver to recipient in real time
            if ($recipientId) {
                $isDelivered = $this->manager->sendToUser($recipientId, $payload);
                if ($isDelivered) {
                    // Update to delivered status
                    Message::updateStatus($messageId, $recipientId, 'delivered');
                    
                    // Notify sender of delivery tick
                    $from->send(json_encode([
                        'event' => 'status_update',
                        'message_uuid' => $uuid,
                        'status' => 'delivered',
                        'user_id' => $recipientId
                    ]));
                } else {
                    // Recipient is offline, send FCM push notification
                    $tokens = User::getDeviceTokens($recipientId);
                    if (!empty($tokens)) {
                        $sender = User::findById($userId);
                        $senderName = $sender['display_name'] ?? $sender['mobile_number'] ?? 'New Message';
                        $body = $type === 'text' ? $content : "[Media: $type]";
                        
                        FCMService::sendNotification($tokens, $senderName, $body, [
                            'conversation_id' => $convId,
                            'message_uuid' => $uuid,
                            'sender_id' => $userId,
                            'type' => $type
                        ]);
                    }
                }
            }

        } catch (\Exception $e) {
            Logger::error("WS message persistence failed: " . $e->getMessage());
            $from->send(json_encode(['event' => 'error', 'message' => 'Failed to save message']));
        }
    }

    private function handleStatusUpdateEvent(string $userId, array $data): void {
        $uuid = $data['message_uuid'] ?? '';
        $status = $data['status'] ?? '';

        if (empty($uuid) || !in_array($status, ['delivered', 'seen'])) {
            return;
        }

        $message = Message::findByUuid($uuid);
        if (!$message) return;

        Message::updateStatus($message['id'], $userId, $status);

        // Notify sender of status update (single, double, blue tick)
        $senderId = $message['sender_id'];
        $this->manager->sendToUser($senderId, [
            'event' => 'status_update',
            'message_uuid' => $uuid,
            'status' => $status,
            'user_id' => $userId
        ]);
    }

    private function handleReactionEvent(string $userId, array $data): void {
        $uuid = $data['message_uuid'] ?? '';
        $reaction = $data['reaction'] ?? '';

        if (empty($uuid) || empty($reaction)) return;

        $message = Message::findByUuid($uuid);
        if (!$message) return;

        Message::addReaction($message['id'], $userId, $reaction);

        // Broadcast reaction to both members
        $recipientId = $this->getRecipientId($message['conversation_id'], $userId);
        if ($recipientId) {
            $this->manager->sendToUser($recipientId, [
                'event' => 'reaction',
                'message_uuid' => $uuid,
                'reaction' => $reaction,
                'user_id' => $userId
            ]);
        }
    }

    private function handleDeleteEvent(string $userId, array $data): void {
        $uuid = $data['message_uuid'] ?? '';
        $deleteType = $data['delete_type'] ?? 'me';

        if (empty($uuid) || !in_array($deleteType, ['me', 'everyone'])) return;

        $message = Message::findByUuid($uuid);
        if (!$message) return;

        if ($deleteType === 'everyone') {
            if ($message['sender_id'] !== $userId) return; // Only sender can delete for everyone
            $success = Message::deleteForEveryone($message['id'], $userId);
            
            if ($success) {
                // Broadcast deletion to recipient in real time
                $recipientId = $this->getRecipientId($message['conversation_id'], $userId);
                if ($recipientId) {
                    $this->manager->sendToUser($recipientId, [
                        'event' => 'delete',
                        'message_uuid' => $uuid,
                        'delete_type' => 'everyone'
                    ]);
                }
            }
        } else {
            Message::deleteForMe($message['id'], $userId);
        }
    }

    // --- Helpers ---

    private function getUserIdByConn(ConnectionInterface $conn): ?string {
        $connId = $conn->resourceId;
        $ref = new \ReflectionProperty($this->manager, 'connectionUsers');
        $ref->setAccessible(true);
        $map = $ref->getValue($this->manager);
        return $map[$connId] ?? null;
    }

    private function getRecipientId(string $conversationId, string $senderId): ?string {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT user_id FROM conversation_members 
            WHERE conversation_id = :conv_id AND user_id != :sender_id
            LIMIT 1
        ");
        $stmt->execute(['conv_id' => $conversationId, 'sender_id' => $senderId]);
        $res = $stmt->fetchColumn();
        return $res ?: null;
    }

    private function broadcastPresence(string $userId, string $status, ?string $lastSeen = null): void {
        $db = Database::getConnection();
        // Fetch all contacts who have this user in their list
        $stmt = $db->prepare("
            SELECT u.mobile_number FROM users u WHERE u.id = ?
        ");
        $stmt->execute([$userId]);
        $mobile = $stmt->fetchColumn();

        if ($mobile) {
            $stmtContacts = $db->prepare("
                SELECT user_id FROM contacts WHERE contact_phone = ?
            ");
            $stmtContacts->execute([$mobile]);
            $contactOwnerIds = $stmtContacts->fetchAll(PDO::FETCH_COLUMN);

            foreach ($contactOwnerIds as $ownerId) {
                if ($this->manager->isUserOnline($ownerId)) {
                    $this->manager->sendToUser($ownerId, [
                        'event' => 'presence_update',
                        'user_id' => $userId,
                        'status' => $status,
                        'last_seen' => $lastSeen
                    ]);
                }
            }
        }
    }
}
