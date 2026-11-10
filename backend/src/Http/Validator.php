<?php
declare(strict_types=1);

namespace Vantiq\Http;

final class Validator
{
    public const PASSWORD_MAX_BYTES = 72;

    public static function only(array $input, array $allowedFields): void
    {
        $unexpected = array_diff(array_keys($input), $allowedFields);
        if ($unexpected !== []) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                'request' => 'Unexpected fields are not allowed.'
            ]);
        }
    }

    public static function requiredString(array $input, string $field, int $maxLength): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'This field is required.'
            ]);
        }

        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'This field is required and must be within the allowed length.'
            ]);
        }

        return $value;
    }

    public static function email(array $input, string $field = 'email'): string
    {
        $email = mb_strtolower(self::requiredString($input, $field, 254));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'Enter a valid email address.'
            ]);
        }

        return $email;
    }

    public static function password(array $input, string $field = 'password'): string
    {
        $password = $input[$field] ?? null;
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > self::PASSWORD_MAX_BYTES) {
            throw new ApiException(422, 'validation_failed', 'Please check the submitted information.', [
                $field => 'Use a password between 12 and 72 bytes.'
            ]);
        }

        return $password;
    }
}
