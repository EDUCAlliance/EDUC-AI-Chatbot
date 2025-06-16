<?php

declare(strict_types=1);

namespace NextcloudBot\Helpers;

use Exception;

class Csrf
{
    private const TOKEN_KEY = 'csrf_token';

    /**
     * Generates and stores a new CSRF token in the session.
     * If a token already exists, it will be overwritten.
     *
     * @return string The generated CSRF token.
     * @throws Exception if random_bytes fails.
     */
    public static function generateToken(): string
    {
        $token = bin2hex(random_bytes(32));
        Session::set(self::TOKEN_KEY, $token);
        return $token;
    }

    /**
     * Validates a given CSRF token against the one stored in the session.
     *
     * @param string|null $token The token to validate.
     * @return bool True if the token is valid, false otherwise.
     */
    public static function validateToken(?string $token): bool
    {
        if (!$token) {
            return false;
        }

        $sessionToken = Session::get(self::TOKEN_KEY);
        if (!$sessionToken) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Returns an HTML hidden input field with the CSRF token.
     *
     * @return string The HTML input field.
     */
    public static function getFormInput(): string
    {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
} 