<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ReportRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function startIngestionRun(int $artistId): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO ingestion_runs (artist_id, status, started_at, created_at, updated_at) VALUES (:artist_id, :status, NOW(), NOW(), NOW())');
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->bindValue(':status', 'running', PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function finishIngestionRun(int $runId, string $status): void
    {
        $stmt = $this->pdo->prepare('UPDATE ingestion_runs SET status = :status, finished_at = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':id', $runId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveSourceSnapshot(
        int $runId,
        int $artistId,
        string $source,
        string $status,
        float $confidence,
        string $collectionMethod,
        array $payload
    ): void {
        $stmt = $this->pdo->prepare('INSERT INTO source_snapshots (ingestion_run_id, artist_id, source, status, confidence, collection_method, payload_json, fetched_at, created_at)
                                     VALUES (:ingestion_run_id, :artist_id, :source, :status, :confidence, :collection_method, :payload_json, NOW(), NOW())');
        $stmt->bindValue(':ingestion_run_id', $runId, PDO::PARAM_INT);
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':confidence', $confidence);
        $stmt->bindValue(':collection_method', $collectionMethod, PDO::PARAM_STR);
        $stmt->bindValue(':payload_json', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function saveSourceError(int $runId, int $artistId, string $source, string $code, string $message, array $context = []): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO source_errors (ingestion_run_id, artist_id, source, error_code, error_message, context_json, created_at)
                                     VALUES (:ingestion_run_id, :artist_id, :source, :error_code, :error_message, :context_json, NOW())');
        $stmt->bindValue(':ingestion_run_id', $runId, PDO::PARAM_INT);
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':error_code', $code, PDO::PARAM_STR);
        $stmt->bindValue(':error_message', $message, PDO::PARAM_STR);
        $stmt->bindValue(':context_json', json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @param array<string, mixed> $reportJson
     */
    public function saveReport(int $artistId, int $runId, string $status, int $score, float $confidence, array $reportJson): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO reports (artist_id, ingestion_run_id, status, bandbrief_score, confidence, report_json, created_at, updated_at)
                                     VALUES (:artist_id, :ingestion_run_id, :status, :bandbrief_score, :confidence, :report_json, NOW(), NOW())');
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->bindValue(':ingestion_run_id', $runId, PDO::PARAM_INT);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':bandbrief_score', $score, PDO::PARAM_INT);
        $stmt->bindValue(':confidence', $confidence);
        $stmt->bindValue(':report_json', json_encode($reportJson, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int, array{name: string, content: array<string, mixed>}> $sections
     */
    public function saveReportSections(int $reportId, array $sections): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO report_sections (report_id, section_name, section_json, created_at, updated_at)
                                     VALUES (:report_id, :section_name, :section_json, NOW(), NOW())');

        foreach ($sections as $section) {
            $stmt->bindValue(':report_id', $reportId, PDO::PARAM_INT);
            $stmt->bindValue(':section_name', $section['name'], PDO::PARAM_STR);
            $stmt->bindValue(':section_json', json_encode($section['content'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
            $stmt->execute();
        }
    }

    /**
     * @param array<string, mixed> $scoreData
     */
    public function saveScoreBreakdown(int $reportId, array $scoreData): void
    {
        $rows = $scoreData['breakdown'] ?? [];
        if (!is_array($rows)) {
            return;
        }

        $stmt = $this->pdo->prepare('INSERT INTO score_breakdowns (report_id, category, score, weight, weighted_score, explanation, inputs_json, created_at)
                                     VALUES (:report_id, :category, :score, :weight, :weighted_score, :explanation, :inputs_json, NOW())');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $stmt->bindValue(':report_id', $reportId, PDO::PARAM_INT);
            $stmt->bindValue(':category', (string) ($row['category'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':score', (int) (($row['score'] ?? 0) ?: 0), PDO::PARAM_INT);
            $stmt->bindValue(':weight', (float) (($row['weight'] ?? 0.0) ?: 0.0));
            $stmt->bindValue(':weighted_score', (float) (($row['weighted'] ?? 0.0) ?: 0.0));
            $stmt->bindValue(':explanation', (string) ($row['explanation'] ?? ''), PDO::PARAM_STR);
            $stmt->bindValue(':inputs_json', json_encode($row['inputs'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
            $stmt->execute();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $reportId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reports WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $reportId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $decoded = json_decode((string) ($row['report_json'] ?? ''), true);
        $row['report'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestByArtist(int $artistId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM reports WHERE artist_id = :artist_id ORDER BY created_at DESC LIMIT 1');
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        $decoded = json_decode((string) ($row['report_json'] ?? ''), true);
        $row['report'] = is_array($decoded) ? $decoded : [];

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(int $reportId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, artist_id, ingestion_run_id, status, created_at, updated_at FROM reports WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $reportId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }
}
