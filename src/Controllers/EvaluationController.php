<?php

declare(strict_types=1);

namespace Rgesn\Controllers;

use Rgesn\Database;
use Rgesn\Repositories\CriteriaRepository;
use Rgesn\Repositories\DeclarationRepository;
use Rgesn\Repositories\EvaluationRepository;
use Rgesn\Repositories\ProjectRepository;
use Rgesn\Services\ApplicabilityService;
use Rgesn\Services\ScoringService;
use Rgesn\Support\Csrf;
use Rgesn\Support\Http;
use Rgesn\Support\View;

final class EvaluationController
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

    public function create(array $params): void
    {
        $projectId = (int) $params['project'];
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/projects/' . $projectId);
        }

        $label = Http::input('label', 'Évaluation du ' . date('d/m/Y'));
        $id = $this->evaluations->create($projectId, $label);
        Http::redirect('/evaluations/' . $id);
    }

    /**
     * Construit l'état complet d'une évaluation : critères applicables/écartés, réponses, score.
     */
    private function buildBoard(int $evaluationId): ?array
    {
        $evaluation = $this->evaluations->find($evaluationId);
        if ($evaluation === null) {
            return null;
        }

        $project = $this->projects->find((int) $evaluation['project_id']);
        $allCriteria = $this->criteria->all();
        $gatingQuestions = $this->criteria->gatingQuestions();
        $gatingTagsByCriteria = $this->criteria->gatingTagsByCriteria();
        $gatingAnswers = $this->evaluations->gatingAnswers($evaluationId);
        $answers = $this->evaluations->answers($evaluationId);

        $applicability = new ApplicabilityService($gatingTagsByCriteria, $gatingAnswers);

        $gatingComplete = true;
        foreach ($gatingQuestions as $gq) {
            if (!array_key_exists($gq['tag'], $gatingAnswers)) {
                $gatingComplete = false;
                break;
            }
        }

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
                'awaiting_gating' => !$decidable,
                'status' => $autoNa ? 'non_applicable' : ($answer['status'] ?? 'non_renseigne'),
            ];
        }

        $scoring = new ScoringService();
        $result = $scoring->compute($allCriteria, $answers, $autoNotApplicableCodes);

        $remaining = array_values(array_filter($rows, fn ($r) => !$r['auto_na'] && !$r['awaiting_gating'] && $r['answer'] === null));

        $groupedRows = [];
        foreach ($rows as $row) {
            $groupedRows[(int) $row['criterion']['theme_code']][] = $row;
        }
        ksort($groupedRows);

        $previousEvaluation = $evaluation['status'] === 'completed'
            ? null
            : $this->evaluations->lastCompletedBefore((int) $evaluation['project_id'], $evaluationId);

        return [
            'evaluation' => $evaluation,
            'project' => $project,
            'gating_questions' => $gatingQuestions,
            'gating_answers' => $gatingAnswers,
            'gating_complete' => $gatingComplete,
            'grouped_rows' => $groupedRows,
            'remaining' => $remaining,
            'scoring' => $result,
            'total_criteria' => count($allCriteria),
            'previous_evaluation' => $previousEvaluation,
        ];
    }

    public function show(array $params): void
    {
        $board = $this->buildBoard((int) $params['id']);
        if ($board === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        $declarations = $this->declarations->forEvaluation((int) $params['id']);

        echo View::render('evaluations/show.html.twig', $board + ['csrf' => Csrf::token(), 'declarations' => $declarations]);
    }

    public function gatingForm(array $params): void
    {
        $board = $this->buildBoard((int) $params['id']);
        if ($board === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        echo View::render('evaluations/gating.html.twig', $board + ['csrf' => Csrf::token()]);
    }

    public function gatingSave(array $params): void
    {
        $id = (int) $params['id'];
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/evaluations/' . $id . '/gating');
        }

        foreach ($this->criteria->gatingQuestions() as $question) {
            $tag = $question['tag'];
            $value = ($_POST['gating'][$tag] ?? 'oui') === 'oui';
            $this->evaluations->saveGatingAnswer($id, $tag, $value);
        }

        Http::flash('success', 'Questions de cadrage enregistrées.');
        Http::redirect('/evaluations/' . $id);
    }

    public function criterionForm(array $params): void
    {
        $evaluationId = (int) $params['id'];
        $code = $params['code'];

        $board = $this->buildBoard($evaluationId);
        if ($board === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        $criterion = $this->criteria->find($code);
        if ($criterion === null) {
            http_response_code(404);
            echo View::render('errors/404.html.twig', []);
            return;
        }

        $answers = $this->evaluations->answers($evaluationId);
        $flatRows = array_merge(...array_values($board['grouped_rows']));
        $codes = array_map(fn ($r) => $r['criterion']['code'], $flatRows);
        $position = array_search($code, $codes, true);
        $nextCode = $position !== false && isset($codes[$position + 1]) ? $codes[$position + 1] : null;
        $prevCode = $position !== false && $position > 0 ? $codes[$position - 1] : null;

        $nextUnanswered = null;
        foreach ($board['remaining'] as $row) {
            if ($row['criterion']['code'] !== $code) {
                $nextUnanswered = $row['criterion']['code'];
                break;
            }
        }

        echo View::render('evaluations/criterion.html.twig', [
            'evaluation' => $board['evaluation'],
            'project' => $board['project'],
            'criterion' => $criterion,
            'answer' => $answers[$code] ?? null,
            'next_code' => $nextCode,
            'prev_code' => $prevCode,
            'next_unanswered' => $nextUnanswered,
            'csrf' => Csrf::token(),
        ]);
    }

    public function criterionSave(array $params): void
    {
        $evaluationId = (int) $params['id'];
        $code = $params['code'];

        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/evaluations/' . $evaluationId . '/criteria/' . $code);
        }

        $status = Http::input('status', 'non_renseigne');
        $allowed = ['valide', 'non_valide', 'non_applicable', 'non_renseigne'];
        if (!in_array($status, $allowed, true)) {
            $status = 'non_renseigne';
        }

        $this->evaluations->saveAnswer($evaluationId, $code, $status, Http::input('justification'));

        $goTo = Http::input('go_to');
        if ($goTo !== '') {
            Http::redirect('/evaluations/' . $evaluationId . '/criteria/' . $goTo);
        }

        Http::flash('success', "Critère {$code} enregistré.");
        Http::redirect('/evaluations/' . $evaluationId);
    }

    public function updateMeta(array $params): void
    {
        $id = (int) $params['id'];
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/evaluations/' . $id);
        }

        $meta = [
            'date_realisation' => Http::input('date_realisation'),
            'perimetre_diagnostic' => Http::input('perimetre_diagnostic'),
            'plan_avancement' => Http::input('plan_avancement'),
            'frequence_revues' => Http::input('frequence_revues'),
            'score_vise' => Http::input('score_vise'),
            'score_vise_date' => Http::input('score_vise_date'),
        ];

        $this->evaluations->updateMeta($id, $meta);
        $this->evaluations->updateLabel($id, Http::input('label'));

        Http::flash('success', 'Informations générales mises à jour.');
        Http::redirect('/evaluations/' . $id);
    }

    public function complete(array $params): void
    {
        $id = (int) $params['id'];
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/evaluations/' . $id);
        }

        $board = $this->buildBoard($id);
        if ($board === null) {
            http_response_code(404);
            return;
        }

        $this->evaluations->markCompleted($id, (float) $board['scoring']['score']);
        Http::flash('success', 'Évaluation finalisée. Score : ' . $board['scoring']['score'] . ' %.');
        Http::redirect('/evaluations/' . $id);
    }

    public function reopen(array $params): void
    {
        $id = (int) $params['id'];
        if (!Csrf::check()) {
            Http::flash('error', 'Session expirée, merci de réessayer.');
            Http::redirect('/evaluations/' . $id);
        }

        $this->evaluations->reopen($id);
        Http::flash('success', 'Évaluation réouverte pour modification.');
        Http::redirect('/evaluations/' . $id);
    }

    public function delete(array $params): void
    {
        $id = (int) $params['id'];
        $evaluation = $this->evaluations->find($id);
        if (!Csrf::check() || $evaluation === null) {
            Http::redirect('/projects');
        }

        $projectId = (int) $evaluation['project_id'];
        $this->evaluations->delete($id);
        Http::flash('success', 'Évaluation supprimée.');
        Http::redirect('/projects/' . $projectId);
    }
}
