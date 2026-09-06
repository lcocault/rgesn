<?php

declare(strict_types=1);

namespace Rgesn;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            try {
                self::$connection = new PDO(
                    Config::dbDsn(),
                    Config::dbUser(),
                    Config::dbPassword(),
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
            } catch (PDOException $e) {
                throw new PDOException('Connexion à la base de données impossible : ' . $e->getMessage(), (int) $e->getCode());
            }
        }

        return self::$connection;
    }
}
