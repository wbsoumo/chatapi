<?php

namespace App\Services;

use GuzzleHttp\Client;
use App\Utils\Logger;
use Dotenv\Dotenv;

class Fast2SMSService {
    public static function sendOtp(string $mobileNumber, string $otp): bool {
        if (!getenv('FAST2SMS_API_KEY')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $apiKey = $_ENV['FAST2SMS_API_KEY'] ?? '';
        $phoneNumberId = $_ENV['FAST2SMS_PHONE_NUMBER_ID'] ?? '';
        $messageId = $_ENV['FAST2SMS_MESSAGE_ID'] ?? '';

        // If configured as mock or missing keys, log OTP and return true
        if (empty($apiKey) || str_starts_with($apiKey, 'mock') || empty($phoneNumberId)) {
            Logger::info("Fast2SMS: MOCK mode - OTP for $mobileNumber is $otp");
            return true;
        }

        $client = new Client();
        try {
            $response = $client->post('https://www.fast2sms.com/dev/whatsapp', [
                'headers' => [
                    'Authorization' => $apiKey,
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'phone_number_id' => $phoneNumberId,
                    'message_id' => $messageId,
                    'numbers' => $mobileNumber,
                    'variables_values' => $otp
                ]
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            
            if ((isset($body['return']) && $body['return'] === true) || (isset($body['status']) && $body['status'] === true)) {
                Logger::info("Fast2SMS: OTP sent successfully to $mobileNumber");
                return true;
            }

            Logger::error("Fast2SMS sending failed: " . json_encode($body));
            return false;
        } catch (\Exception $e) {
            Logger::error("Fast2SMS exception: " . $e->getMessage());
            return false;
        }
    }
}
