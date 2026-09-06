<?php

declare(strict_types=1);

namespace Rgesn\Support;

final class Http
{
    public static function redirect(string $path): never
    {
        header('Location: ' . View::path($path));
        exit;
    }

    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * @return array<int, array{type:string, message:string}>
     */
    public static function consumeFlashes(): array
    {
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);

        return $flashes;
    }

    public static function input(string $key, string $default = ''): string
    {
        return trim((string) ($_POST[$key] ?? $default));
    }
}
