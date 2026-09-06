<?php

declare(strict_types=1);

namespace Rgesn\Services;

/**
 * Calcule le score d'avancement d'une évaluation selon la formule officielle du RGESN :
 *
 *   [Somme des poids des critères applicables VALIDÉS]
 *   -------------------------------------------------- x 100
 *   [Somme des poids des critères applicables VALIDÉS + NON VALIDÉS]
 *
 * avec les pondérations : Prioritaire = 1,5 ; Recommandé = 1,25 ; Modéré = 1,0.
 * Les critères "non applicable" ou "non renseigné" sont exclus du calcul.
 */
final class ScoringService
{
    private const WEIGHTS = [
        'prioritaire' => 1.5,
        'recommande' => 1.25,
        'modere' => 1.0,
    ];

    /**
     * @param array<int, array<string, mixed>> $criteria tous les critères (avec 'code' et 'priority')
     * @param array<string, array<string, mixed>> $answers code critère => ['status' => ..., 'justification' => ...]
     * @param string[] $autoNotApplicableCodes codes des critères écartés automatiquement par le filtrage
     */
    public function compute(array $criteria, array $answers, array $autoNotApplicableCodes): array
    {
        $validatedWeight = 0.0;
        $applicableWeight = 0.0;

        $validated = [];
        $nonValidated = [];
        $notApplicable = [];
        $unanswered = [];

        foreach ($criteria as $criterion) {
            $code = $criterion['code'];
            $weight = self::WEIGHTS[$criterion['priority']] ?? 1.0;

            if (in_array($code, $autoNotApplicableCodes, true)) {
                $notApplicable[] = $code;
                continue;
            }

            $status = $answers[$code]['status'] ?? 'non_renseigne';

            switch ($status) {
                case 'valide':
                    $validated[] = $code;
                    $validatedWeight += $weight;
                    $applicableWeight += $weight;
                    break;
                case 'non_valide':
                    $nonValidated[] = $code;
                    $applicableWeight += $weight;
                    break;
                case 'non_applicable':
                    $notApplicable[] = $code;
                    break;
                default:
                    $unanswered[] = $code;
                    break;
            }
        }

        $score = $applicableWeight > 0.0 ? round(($validatedWeight / $applicableWeight) * 100, 1) : 0.0;

        return [
            'score' => $score,
            'validated' => $validated,
            'non_validated' => $nonValidated,
            'not_applicable' => $notApplicable,
            'unanswered' => $unanswered,
        ];
    }
}
