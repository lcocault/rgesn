<?php

declare(strict_types=1);

namespace Rgesn;

use Rgesn\Support\Env;

final class Config
{
    public static function init(string $rootPath): void
    {
        Env::load($rootPath . '/.env');
    }

    public static function isDebug(): bool
    {
        return Env::get('APP_DEBUG', 'false') === 'true';
    }

    public static function organisation(): string
    {
        return Env::get('APP_ORGANISATION', '') ?? '';
    }

    /**
     * Sous-répertoire éventuel sous lequel l'application est publiée (vide si elle est servie
     * à la racine du domaine, ce qui est le cas recommandé pour un déploiement AlwaysData où
     * le document root du site pointe directement sur /public).
     */
    public static function basePath(): string
    {
        return rtrim(Env::get('APP_BASE_PATH', '') ?? '', '/');
    }

    public static function accessPassword(): ?string
    {
        $value = Env::get('APP_ACCESS_PASSWORD', '');
        return $value === '' ? null : $value;
    }

    public static function dbDsn(): string
    {
        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '5432');
        $name = Env::get('DB_NAME', 'rgesn');

        return "pgsql:host={$host};port={$port};dbname={$name}";
    }

    public static function dbUser(): string
    {
        return Env::get('DB_USER', 'rgesn') ?? 'rgesn';
    }

    public static function dbPassword(): string
    {
        return Env::get('DB_PASSWORD', '') ?? '';
    }
}
