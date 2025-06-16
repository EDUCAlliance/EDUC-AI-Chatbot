<?php

declare(strict_types=1);

namespace NextcloudBot\Helpers;

class Session
{
    private const APP_SESSION_KEY = 'nextcloud_bot_session';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION[self::APP_SESSION_KEY])) {
            $_SESSION[self::APP_SESSION_KEY] = [];
        }
    }

    public static function set(string $key, $value): void
    {
        self::start();
        $_SESSION[self::APP_SESSION_KEY][$key] = $value;
    }

    public static function get(string $key, $default = null)
    {
        self::start();
        return $_SESSION[self::APP_SESSION_KEY][$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[self::APP_SESSION_KEY][$key]);
    }

    public static function delete(string $key): void
    {
        self::start();
        unset($_SESSION[self::APP_SESSION_KEY][$key]);
    }

    public static function destroy(): void
    {
        self::start();
        // Unset all of the session variables.
        $_SESSION = [];

        // If it's desired to kill the session, also delete the session cookie.
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        session_destroy();
    }
    
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }
} 