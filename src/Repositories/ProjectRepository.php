<?php

declare(strict_types=1);

namespace Rgesn\Repositories;

use PDO;

final class ProjectRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            'SELECT p.*,
                    (SELECT COUNT(*) FROM evaluations e WHERE e.project_id = p.id) AS evaluations_count,
                    (SELECT e.score FROM evaluations e WHERE e.project_id = p.id AND e.status = \'completed\'
                        ORDER BY e.completed_at DESC LIMIT 1) AS last_score
             FROM projects p
             ORDER BY p.name'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function create(string $name, string $description): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO projects (name, description) VALUES (:name, :description) RETURNING id'
        );
        $stmt->execute(['name' => $name, 'description' => $description]);

        return (int) $stmt->fetchColumn();
    }

    public function update(int $id, string $name, string $description): void
    {
        $stmt = $this->db->prepare(
            'UPDATE projects SET name = :name, description = :description, updated_at = now() WHERE id = :id'
        );
        $stmt->execute(['name' => $name, 'description' => $description, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM projects WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
