<?php

namespace App\Services;

use GuzzleHttp\Client;
use App\Utils\Logger;
use Dotenv\Dotenv;
use Firebase\JWT\JWT;
use Exception;

class FCMService {

    public static function sendNotification(array $deviceTokens, string $title, string $body, array $payloadData = []): bool {
        if (!getenv('FCM_PROJECT_ID')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $projectId = $_ENV['FCM_PROJECT_ID'] ?? '';
        $credentialsPath = dirname(__DIR__, 2) . '/' . ($_ENV['FCM_CREDENTIALS_PATH'] ?? 'config/firebase_credentials.json');

        // Check mock mode
        if (empty($projectId) || str_starts_with($projectId, 'mock') || !file_exists($credentialsPath)) {
            Logger::info("FCM: MOCK mode - Sending push to tokens: " . json_encode(array_column($deviceTokens, 'device_token')) . " | Title: $title | Body: $body | Payload: " . json_encode($payloadData));
            return true;
        }

        try {
            $accessToken = self::getAccessToken($credentialsPath);
            if (!$accessToken) {
                Logger::error("FCM: Failed to retrieve OAuth access token");
                return false;
            }

            $client = new Client();
            $success = true;

            foreach ($deviceTokens as $tokenData) {
                $token = $tokenData['device_token'];
                
                // Construct FCM HTTP v1 payload
                $payload = [
                    'message' => [
                        'token' => $token,
                        'notification' => [
                            'title' => $title,
                            'body' => $body
                        ],
                        'data' => array_map('strval', $payloadData), // Data values must be strings in FCM HTTP v1
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'sound' => 'default',
                                'click_action' => 'OPEN_CHAT_ACTIVITY' // Deep link target
                            ]
                        ]
                    ]
                ];

                try {
                    $response = $client->post("https://fcm.googleapis.com/v1/projects/$projectId/messages:send", [
                        'headers' => [
                            'Authorization' => 'Bearer ' . $accessToken,
                            'Content-Type' => 'application/json'
                        ],
                        'json' => $payload
                    ]);
                    
                    $statusCode = $response->getStatusCode();
                    if ($statusCode !== 200) {
                        Logger::warning("FCM: Unsuccessful delivery. Status code: $statusCode");
                        $success = false;
                    }
                } catch (Exception $e) {
                    Logger::error("FCM Send Exception for token $token: " . $e->getMessage());
                    $success = false;
                }
            }

            return $success;

        } catch (Exception $e) {
            Logger::error("FCM Service Exception: " . $e->getMessage());
            return false;
        }
    }

    private static function getAccessToken(string $credentialsPath): ?string {
        try {
            $credentials = json_decode(file_get_contents($credentialsPath), true);
            if (!$credentials) return null;

            $clientEmail = $credentials['client_email'] ?? '';
            $privateKey = $credentials['private_key'] ?? '';
            $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

            if (empty($clientEmail) || empty($privateKey)) {
                return null;
            }

            // Create JWT for Google OAuth2
            $now = time();
            $payload = [
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600
            ];

            $jwt = JWT::encode($payload, $privateKey, 'RS256');

            // Exchange JWT for token
            $client = new Client();
            $response = $client->post($tokenUri, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt
                ]
            ]);

            $resBody = json_decode($response->getBody()->getContents(), true);
            return $resBody['access_token'] ?? null;

        } catch (Exception $e) {
            Logger::error("FCM Token fetch failed: " . $e->getMessage());
            return null;
        }
    }
}
