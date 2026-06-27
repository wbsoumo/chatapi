<?php

namespace App\Utils;

class Validator {
    public static function validateMobile(string $mobile): bool {
        // WhatsApp mobile format: should be digits, typically 10 to 15 characters long, optionally starting with '+'
        return preg_match('/^\+?[1-9]\d{1,14}$/', $mobile) === 1;
    }

    public static function validateUuid(string $uuid): bool {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    public static function cleanString(string $data): string {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }

    public static function validate(array $data, array $rules): array {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;
            $ruleList = explode('|', $fieldRules);

            foreach ($ruleList as $rule) {
                if ($rule === 'required' && ($value === null || $value === '')) {
                    $errors[$field][] = "The $field field is required.";
                }

                if ($value !== null && $value !== '') {
                    if ($rule === 'uuid' && !self::validateUuid($value)) {
                        $errors[$field][] = "The $field field must be a valid UUID.";
                    }
                    if ($rule === 'mobile' && !self::validateMobile($value)) {
                        $errors[$field][] = "The $field field must be a valid mobile number.";
                    }
                    if (str_starts_with($rule, 'max:')) {
                        $max = (int) substr($rule, 4);
                        if (strlen($value) > $max) {
                            $errors[$field][] = "The $field field must not exceed $max characters.";
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
