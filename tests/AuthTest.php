<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Database\Database;
use App\Database\Schema;
use App\Models\User;

class AuthTest extends TestCase {
    
    protected function setUp(): void {
        parent::setUp();
        
        // Configure SQLite in-memory database for testing
        $_ENV['DB_HOST'] = 'sqlite::memory:';
        
        // Reset singleton and migrate tables
        Database::resetConnection();
        $pdo = Database::getConnection();
        Schema::createTables($pdo);
    }

    protected function tearDown(): void {
        Database::resetConnection();
        parent::tearDown();
    }

    public function testUserCreationAndLookup(): void {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $phone = '+12345678901';

        $success = User::create($uuid, $phone, 'Test User');
        $this->assertTrue($success);

        $user = User::findById($uuid);
        $this->assertNotNull($user);
        $this->assertEquals('Test User', $user['display_name']);
        $this->assertEquals($phone, $user['mobile_number']);

        $userByPhone = User::findByMobile($phone);
        $this->assertNotNull($userByPhone);
        $this->assertEquals($uuid, $userByPhone['id']);
    }

    public function testProfileUpdates(): void {
        $uuid = '22222222-2222-4222-8222-222222222222';
        $phone = '+19876543210';

        User::create($uuid, $phone);
        
        $updateData = [
            'display_name' => 'John Doe',
            'about' => 'Coding all night!',
            'online_status' => 'online'
        ];

        $updated = User::updateProfile($uuid, $updateData);
        $this->assertTrue($updated);

        $user = User::findById($uuid);
        $this->assertEquals('John Doe', $user['display_name']);
        $this->assertEquals('Coding all night!', $user['about']);
        $this->assertEquals('online', $user['online_status']);
    }

    public function testBlockingUsers(): void {
        $user1 = '33333333-3333-4333-8333-333333333333';
        $user2 = '44444444-4444-4444-8444-444444444444';

        User::create($user1, '+10000000001');
        User::create($user2, '+10000000002');

        $this->assertFalse(User::isBlocked($user1, $user2));

        User::blockUser($user1, $user2);
        $this->assertTrue(User::isBlocked($user1, $user2));
        $this->assertTrue(User::isBlocked($user2, $user1));

        User::unblockUser($user1, $user2);
        $this->assertFalse(User::isBlocked($user1, $user2));
    }
}
