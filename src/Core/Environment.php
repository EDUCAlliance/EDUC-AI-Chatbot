<?php
namespace EDUC\Core;

class Environment {
    private static bool $loaded = false;

    public static function load(string $filePath): void {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($filePath)) {
            throw new \Exception(".env File not found at: $filePath");
        }

        // Read the .env file
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            // Skip lines that start with '#' (comments)
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Split the line into key and value at the first '=' character
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);

                $value = trim($value, "\"'");

                // Set the environment variable
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }

    public static function set(string $key, string $value): void {
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
} 