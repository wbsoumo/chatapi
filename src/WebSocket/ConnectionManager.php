<?php

namespace App\WebSocket;

use Ratchet\ConnectionInterface;

class ConnectionManager {
    // Map of user_id => [connId => ConnectionInterface]
    private array $userConnections = [];

    // Map of connId => user_id
    private array $connectionUsers = [];

    public function addConnection(string $userId, ConnectionInterface $conn): void {
        $connId = $conn->resourceId;
        
        if (!isset($this->userConnections[$userId])) {
            $this->userConnections[$userId] = [];
        }
        
        $this->userConnections[$userId][$connId] = $conn;
        $this->connectionUsers[$connId] = $userId;
    }

    public function removeConnection(ConnectionInterface $conn): ?string {
        $connId = $conn->resourceId;
        $userId = $this->connectionUsers[$connId] ?? null;

        if ($userId) {
            unset($this->userConnections[$userId][$connId]);
            if (empty($this->userConnections[$userId])) {
                unset($this->userConnections[$userId]);
            }
            unset($this->connectionUsers[$connId]);
        }

        return $userId;
    }

    public function isUserOnline(string $userId): bool {
        return isset($this->userConnections[$userId]) && !empty($this->userConnections[$userId]);
    }

    public function getConnections(string $userId): array {
        return $this->userConnections[$userId] ?? [];
    }

    public function sendToUser(string $userId, array $data): bool {
        if (!$this->isUserOnline($userId)) {
            return false;
        }

        $payload = json_encode($data);
        foreach ($this->userConnections[$userId] as $conn) {
            $conn->send($payload);
        }
        
        return true;
    }

    public function broadcast(array $data, array $excludeUserIds = []): void {
        $payload = json_encode($data);
        foreach ($this->userConnections as $userId => $conns) {
            if (in_array($userId, $excludeUserIds)) {
                continue;
            }
            foreach ($conns as $conn) {
                $conn->send($payload);
            }
        }
    }
}
