<?php

declare(strict_types=1);

namespace Rgesn\Repositories;

use PDO;

final class DeclarationRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function create(int $evaluationId, string $html): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO declarations (evaluation_id, html_content)
             VALUES (:evaluation_id, :html) RETURNING id'
        );
        $stmt->execute(['evaluation_id' => $evaluationId, 'html' => $html]);

        return (int) $stmt->fetchColumn();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM declarations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forEvaluation(int $evaluationId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, evaluation_id, generated_at FROM declarations
             WHERE evaluation_id = :id ORDER BY generated_at DESC'
        );
        $stmt->execute(['id' => $evaluationId]);

        return $stmt->fetchAll();
    }
}
