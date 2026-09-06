<?php

declare(strict_types=1);

// Petit runner de migrations, sans dépendance : exécute tous les fichiers .sql
// de database/migrations/ dans l'ordre alphabétique, une seule fois chacun.
// Usage : php database/migrate.php

require __DIR__ . '/../vendor/autoload.php';

use Rgesn\Config;
use Rgesn\Database;

Config::init(dirname(__DIR__));

$pdo = Database::connection();

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    filename TEXT PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT now()
)');

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$migrationsDir = __DIR__ . '/migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files);

foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        echo "- déjà appliquée : {$name}\n";
        continue;
    }

    echo "-> application de {$name}...\n";
    $sql = file_get_contents($file);
    $pdo->beginTransaction();
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:name)');
        $stmt->execute(['name' => $name]);
        $pdo->commit();
        echo "   OK\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Erreur lors de {$name} : {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Migrations terminées.\n";
