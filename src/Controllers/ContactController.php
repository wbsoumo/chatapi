<?php

namespace App\Controllers;

use App\Database\Database;
use App\Utils\Response;
use App\Utils\Validator;
use PDO;

class ContactController {
    
    public function syncContacts(array $userContext): void {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = $userContext['sub'];

        if (!isset($input['contacts']) || !is_array($input['contacts'])) {
            Response::error('Contacts list is required and must be an array', 400, 4009);
        }

        $contacts = $input['contacts']; // array of [ 'name' => 'Alice', 'phone' => '+1234567890' ] or list of phone numbers
        if (empty($contacts)) {
            Response::success('Sync completed', ['registered_contacts' => []]);
        }

        $phoneToNameMap = [];
        $phones = [];

        foreach ($contacts as $contact) {
            $phone = '';
            $name = '';
            
            if (is_array($contact)) {
                $phone = trim($contact['phone'] ?? '');
                $name = trim($contact['name'] ?? '');
            } else {
                $phone = trim($contact);
            }

            if (!empty($phone)) {
                // Normalize phone number (strip whitespace, dashes, etc.)
                $normalizedPhone = preg_replace('/[^\d+]/', '', $phone);
                if (Validator::validateMobile($normalizedPhone)) {
                    $phones[] = $normalizedPhone;
                    if (!empty($name)) {
                        $phoneToNameMap[$normalizedPhone] = $name;
                    }
                }
            }
        }

        if (empty($phones)) {
            Response::success('Sync completed', ['registered_contacts' => []]);
        }

        $db = Database::getConnection();
        $db->beginTransaction();

        try {
            // Retrieve all registered users whose mobile_number matches
            // Build in-clause dynamically to prevent SQL Injection
            $placeholders = implode(',', array_fill(0, count($phones), '?'));
            $sql = "
                SELECT u.id, u.mobile_number, p.display_name, p.about, p.profile_picture, p.last_seen, p.online_status
                FROM users u
                LEFT JOIN profiles p ON u.id = p.user_id
                WHERE u.mobile_number IN ($placeholders) AND u.id != ?
            ";
            
            $stmt = $db->prepare($sql);
            $execParams = array_merge($phones, [$userId]);
            $stmt->execute($execParams);
            $registeredUsers = $stmt->fetchAll();

            $registeredContacts = [];
            $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql = ($driver === 'sqlite')
                ? "INSERT INTO contacts (user_id, contact_name, contact_phone) VALUES (:user_id, :contact_name, :contact_phone) ON CONFLICT(user_id, contact_phone) DO UPDATE SET contact_name = :contact_name"
                : "INSERT INTO contacts (user_id, contact_name, contact_phone) VALUES (:user_id, :contact_name, :contact_phone) ON DUPLICATE KEY UPDATE contact_name = :contact_name";
            $insertStmt = $db->prepare($sql);

            foreach ($registeredUsers as $regUser) {
                $phone = $regUser['mobile_number'];
                $customName = $phoneToNameMap[$phone] ?? $regUser['display_name'] ?? $phone;

                // Store relation in contacts table for faster future scans
                $insertStmt->execute([
                    'user_id' => $userId,
                    'contact_name' => $customName,
                    'contact_phone' => $phone
                ]);

                // Append custom display name based on user's local phone book preference
                $regUser['local_contact_name'] = $customName;
                $registeredContacts[] = $regUser;
            }

            $db->commit();
            Response::success('Contacts synchronized successfully', [
                'registered_contacts' => $registeredContacts
            ]);

        } catch (\Exception $e) {
            $db->rollBack();
            Response::error('Failed to synchronize contacts: ' . $e->getMessage(), 500, 5004);
        }
    }
}
