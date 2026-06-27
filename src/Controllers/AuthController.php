<?php

namespace App\Controllers;

use App\Database\Database;
use App\Models\User;
use App\Services\Fast2SMSService;
use App\Utils\Response;
use App\Utils\Validator;
use App\Utils\Logger;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Dotenv\Dotenv;
use Exception;
use PDO;

class AuthController {
    
    public function generateOtp(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $errors = Validator::validate($input, [
            'mobile_number' => 'required|mobile'
        ]);

        if (!empty($errors)) {
            Response::error('Validation Failed', 400, 4001);
        }

        $mobile = Validator::cleanString($input['mobile_number']);
        $db = Database::getConnection();

        // 1. Check resend timer (must wait 60 seconds between resends)
        $stmt = $db->prepare("
            SELECT resend_at FROM otp 
            WHERE mobile_number = :mobile 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute(['mobile' => $mobile]);
        $lastOtp = $stmt->fetch();

        $now = time();
        if ($lastOtp && $lastOtp['resend_at'] && strtotime($lastOtp['resend_at']) > $now) {
            $waitSeconds = strtotime($lastOtp['resend_at']) - $now;
            Response::error("Please wait $waitSeconds seconds before requesting a new OTP", 429, 4291);
        }

        // 2. Generate random six digit OTP
        $otp = sprintf("%06d", mt_rand(0, 999999));
        $otpHash = password_hash($otp, PASSWORD_DEFAULT);
        $expiresAt = date('Y-m-d H:i:s', $now + 300); // 5 minutes expiry
        $resendAt = date('Y-m-d H:i:s', $now + 60);    // 60 seconds resend timer

        // 3. Store only hashed OTP
        $stmt = $db->prepare("
            INSERT INTO otp (mobile_number, otp_hash, attempts, resend_at, expires_at) 
            VALUES (:mobile, :hash, 0, :resend_at, :expires_at)
        ");
        $stmt->execute([
            'mobile' => $mobile,
            'hash' => $otpHash,
            'resend_at' => $resendAt,
            'expires_at' => $expiresAt
        ]);

        // 4. Send through Fast2SMS WhatsApp API
        $sent = Fast2SMSService::sendOtp($mobile, $otp);

        if ($sent) {
            Response::success('OTP sent successfully', ['resend_after_seconds' => 60]);
        } else {
            Response::error('Failed to send OTP. Please try again.', 500, 5002);
        }
    }

    public function verifyOtp(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $errors = Validator::validate($input, [
            'mobile_number' => 'required|mobile',
            'otp' => 'required'
        ]);

        if (!empty($errors)) {
            Response::error('Validation Failed', 400, 4001);
        }

        $mobile = Validator::cleanString($input['mobile_number']);
        $otpCode = trim($input['otp']);

        $db = Database::getConnection();

        // 1. Fetch latest active OTP record
        $stmt = $db->prepare("
            SELECT id, otp_hash, attempts, expires_at FROM otp 
            WHERE mobile_number = :mobile 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute(['mobile' => $mobile]);
        $otpRecord = $stmt->fetch();

        if (!$otpRecord) {
            Response::error('Invalid or expired OTP', 400, 4002);
        }

        // 2. Check maximum retry attempts (Limit to 3 attempts)
        if ($otpRecord['attempts'] >= 3) {
            Response::error('Maximum OTP verification attempts reached. Please request a new OTP.', 400, 4003);
        }

        // 3. Check expiration (expires in 5 minutes)
        if (strtotime($otpRecord['expires_at']) < time()) {
            Response::error('OTP has expired', 400, 4004);
        }

        // 4. Verify OTP hash
        if (!password_verify($otpCode, $otpRecord['otp_hash'])) {
            // Increment attempts
            $stmt = $db->prepare("UPDATE otp SET attempts = attempts + 1 WHERE id = :id");
            $stmt->execute(['id' => $otpRecord['id']]);
            Response::error('Invalid OTP code', 400, 4005);
        }

        // OTP is correct! Invalidate OTP record immediately to prevent replay attacks
        $stmt = $db->prepare("DELETE FROM otp WHERE mobile_number = :mobile");
        $stmt->execute(['mobile' => $mobile]);

        // 5. Register or fetch existing user
        $user = User::findByMobile($mobile);
        $isNewUser = false;
        $userId = '';

        if (!$user) {
            $isNewUser = true;
            $userId = self::generateUuid();
            User::create($userId, $mobile);
            $user = User::findById($userId);
        } else {
            $userId = $user['id'];
        }

        // 6. Generate JWT Access & Refresh Tokens
        $tokens = self::generateTokens($userId, $mobile);

        Response::success('OTP verified successfully', [
            'is_new_user' => $isNewUser,
            'user' => $user,
            'tokens' => $tokens
        ]);
    }

    public function refreshToken(): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        if (empty($input['refresh_token'])) {
            Response::error('Refresh token is required', 400, 4006);
        }

        $refreshToken = $input['refresh_token'];
        
        if (!getenv('JWT_REFRESH_SECRET')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $refreshSecret = $_ENV['JWT_REFRESH_SECRET'] ?? 'super_secret_refresh_token_key_12345';

        try {
            $decoded = JWT::decode($refreshToken, new Key($refreshSecret, 'HS256'));
            $userId = $decoded->sub;
            
            $user = User::findById($userId);
            if (!$user) {
                Response::error('User not found', 404, 4041);
            }

            $tokens = self::generateTokens($userId, $user['mobile_number']);
            Response::success('Token refreshed successfully', ['tokens' => $tokens]);

        } catch (Exception $e) {
            Response::error('Invalid or expired refresh token', 401, 4011);
        }
    }

    public function updateProfile(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        $errors = Validator::validate($input, [
            'display_name' => 'max:100',
            'about' => 'max:255'
        ]);

        if (!empty($errors)) {
            Response::error('Validation Failed', 400, 4001);
        }

        $updated = User::updateProfile($userId, $input);
        if ($updated) {
            $user = User::findById($userId);
            Response::success('Profile updated successfully', ['user' => $user]);
        } else {
            Response::error('No profile updates were made', 400, 4007);
        }
    }

    public function registerDeviceToken(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        if (empty($input['device_token'])) {
            Response::error('Device token is required', 400, 4008);
        }

        $token = trim($input['device_token']);
        $platform = trim($input['platform'] ?? 'android');

        $updated = User::updateDeviceToken($userId, $token, $platform);
        if ($updated) {
            Response::success('Device token registered successfully');
        } else {
            Response::error('Failed to register device token', 500, 5003);
        }
    }

    private static function generateTokens(string $userId, string $mobileNumber): array {
        if (!getenv('JWT_SECRET')) {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->safeLoad();
        }

        $secret = $_ENV['JWT_SECRET'] ?? 'super_secret_access_token_key_12345';
        $refreshSecret = $_ENV['JWT_REFRESH_SECRET'] ?? 'super_secret_refresh_token_key_12345';
        $exp = (int)($_ENV['JWT_EXPIRATION_SECONDS'] ?? 3600);
        $refreshExp = (int)($_ENV['JWT_REFRESH_EXPIRATION_SECONDS'] ?? 2592000);

        $now = time();

        $payload = [
            'iss' => 'whatsapp-backend',
            'sub' => $userId,
            'mobile' => $mobileNumber,
            'iat' => $now,
            'exp' => $now + $exp
        ];

        $refreshPayload = [
            'iss' => 'whatsapp-backend',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + $refreshExp
        ];

        return [
            'access_token' => JWT::encode($payload, $secret, 'HS256'),
            'refresh_token' => JWT::encode($refreshPayload, $refreshSecret, 'HS256'),
            'expires_in' => $exp
        ];
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
