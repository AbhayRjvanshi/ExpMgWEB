<?php
/**
 * Phase 3.1 Isolated Environment Configuration Loader & Validator
 * 
 * Loads variables from .env.phase3_1 strictly for Phase 3.1 feasibility tests.
 * Completely separate from production application config.
 * 
 * Hardened to enforce:
 * 1. Modern Supabase Publishable Key (deprecating legacy anon key)
 * 2. Unmistakable placeholder detection and execution blocking
 * 3. Sanitized error reporting (no secret leakage)
 */

declare(strict_types=1);

namespace ExpMg\Phase31;

class Config {
    private static array $env = [];
    private static bool $loaded = false;

    public static function load(?string $filePath = null, bool $throwIfMissing = true): void {
        if (self::$loaded) {
            return;
        }

        $path = $filePath ?? dirname(__DIR__, 2) . '/.env.phase3_1';

        if (!file_exists($path)) {
            if ($throwIfMissing) {
                $examplePath = dirname(__DIR__, 2) . '/.env.phase3_1.example';
                throw new \RuntimeException(
                    "[BLOCKED] Phase 3.1 configuration file not found at: {$path}\n" .
                    "Please copy {$examplePath} to .env.phase3_1 and fill in your Supabase project details."
                );
            }
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                self::$env[$key] = $value;
                if (!isset($_ENV[$key])) {
                    $_ENV[$key] = $value;
                }
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string {
        self::load(null, false);
        return self::$env[$key] ?? $_ENV[$key] ?? $default;
    }

    public static function has(string $key): bool {
        self::load(null, false);
        return !empty(self::$env[$key]) || !empty($_ENV[$key]);
    }

    /**
     * Determines whether a configuration value contains a placeholder.
     */
    public static function isPlaceholder(?string $value): bool {
        if ($value === null || trim($value) === '') {
            return true;
        }
        $val = strtolower(trim($value));
        $placeholderPatterns = [
            'your-',
            'your_',
            'placeholder',
            'example.com',
            'domain.com',
            'change-me',
            '<replace',
            'your_secure_test_password',
            'your_admin_database_password',
            'your_expmg_app_password',
            'your-publishable-key',
            'your-project-ref',
            'your-anon-key',
        ];
        foreach ($placeholderPatterns as $pattern) {
            if (str_contains($val, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns a configuration value, refusing execution if the key is missing or contains a placeholder.
     * Never prints sensitive secret values in error messages.
     */
    public static function requireReal(string $key): string {
        $val = self::get($key);
        if ($val === null || trim($val) === '') {
            throw new \RuntimeException("[BLOCKED] Required configuration '{$key}' is missing in .env.phase3_1.");
        }
        if (self::isPlaceholder($val)) {
            $isSecret = str_contains($key, 'PASS') || str_contains($key, 'KEY') || str_contains($key, 'SECRET');
            $safeDisplay = $isSecret ? '[REDACTED_PLACEHOLDER]' : "'{$val}'";
            throw new \RuntimeException(
                "[BLOCKED] Configuration '{$key}' contains placeholder value {$safeDisplay}.\n" .
                "Please update .env.phase3_1 with real, owner-controlled values before executing this test."
            );
        }
        return $val;
    }
}
