<?php

declare(strict_types=1);

namespace Rgesn\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Rgesn\Database;
use Rgesn\Repositories\CriteriaRepository;
use Rgesn\Repositories\DeclarationRepository;
use Rgesn\Repositories\EvaluationRepository;
use Rgesn\Repositories\ProjectRepository;
use Rgesn\Services\ApplicabilityService;
use Rgesn\Services\DeclarationRenderer;
use Rgesn\Services\ScoringService;
use Rgesn\Support\Csrf;
use Rgesn\Support\Http;
use Rgesn\Support\View;

final class DeclarationController
{
    private CriteriaRepository $criteria;
    private EvaluationRepository $evaluations;
    private ProjectRepository $projects;
    private DeclarationRepository $declarations;

    public function __construct()
    {
        $db = Database::connection();
        $this->criteria = new CriteriaRepository($db);
        $this->evaluations = new EvaluationRepository($db);
        $this->projects = new ProjectRepository($db);
        $this->declarations = new DeclarationRepository($db);
    }

    /** Reconstruit le même "board" que EvaluationController, nécessaire au rendu du document. */
    private function buildBoard(int $evaluationId): ?array
    {
        $evaluation = $this->evaluations->find($evaluationId);
        if ($evaluation === null) {
            return null;
        }

        $project = $this->projects->find((int) $evaluation['project_id']);
        $allCriteria = $this->criteria->all();
        $gatingTagsByCriteria = $this->criteria->gatingTagsByCriteria();
        $gatingAnswers = $this->evaluations->gatingAnswers($evaluationId);
        $answers = $this->evaluations->answers($evaluationId);

        $applicability = new ApplicabilityService($gatingTagsByCriteria, $gatingAnswers);

        $autoNotApplicableCodes = [];
        $rows = [];
        foreach ($allCriteria as $criterion) {
            $code = $criterion['code'];
            $decidable = $applicability->isDecidable($code);
            $autoNa = $decidable && $applicability->isAutoNotApplicable($code);
            if ($autoNa) {
                $autoNotApplicableCodes[] = $code;
            }
            $answer = $answers[$code] ?? null;
            $rows[] = [
                'criterion' => $criterion,
                'answer' => $answer,
                'auto_na' => $autoNa,
                'status' => $autoNa ? 'non_applicable' : ($answer['status'] ?? 'non_renseigne'),
            ];
        }

        $groupedRows = [];
        foreach ($rows as $row) {
            $groupedRows[(int) $row['criterion']['theme_code']][] = $row;
        }
        ksort($groupedRows);

        $scoring = (new ScoringService())->compute($allCriteria, $answers, $autoNotApplicableCodes);

        $previousEvaluation = $this->evaluations->lastCompletedBefore((int) $evaluation['project_id'], $evaluationId);

        return [
            'evaluation' => $evaluation,
            'project' => $project,
            'grouped_rows' => $groupedRows,
            'scoring' => $scoring,
            'previous_evaluation' => $previousEvaluation,
        ];
    }

    public function preview(array $params): void
    {
        $board = $this->buildBoard((int) $params['id']);
        if ($board === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        echo (new DeclarationRenderer())->render($board);
    }

    public function generate(array $params): void
    {
        $evaluationId = (int) $params['id'];
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/evaluations/' . $evaluationId);
        }

        $board = $this->buildBoard($evaluationId);
        if ($board === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        $html = (new DeclarationRenderer())->render($board);
        $declarationId = $this->declarations->create($evaluationId, $html);

        Http::flash('success', 'Déclaration de conformité générée.');
        Http::redirect('/declarations/' . $declarationId);
    }

    public function show(array $params): void
    {
        $declaration = $this->declarations->find((int) $params['id']);
        if ($declaration === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        echo View::render('declaration/show.html.twig', [
            'declaration' => $declaration,
        ]);
    }

    public function raw(array $params): void
    {
        $declaration = $this->declarations->find((int) $params['id']);
        if ($declaration === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        echo $declaration['html_content'];
    }

    public function pdf(array $params): void
    {
        $declaration = $this->declarations->find((int) $params['id']);
        if ($declaration === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($declaration['html_content']);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="declaration-ecoconception-' . $declaration['id'] . '.pdf"');
        echo $dompdf->output();
    }
}
