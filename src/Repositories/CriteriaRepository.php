<?php

declare(strict_types=1);

namespace Rgesn\Repositories;

use PDO;

final class CriteriaRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>> tous les critères, triés par thématique puis position.
     */
    public function all(): array
    {
        $stmt = $this->db->query(
            'SELECT * FROM criteria ORDER BY theme_code, position'
        );

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>> critères groupés par code de thématique (1..9).
     */
    public function allGroupedByTheme(): array
    {
        $grouped = [];
        foreach ($this->all() as $criterion) {
            $grouped[(int) $criterion['theme_code']][] = $criterion;
        }
        ksort($grouped);

        return $grouped;
    }

    public function find(string $code): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM criteria WHERE code = :code');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>> toutes les questions de filtrage, triées par position.
     */
    public function gatingQuestions(): array
    {
        $stmt = $this->db->query('SELECT * FROM gating_questions ORDER BY position');

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, string[]> tags de filtrage requis par critère (code => [tags]).
     */
    public function gatingTagsByCriteria(): array
    {
        $stmt = $this->db->query('SELECT criteria_code, gating_tag FROM criteria_gating_tags');
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[$row['criteria_code']][] = $row['gating_tag'];
        }

        return $map;
    }

    public function countAll(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM criteria')->fetchColumn();
    }
}
