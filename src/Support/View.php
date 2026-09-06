<?php

declare(strict_types=1);

namespace Rgesn\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class View
{
    private static ?Environment $twig = null;

    public static function init(string $templatesPath, bool $debug): void
    {
        $loader = new FilesystemLoader($templatesPath);
        self::$twig = new Environment($loader, [
            'cache' => false,
            'debug' => $debug,
            'autoescape' => 'html',
        ]);

        self::$twig->addFunction(new \Twig\TwigFunction('path', [self::class, 'path']));
        self::$twig->addFunction(new \Twig\TwigFunction('flashes', [Http::class, 'consumeFlashes']));
        self::$twig->addFunction(new \Twig\TwigFunction('is_authenticated', [Auth::class, 'isLoggedIn']));
        self::$twig->addFunction(new \Twig\TwigFunction('auth_required', [Auth::class, 'isRequired']));
        self::$twig->addFilter(new \Twig\TwigFilter('json_decode', function ($value) {
            return json_decode((string) $value, true) ?: [];
        }));
    }

    public static function path(string $path = ''): string
    {
        $base = rtrim(BASE_URL_PREFIX, '/');
        return $base . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function render(string $template, array $context = []): string
    {
        if (self::$twig === null) {
            throw new \RuntimeException('View non initialisée.');
        }

        return self::$twig->render($template, $context);
    }
}
