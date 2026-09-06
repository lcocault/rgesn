<?php

declare(strict_types=1);

namespace Rgesn\Support;

use Rgesn\Config;

final class Auth
{
    public static function isRequired(): bool
    {
        return Config::accessPassword() !== null;
    }

    public static function isLoggedIn(): bool
    {
        return !self::isRequired() || ($_SESSION['authenticated'] ?? false) === true;
    }

    public static function attempt(string $password): bool
    {
        $expected = Config::accessPassword();
        if ($expected !== null && hash_equals($expected, $password)) {
            $_SESSION['authenticated'] = true;

            return true;
        }

        return false;
    }

    public static function logout(): void
    {
        unset($_SESSION['authenticated']);
    }
}
