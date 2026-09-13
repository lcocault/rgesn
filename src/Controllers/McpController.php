<?php

declare(strict_types=1);

namespace Rgesn\Controllers;

use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Rgesn\Config;
use Rgesn\Database;
use Rgesn\Repositories\CriteriaRepository;
use Rgesn\Repositories\EvaluationRepository;
use Rgesn\Repositories\ProjectRepository;
use Rgesn\Services\ApplicabilityService;

final class McpController
{
    private ProjectRepository $projects;
    private EvaluationRepository $evaluations;
    private CriteriaRepository $criteria;

    public function __construct()
    {
        $db = Database::connection();
        $this->projects = new ProjectRepository($db);
        $this->evaluations = new EvaluationRepository($db);
        $this->criteria = new CriteriaRepository($db);
    }

    public function handle(): void
    {
        if (!$this->isAuthorized()) {
            $this->sendMcpError(null, -32001, 'Unauthorized', 401);
            return;
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendMcpError(null, -32700, 'Invalid JSON', 400);
            return;
        }
        if (!is_array($payload)) {
            $this->sendMcpError(null, -32600, 'Invalid request', 400);
            return;
        }
        if (array_is_list($payload)) {
            $this->sendMcpError(null, -32600, 'Batch requests are not supported', 400);
            return;
        }

        $hasId = array_key_exists('id', $payload);
        $id = $payload['id'] ?? null;
        $jsonRpcVersion = $payload['jsonrpc'] ?? null;
        $method = $payload['method'] ?? null;
        $hasParams = array_key_exists('params', $payload);
        $params = $payload['params'] ?? [];
        if ($hasParams && !is_array($params)) {
            $this->sendMcpError($hasId ? $id : null, -32600, 'Invalid request', 400);
            return;
        }

        if (!is_string($jsonRpcVersion) || $jsonRpcVersion !== '2.0' || !is_string($method) || $method === '') {
            $this->sendMcpError($hasId ? $id : null, -32600, 'Invalid request', 400);
            return;
        }

        try {
            $result = $this->dispatchMethod($method, $params);
        } catch (InvalidArgumentException $e) {
            if (!$hasId) {
                http_response_code(204);
                return;
            }
            $message = $e->getMessage();
            $code = str_starts_with($message, 'Method not found') || str_starts_with($message, 'Unknown tool:')
                ? -32601
                : -32602;
            $this->sendMcpError($id, $code, $message);
            return;
        } catch (\Throwable $e) {
            if (!$hasId) {
                http_response_code(204);
                return;
            }
            $this->sendMcpError($id, -32603, 'Internal error');
            return;
        }

        if (!$hasId) {
            http_response_code(204);
            return;
        }
        $this->sendMcpResult($id, $result);
    }

    private function dispatchMethod(string $method, array $params): array
    {
        return match ($method) {
            'initialize' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'rgesn-mcp', 'version' => '1.0.0'],
            ],
            'tools/list' => [
                'tools' => [
                    [
                        'name' => 'list_projects',
                        'description' => 'Liste les projets RGESN.',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ],
                    [
                        'name' => 'list_project_evaluations',
                        'description' => 'Liste les évaluations d’un projet et leur statut.',
                        'inputSchema' => [
                            'type' => 'object',
                            'required' => ['project_id'],
                            'properties' => ['project_id' => ['type' => 'integer']],
                        ],
                    ],
                    [
                        'name' => 'get_open_questions',
                        'description' => 'Retourne les questions ouvertes d’une évaluation en cours.',
                        'inputSchema' => [
                            'type' => 'object',
                            'required' => ['evaluation_id'],
                            'properties' => [
                                'evaluation_id' => ['type' => 'integer'],
                                'limit' => ['type' => 'integer'],
                                'offset' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'submit_answer',
                        'description' => 'Enregistre une réponse de critère sur une évaluation en cours.',
                        'inputSchema' => [
                            'type' => 'object',
                            'required' => ['evaluation_id', 'criteria_code', 'status'],
                            'properties' => [
                                'evaluation_id' => ['type' => 'integer'],
                                'criteria_code' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'enum' => ['valide', 'non_valide', 'non_applicable', 'non_renseigne']],
                                'justification' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            'tools/call' => $this->dispatchToolCall($params),
            default => throw new InvalidArgumentException('Method not found'),
        };
    }

    private function dispatchToolCall(array $params): array
    {
        $name = $params['name'] ?? null;
        $hasArguments = array_key_exists('arguments', $params);
        $arguments = $params['arguments'] ?? [];
        if (!is_string($name) || $name === '') {
            throw new InvalidArgumentException('Missing or invalid tool name');
        }
        if ($hasArguments && (!is_array($arguments) || array_is_list($arguments))) {
            throw new InvalidArgumentException('Missing or invalid tool arguments');
        }

        $data = match ($name) {
            'list_projects' => ['projects' => $this->listProjects()],
            'list_project_evaluations' => ['evaluations' => $this->listProjectEvaluations($this->requiredInt($arguments, 'project_id'))],
            'get_open_questions' => $this->getOpenQuestions(
                $this->requiredInt($arguments, 'evaluation_id'),
                $this->optionalInt($arguments, 'limit', 100, true),
                $this->optionalInt($arguments, 'offset', 0, false)
            ),
            'submit_answer' => $this->submitAnswer(
                $this->requiredInt($arguments, 'evaluation_id'),
                $this->requiredString($arguments, 'criteria_code'),
                $this->requiredString($arguments, 'status'),
                trim((string) ($arguments['justification'] ?? ''))
            ),
            default => throw new InvalidArgumentException('Unknown tool: ' . $name),
        };

        try {
            $textContent = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Unable to encode tool response');
        }

        return [
            'structuredContent' => $data,
            'content' => [
                ['type' => 'text', 'text' => $textContent],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listProjects(): array
    {
        return array_map(static function (array $project): array {
            return [
                'id' => (int) $project['id'],
                'name' => (string) $project['name'],
                'description' => (string) $project['description'],
                'evaluations_count' => (int) ($project['evaluations_count'] ?? 0),
                'last_score' => array_key_exists('last_score', $project) && $project['last_score'] !== null
                    ? (float) $project['last_score']
                    : null,
            ];
        }, $this->projects->all());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listProjectEvaluations(int $projectId): array
    {
        if ($this->projects->find($projectId) === null) {
            throw new InvalidArgumentException('Unknown project_id: ' . $projectId);
        }

        return array_map(static function (array $evaluation): array {
            return [
                'id' => (int) $evaluation['id'],
                'project_id' => (int) $evaluation['project_id'],
                'label' => (string) $evaluation['label'],
                'status' => (string) $evaluation['status'],
                'is_in_progress' => $evaluation['status'] === 'in_progress',
                'score' => $evaluation['score'] !== null ? (float) $evaluation['score'] : null,
                'created_at' => (string) $evaluation['created_at'],
                'updated_at' => (string) $evaluation['updated_at'],
                'completed_at' => $evaluation['completed_at'] !== null ? (string) $evaluation['completed_at'] : null,
            ];
        }, $this->evaluations->forProject($projectId));
    }

    private function getOpenQuestions(int $evaluationId, int $limit, int $offset): array
    {
        $evaluation = $this->evaluations->find($evaluationId);
        if ($evaluation === null) {
            throw new InvalidArgumentException('Unknown evaluation_id: ' . $evaluationId);
        }
        if ($evaluation['status'] !== 'in_progress') {
            throw new InvalidArgumentException('Evaluation is not in_progress');
        }

        $allCriteria = $this->criteria->all();
        $answers = $this->evaluations->answers($evaluationId);
        $applicability = new ApplicabilityService(
            $this->criteria->gatingTagsByCriteria(),
            $this->evaluations->gatingAnswers($evaluationId)
        );

        $openQuestions = [];
        $total = 0;
        foreach ($allCriteria as $criterion) {
            $code = (string) $criterion['code'];
            if (isset($answers[$code])) {
                continue;
            }
            if (!$applicability->isDecidable($code)) {
                continue;
            }
            if ($applicability->isAutoNotApplicable($code)) {
                continue;
            }

            $total++;
            if ($total <= $offset || count($openQuestions) >= $limit) {
                continue;
            }

            $openQuestions[] = [
                'criteria_code' => $code,
                'theme_code' => (int) $criterion['theme_code'],
                'theme_label' => (string) $criterion['theme_label'],
                'priority' => (string) $criterion['priority'],
                'difficulty' => $criterion['difficulty'] !== null ? (string) $criterion['difficulty'] : null,
                'question' => (string) $criterion['question'],
                'applicability_condition' => (string) $criterion['applicability_condition'],
                'metiers' => (string) $criterion['metiers'],
                'objectif' => (string) $criterion['objectif'],
                'mise_en_oeuvre' => (string) $criterion['mise_en_oeuvre'],
                'moyen_test' => (string) $criterion['moyen_test'],
                'pour_aller_plus_loin' => $criterion['pour_aller_plus_loin'] !== null ? (string) $criterion['pour_aller_plus_loin'] : null,
            ];
        }

        return [
            'evaluation_id' => $evaluationId,
            'status' => (string) $evaluation['status'],
            'total_open_questions' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'open_questions' => $openQuestions,
        ];
    }

    private function submitAnswer(int $evaluationId, string $criteriaCode, string $status, string $justification): array
    {
        $evaluation = $this->evaluations->find($evaluationId);
        if ($evaluation === null) {
            throw new InvalidArgumentException('Unknown evaluation_id: ' . $evaluationId);
        }
        if ($evaluation['status'] !== 'in_progress') {
            throw new InvalidArgumentException('Evaluation is not in_progress');
        }

        $criterion = $this->criteria->find($criteriaCode);
        if ($criterion === null) {
            throw new InvalidArgumentException('Unknown criteria_code: ' . $criteriaCode);
        }

        $allowed = ['valide', 'non_valide', 'non_applicable', 'non_renseigne'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status (allowed: valide, non_valide, non_applicable, non_renseigne)');
        }

        $answers = $this->evaluations->answers($evaluationId);
        if (isset($answers[$criteriaCode])) {
            throw new InvalidArgumentException('Criterion already answered');
        }

        $applicability = new ApplicabilityService(
            $this->criteria->gatingTagsByCriteria(),
            $this->evaluations->gatingAnswers($evaluationId)
        );
        if (!$applicability->isDecidable($criteriaCode)) {
            throw new InvalidArgumentException('Criterion gating questions are not all answered');
        }
        if ($applicability->isAutoNotApplicable($criteriaCode)) {
            throw new InvalidArgumentException('Criterion is automatically non_applicable');
        }

        $this->evaluations->saveAnswer($evaluationId, $criteriaCode, $status, $justification);

        return [
            'evaluation_id' => $evaluationId,
            'criteria_code' => $criteriaCode,
            'status' => $status,
            'justification' => $justification,
            'saved' => true,
        ];
    }

    private function requiredInt(array $data, string $key): int
    {
        if (!array_key_exists($key, $data)) {
            throw new InvalidArgumentException('Missing or invalid ' . $key);
        }

        $value = $data[$key];
        if (is_int($value)) {
            if ($value <= 0) {
                throw new InvalidArgumentException('Missing or invalid ' . $key);
            }
            return $value;
        }

        if (!is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new InvalidArgumentException('Missing or invalid ' . $key);
        }

        $intValue = (int) $value;
        if ($intValue <= 0) {
            throw new InvalidArgumentException('Missing or invalid ' . $key);
        }

        return $intValue;
    }

    private function requiredString(array $data, string $key): string
    {
        $value = trim((string) ($data[$key] ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException('Missing ' . $key);
        }

        return $value;
    }

    private function optionalInt(array $data, string $key, int $default, bool $strictlyPositive): int
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (is_int($value)) {
            $intValue = $value;
        } elseif (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            $intValue = (int) $value;
        } else {
            throw new InvalidArgumentException('Missing or invalid ' . $key);
        }

        if ($strictlyPositive && $intValue <= 0) {
            throw new InvalidArgumentException('Missing or invalid ' . $key);
        }
        if (!$strictlyPositive && $intValue < 0) {
            throw new InvalidArgumentException('Missing or invalid ' . $key);
        }

        return $intValue;
    }

    private function isAuthorized(): bool
    {
        $password = Config::accessPassword();
        if ($password === null || $password === '') {
            return false;
        }

        $authorization = (string) (
            $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $this->authorizationHeaderFromGetAllHeaders()
            ?? ''
        );
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
            return hash_equals($password, trim($matches[1]));
        }

        return false;
    }

    private function sendMcpResult(mixed $id, array $result): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => $result,
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            http_response_code(500);
            echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
        }
    }

    private function sendMcpError(mixed $id, int $code, string $message, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        try {
            echo json_encode([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => $code, 'message' => $message],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            http_response_code(500);
            echo '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}';
        }
    }

    private function authorizationHeaderFromGetAllHeaders(): ?string
    {
        if (!function_exists('getallheaders')) {
            return null;
        }

        $headers = getallheaders();
        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value) && strtolower($name) === 'authorization') {
                return $value;
            }
        }

        return null;
    }
}
