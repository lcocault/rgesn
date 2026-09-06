<?php

declare(strict_types=1);

namespace Rgesn\Support;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function check(): bool
    {
        $submitted = $_POST['_csrf'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';

        return $expected !== '' && hash_equals($expected, (string) $submitted);
    }
}
