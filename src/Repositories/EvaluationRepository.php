<?php

declare(strict_types=1);

namespace Rgesn\Repositories;

use PDO;

final class EvaluationRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(int $projectId, string $label): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO evaluations (project_id, label) VALUES (:project_id, :label) RETURNING id'
        );
        $stmt->execute(['project_id' => $projectId, 'label' => $label]);

        return (int) $stmt->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM evaluations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forProject(int $projectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM evaluations WHERE project_id = :project_id ORDER BY created_at DESC'
        );
        $stmt->execute(['project_id' => $projectId]);

        return $stmt->fetchAll();
    }

    /**
     * Dernière évaluation complétée pour un projet, différente de $excludeId, la plus récente.
     */
    public function lastCompletedBefore(int $projectId, int $excludeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM evaluations
             WHERE project_id = :project_id AND status = 'completed' AND id != :exclude_id
             ORDER BY completed_at DESC LIMIT 1"
        );
        $stmt->execute(['project_id' => $projectId, 'exclude_id' => $excludeId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function updateMeta(int $id, array $meta): void
    {
        $stmt = $this->db->prepare('UPDATE evaluations SET meta = :meta, updated_at = now() WHERE id = :id');
        $stmt->execute(['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE), 'id' => $id]);
    }

    public function updateLabel(int $id, string $label): void
    {
        $stmt = $this->db->prepare('UPDATE evaluations SET label = :label, updated_at = now() WHERE id = :id');
        $stmt->execute(['label' => $label, 'id' => $id]);
    }

    public function markCompleted(int $id, float $score): void
    {
        $stmt = $this->db->prepare(
            "UPDATE evaluations SET status = 'completed', score = :score, completed_at = now(), updated_at = now() WHERE id = :id"
        );
        $stmt->execute(['score' => $score, 'id' => $id]);
    }

    public function reopen(int $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE evaluations SET status = 'in_progress', updated_at = now() WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM evaluations WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // --- Réponses de filtrage (gating) ---

    /**
     * @return array<string, bool> tag => valeur
     */
    public function gatingAnswers(int $evaluationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT gating_tag, value FROM evaluation_gating_answers WHERE evaluation_id = :id'
        );
        $stmt->execute(['id' => $evaluationId]);

        $answers = [];
        foreach ($stmt->fetchAll() as $row) {
            $answers[$row['gating_tag']] = $row['value'] === 't' || $row['value'] === true || $row['value'] === '1';
        }

        return $answers;
    }

    public function saveGatingAnswer(int $evaluationId, string $tag, bool $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO evaluation_gating_answers (evaluation_id, gating_tag, value)
             VALUES (:evaluation_id, :tag, :value)
             ON CONFLICT (evaluation_id, gating_tag) DO UPDATE SET value = EXCLUDED.value'
        );
        $stmt->bindValue('evaluation_id', $evaluationId, PDO::PARAM_INT);
        $stmt->bindValue('tag', $tag, PDO::PARAM_STR);
        $stmt->bindValue('value', $value, PDO::PARAM_BOOL);
        $stmt->execute();
    }

    // --- Réponses aux critères ---

    /**
     * @return array<string, array<string, mixed>> code critère => réponse
     */
    public function answers(int $evaluationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM evaluation_answers WHERE evaluation_id = :id'
        );
        $stmt->execute(['id' => $evaluationId]);

        $answers = [];
        foreach ($stmt->fetchAll() as $row) {
            $answers[$row['criteria_code']] = $row;
        }

        return $answers;
    }

    public function saveAnswer(int $evaluationId, string $criteriaCode, string $status, string $justification): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO evaluation_answers (evaluation_id, criteria_code, status, justification, updated_at)
             VALUES (:evaluation_id, :code, :status, :justification, now())
             ON CONFLICT (evaluation_id, criteria_code)
             DO UPDATE SET status = EXCLUDED.status, justification = EXCLUDED.justification, updated_at = now()'
        );
        $stmt->execute([
            'evaluation_id' => $evaluationId,
            'code' => $criteriaCode,
            'status' => $status,
            'justification' => $justification,
        ]);
    }

    public function deleteAnswer(int $evaluationId, string $criteriaCode): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM evaluation_answers WHERE evaluation_id = :evaluation_id AND criteria_code = :code'
        );
        $stmt->execute(['evaluation_id' => $evaluationId, 'code' => $criteriaCode]);
    }
}
