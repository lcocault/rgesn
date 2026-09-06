<?php

declare(strict_types=1);

// Charge (ou recharge) les données de référence du RGESN dans la base :
// les 78 critères du référentiel, les questions de cadrage et leur association.
// Idempotent : peut être relancé sans dupliquer les données (upsert par clé primaire).
// Usage : php database/seed_criteria.php

require __DIR__ . '/../vendor/autoload.php';

use Rgesn\Config;
use Rgesn\Database;

Config::init(dirname(__DIR__));

$pdo = Database::connection();

$criteriaFile = __DIR__ . '/seeds/criteria.json';
$gatingFile = __DIR__ . '/seeds/gating_questions.json';

if (!is_file($criteriaFile)) {
    fwrite(STDERR, "Fichier introuvable : {$criteriaFile}\n");
    exit(1);
}
if (!is_file($gatingFile)) {
    fwrite(STDERR, "Fichier introuvable : {$gatingFile}\n");
    exit(1);
}

$criteria = json_decode((string) file_get_contents($criteriaFile), true, flags: JSON_THROW_ON_ERROR);
$gatingQuestions = json_decode((string) file_get_contents($gatingFile), true, flags: JSON_THROW_ON_ERROR);

if (count($criteria) !== 78) {
    fwrite(STDERR, sprintf("Attention : %d critères trouvés dans criteria.json (78 attendus).\n", count($criteria)));
}

$pdo->beginTransaction();

try {
    $upsertCriterion = $pdo->prepare(
        'INSERT INTO criteria (
            code, theme_code, theme_label, position, question, priority, difficulty,
            applicability_default, applicability_condition, metiers, objectif,
            mise_en_oeuvre, moyen_test, pour_aller_plus_loin, referentiel_version
        ) VALUES (
            :code, :theme_code, :theme_label, :position, :question, :priority, :difficulty,
            :applicability_default, :applicability_condition, :metiers, :objectif,
            :mise_en_oeuvre, :moyen_test, :pour_aller_plus_loin, :referentiel_version
        )
        ON CONFLICT (code) DO UPDATE SET
            theme_code = EXCLUDED.theme_code,
            theme_label = EXCLUDED.theme_label,
            position = EXCLUDED.position,
            question = EXCLUDED.question,
            priority = EXCLUDED.priority,
            difficulty = EXCLUDED.difficulty,
            applicability_default = EXCLUDED.applicability_default,
            applicability_condition = EXCLUDED.applicability_condition,
            metiers = EXCLUDED.metiers,
            objectif = EXCLUDED.objectif,
            mise_en_oeuvre = EXCLUDED.mise_en_oeuvre,
            moyen_test = EXCLUDED.moyen_test,
            pour_aller_plus_loin = EXCLUDED.pour_aller_plus_loin,
            referentiel_version = EXCLUDED.referentiel_version'
    );

    foreach ($criteria as $c) {
        $upsertCriterion->execute([
            'code' => $c['code'],
            'theme_code' => $c['theme_code'],
            'theme_label' => $c['theme_label'],
            'position' => $c['position'],
            'question' => $c['question'],
            'priority' => $c['priority'],
            'difficulty' => $c['difficulty'] ?? null,
            'applicability_default' => $c['applicability_default'],
            'applicability_condition' => $c['applicability_condition'] ?? '',
            'metiers' => $c['metiers'] ?? '',
            'objectif' => $c['objectif'] ?? '',
            'mise_en_oeuvre' => $c['mise_en_oeuvre'] ?? '',
            'moyen_test' => $c['moyen_test'] ?? '',
            'pour_aller_plus_loin' => $c['pour_aller_plus_loin'] ?? null,
            'referentiel_version' => $c['referentiel_version'] ?? '2024',
        ]);
    }

    echo count($criteria) . " critères chargés.\n";

    $upsertGating = $pdo->prepare(
        'INSERT INTO gating_questions (tag, label, help_text, position)
         VALUES (:tag, :label, :help_text, :position)
         ON CONFLICT (tag) DO UPDATE SET label = EXCLUDED.label, help_text = EXCLUDED.help_text, position = EXCLUDED.position'
    );
    $clearLinks = $pdo->prepare('DELETE FROM criteria_gating_tags WHERE gating_tag = :tag');
    $insertLink = $pdo->prepare(
        'INSERT INTO criteria_gating_tags (criteria_code, gating_tag) VALUES (:code, :tag)
         ON CONFLICT DO NOTHING'
    );

    foreach ($gatingQuestions as $position => $gq) {
        $upsertGating->execute([
            'tag' => $gq['tag'],
            'label' => $gq['label'],
            'help_text' => $gq['help_text'] ?? '',
            'position' => $position,
        ]);

        $clearLinks->execute(['tag' => $gq['tag']]);
        foreach ($gq['criteria'] as $code) {
            $insertLink->execute(['code' => $code, 'tag' => $gq['tag']]);
        }
    }

    echo count($gatingQuestions) . " questions de cadrage chargées.\n";

    $pdo->commit();
    echo "Seed terminé.\n";
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Erreur pendant le seed : ' . $e->getMessage() . "\n");
    exit(1);
}
