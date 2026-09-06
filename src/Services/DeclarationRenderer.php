<?php

declare(strict_types=1);

namespace Rgesn\Services;

use Rgesn\Support\View;

/**
 * Construit le contexte de rendu de la déclaration d'écoconception à partir de l'état
 * d'une évaluation (le "board"), en suivant la structure du modèle officiel Arcep/Arcom :
 * résumé, critères validés/non validés, score d'avancement, plan d'avancement,
 * puis le détail du diagnostic thématique par thématique.
 */
final class DeclarationRenderer
{
    public function render(array $board): string
    {
        $meta = json_decode((string) ($board['evaluation']['meta'] ?? '{}'), true) ?: [];

        $themes = [];
        foreach ($board['grouped_rows'] as $themeCode => $rows) {
            $themes[] = [
                'code' => $themeCode,
                'label' => $rows[0]['criterion']['theme_label'] ?? ('Thématique ' . $themeCode),
                'rows' => $rows,
            ];
        }

        $context = [
            'project' => $board['project'],
            'evaluation' => $board['evaluation'],
            'meta' => $meta,
            'scoring' => $board['scoring'],
            'previous_evaluation' => $board['previous_evaluation'],
            'themes' => $themes,
            'generated_at' => date('d/m/Y'),
        ];

        return View::render('declaration/document.html.twig', $context);
    }
}
