<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ArtistRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByNameLike(string $query, int $limit = 10): array
    {
        $sql = 'SELECT id, canonical_name, display_name, resolver_confidence, created_at, updated_at
                FROM artists
                WHERE canonical_name LIKE :q OR display_name LIKE :q
                ORDER BY updated_at DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':q', '%' . $query . '%', PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $artistId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artists WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $artistId, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByCanonicalName(string $canonicalName): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM artists WHERE canonical_name = :canonical_name LIMIT 1');
        $stmt->bindValue(':canonical_name', $canonicalName, PDO::PARAM_STR);
        $stmt->execute();
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function upsertArtist(string $canonicalName, string $displayName, float $resolverConfidence, array $metadata = []): int
    {
        $existing = $this->findByCanonicalName($canonicalName);

        if (is_array($existing)) {
            $stmt = $this->pdo->prepare('UPDATE artists SET display_name = :display_name, resolver_confidence = :resolver_confidence, metadata_json = :metadata_json, updated_at = NOW() WHERE id = :id');
            $stmt->bindValue(':display_name', $displayName, PDO::PARAM_STR);
            $stmt->bindValue(':resolver_confidence', $resolverConfidence);
            $stmt->bindValue(':metadata_json', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
            $stmt->bindValue(':id', (int) $existing['id'], PDO::PARAM_INT);
            $stmt->execute();

            return (int) $existing['id'];
        }

        $stmt = $this->pdo->prepare('INSERT INTO artists (canonical_name, display_name, resolver_confidence, metadata_json, created_at, updated_at) VALUES (:canonical_name, :display_name, :resolver_confidence, :metadata_json, NOW(), NOW())');
        $stmt->bindValue(':canonical_name', $canonicalName, PDO::PARAM_STR);
        $stmt->bindValue(':display_name', $displayName, PDO::PARAM_STR);
        $stmt->bindValue(':resolver_confidence', $resolverConfidence);
        $stmt->bindValue(':metadata_json', json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    public function saveAlias(int $artistId, string $alias, string $source, float $confidence): void
    {
        $sql = 'INSERT INTO artist_aliases (artist_id, alias, source, confidence, created_at)
                VALUES (:artist_id, :alias, :source, :confidence, NOW())
                ON DUPLICATE KEY UPDATE confidence = VALUES(confidence), source = VALUES(source)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->bindValue(':alias', $alias, PDO::PARAM_STR);
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':confidence', $confidence);
        $stmt->execute();
    }

    public function saveExternalProfile(
        int $artistId,
        string $source,
        string $externalId,
        string $url,
        string $username,
        array $payload = []
    ): void {
        $sql = 'INSERT INTO external_profiles (artist_id, source, external_id, url, username, payload_json, fetched_at, created_at, updated_at)
                VALUES (:artist_id, :source, :external_id, :url, :username, :payload_json, NOW(), NOW(), NOW())
                ON DUPLICATE KEY UPDATE url = VALUES(url), username = VALUES(username), payload_json = VALUES(payload_json), fetched_at = NOW(), updated_at = NOW()';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->bindValue(':source', $source, PDO::PARAM_STR);
        $stmt->bindValue(':external_id', $externalId, PDO::PARAM_STR);
        $stmt->bindValue(':url', $url, PDO::PARAM_STR);
        $stmt->bindValue(':username', $username, PDO::PARAM_STR);
        $stmt->bindValue(':payload_json', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PDO::PARAM_STR);
        $stmt->execute();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function aliases(int $artistId): array
    {
        $stmt = $this->pdo->prepare('SELECT alias, source, confidence FROM artist_aliases WHERE artist_id = :artist_id ORDER BY confidence DESC, alias ASC');
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function externalProfiles(int $artistId): array
    {
        $stmt = $this->pdo->prepare('SELECT source, external_id, url, username, fetched_at, payload_json FROM external_profiles WHERE artist_id = :artist_id ORDER BY source ASC');
        $stmt->bindValue(':artist_id', $artistId, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
            $row['payload'] = is_array($decoded) ? $decoded : [];
            unset($row['payload_json']);
        }

        return $rows;
    }
}
