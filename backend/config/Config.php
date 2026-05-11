<?php
// backend/config/Config.php

class Config {
    private static array $config = [];

    public static function load(): void {
        $envFile = __DIR__ . '/../../.env';

        if (!file_exists($envFile)) {
            die('Error: .env file not found at: ' . $envFile);
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and empty lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                self::$config[trim($key)] = trim($value);
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed {
        return self::$config[$key] ?? $default;
    }
}

Config::load();