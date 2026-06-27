<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Database\Database;
use App\Database\Schema;
use App\Models\User;
use App\Models\Conversation;
use App\Models\Message;

class SyncTest extends TestCase {
    protected function setUp(): void {
        parent::setUp();
        $_ENV['DB_HOST'] = 'sqlite::memory:';
        Database::resetConnection();
        $pdo = Database::getConnection();
        Schema::createTables($pdo);
    }

    protected function tearDown(): void {
        Database::resetConnection();
        parent::tearDown();
    }

    public function testIncrementalMessageSync(): void {
        $senderId = '11111111-1111-4111-8111-111111111111';
        $receiverId = '22222222-2222-4222-8222-222222222222';
        $convId = '33333333-3333-4333-8333-333333333333';

        User::create($senderId, '+12345678901');
        User::create($receiverId, '+19876543210');
        Conversation::create($convId, $senderId, $receiverId);

        // Write 4 messages
        $msgIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $msgIds[] = Message::create([
                'message_uuid' => "00000000-0000-4000-8000-00000000000$i",
                'conversation_id' => $convId,
                'sender_id' => $senderId,
                'type' => 'text',
                'content' => "Message $i",
                'reply_to_message_id' => null,
                'forwarded' => false
            ]);
        }

        // Test Sync from start (last message ID = 0)
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, content FROM messages WHERE conversation_id = ? AND id > ? ORDER BY id ASC");
        $stmt->execute([$convId, 0]);
        $newMsgs0 = $stmt->fetchAll();
        $this->assertCount(4, $newMsgs0);

        // Test Sync from message ID = 2
        $stmt->execute([$convId, $msgIds[1]]); // msgIds[1] is the 2nd message (index 1)
        $newMsgs2 = $stmt->fetchAll();
        
        $this->assertCount(2, $newMsgs2);
        $this->assertEquals("Message 3", $newMsgs2[0]['content']);
        $this->assertEquals("Message 4", $newMsgs2[1]['content']);
    }

    public function testSyncDeletionsAndReactions(): void {
        $senderId = '11111111-1111-4111-8111-111111111111';
        $receiverId = '22222222-2222-4222-8222-222222222222';
        $convId = '33333333-3333-4333-8333-333333333333';

        User::create($senderId, '+12345678901');
        User::create($receiverId, '+19876543210');
        Conversation::create($convId, $senderId, $receiverId);

        $msgId = Message::create([
            'message_uuid' => "00000000-0000-4000-8000-999999999999",
            'conversation_id' => $convId,
            'sender_id' => $senderId,
            'type' => 'text',
            'content' => "Delete and React Test",
            'reply_to_message_id' => null,
            'forwarded' => false
        ]);

        // Add reaction
        Message::addReaction($msgId, $receiverId, '❤️');

        $db = Database::getConnection();
        $stmtReaction = $db->prepare("SELECT reaction FROM message_reactions WHERE message_id = ? AND user_id = ?");
        $stmtReaction->execute([$msgId, $receiverId]);
        $this->assertEquals('❤️', $stmtReaction->fetchColumn());

        // Delete message for me
        Message::deleteForMe($msgId, $receiverId);
        
        $stmtDeleted = $db->prepare("SELECT 1 FROM deleted_messages WHERE message_id = ? AND user_id = ?");
        $stmtDeleted->execute([$msgId, $receiverId]);
        $this->assertTrue((bool)$stmtDeleted->fetchColumn());
    }
}
