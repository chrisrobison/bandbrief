<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\BandcampAdapter;
use App\Adapters\LastfmAdapter;
use App\Adapters\MusicbrainzAdapter;
use App\Adapters\SpotifyAdapter;
use App\Adapters\WikipediaAdapter;
use App\Core\Db;
use App\Repositories\ArtistRepository;
use App\Support\HttpClient;

final class ArtistService
{
    private ArtistRepository $artistRepository;
    private Resolver $resolver;
    private MusicbrainzAdapter $musicbrainzAdapter;
    private SpotifyAdapter $spotifyAdapter;
    private LastfmAdapter $lastfmAdapter;
    private WikipediaAdapter $wikipediaAdapter;
    private BandcampAdapter $bandcampAdapter;

    public function __construct()
    {
        $this->artistRepository = new ArtistRepository(Db::pdo());
        $this->resolver = new Resolver();

        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../Config/config.php';
        $http = new HttpClient();

        $this->musicbrainzAdapter = new MusicbrainzAdapter($http, (array) ($config['sources']['musicbrainz'] ?? []));
        $this->spotifyAdapter = new SpotifyAdapter($http, (array) ($config['sources']['spotify'] ?? []));
        $this->lastfmAdapter = new LastfmAdapter($http, (array) ($config['sources']['lastfm'] ?? []));
        $this->wikipediaAdapter = new WikipediaAdapter($http, []);
        $this->bandcampAdapter = new BandcampAdapter($http, (array) ($config['sources']['bandcamp'] ?? []));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        $local = $this->artistRepository->findByNameLike($trimmed, $limit);
        $normalized = [];

        foreach ($local as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['canonical_name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $key = $this->normalizeForKey($name);
            $normalized[$key] = [
                'id' => (int) ($item['id'] ?? 0),
                'canonical_name' => $name,
                'display_name' => (string) ($item['display_name'] ?? $name),
                'resolver_confidence' => (float) ($item['resolver_confidence'] ?? 0.0),
                'match_type' => 'cached_match',
                'source_origin' => ['local_db'],
                'created_at' => $item['created_at'] ?? null,
                'updated_at' => $item['updated_at'] ?? null,
            ];
        }

        $needsRemoteLookup = strlen($trimmed) >= 3 && count($normalized) < max(3, (int) floor($limit * 0.6));
        if ($needsRemoteLookup) {
            $identitySources = $this->collectIdentitySources($trimmed);
            $remote = $this->buildSearchCandidates($trimmed, $identitySources);

            foreach ($remote as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $key = $this->normalizeForKey((string) ($candidate['canonical_name'] ?? ''));
                if ($key === '') {
                    continue;
                }

                if (!isset($normalized[$key])) {
                    $normalized[$key] = $candidate;
                    continue;
                }

                $existing = $normalized[$key];
                $existingOrigins = is_array($existing['source_origin'] ?? null) ? $existing['source_origin'] : [];
                $candidateOrigins = is_array($candidate['source_origin'] ?? null) ? $candidate['source_origin'] : [];
                $combinedOrigins = array_values(array_unique(array_merge($existingOrigins, $candidateOrigins)));

                $normalized[$key]['source_origin'] = $combinedOrigins;
                $normalized[$key]['resolver_confidence'] = max(
                    (float) ($existing['resolver_confidence'] ?? 0.0),
                    (float) ($candidate['resolver_confidence'] ?? 0.0)
                );

                if (($normalized[$key]['match_type'] ?? '') === 'cached_match' && ($candidate['match_type'] ?? '') !== '') {
                    $normalized[$key]['match_type'] = $candidate['match_type'];
                }
            }
        }

        $items = array_values($normalized);
        usort(
            $items,
            static fn(array $a, array $b): int => (($b['resolver_confidence'] ?? 0.0) <=> ($a['resolver_confidence'] ?? 0.0))
        );

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $artistName): array
    {
        $identitySources = $this->collectIdentitySources($artistName);
        $identity = $this->resolver->resolve($artistName, $identitySources);

        $canonicalName = (string) ($identity['canonical_name'] ?? $artistName);
        $confidence = (float) ($identity['confidence'] ?? 0.0);

        $artistId = $this->artistRepository->upsertArtist(
            $canonicalName,
            $canonicalName,
            $confidence,
            [
                'requested_name' => $artistName,
                'match_type' => (string) ($identity['match_type'] ?? 'no_trustworthy_match'),
                'resolution_explanation' => (string) ($identity['explanation'] ?? ''),
                'resolution_detail' => $identity['explainability'] ?? [],
            ]
        );

        foreach ($identitySources as $source => $payload) {
            $profile = $payload['payload']['profile'] ?? [];
            if (!is_array($profile)) {
                continue;
            }

            $sourceName = (string) $source;
            $sourceConfidence = (float) ($payload['confidence'] ?? 0.0);

            $primaryName = trim((string) ($profile['name'] ?? ''));
            if ($primaryName !== '') {
                $this->artistRepository->saveAlias($artistId, $primaryName, $sourceName, $sourceConfidence);
            }

            $payloadAliases = $payload['payload']['aliases'] ?? [];
            if (is_array($payloadAliases)) {
                foreach ($payloadAliases as $aliasValue) {
                    $alias = trim((string) $aliasValue);
                    if ($alias === '') {
                        continue;
                    }
                    $this->artistRepository->saveAlias($artistId, $alias, $sourceName, max(0.45, $sourceConfidence * 0.92));
                }
            }

            $url = trim((string) ($profile['url'] ?? ''));
            if ($url !== '' || $primaryName !== '') {
                $externalId = (string) ($profile['external_id'] ?? $primaryName ?: $artistName);
                $this->artistRepository->saveExternalProfile(
                    $artistId,
                    $sourceName,
                    $externalId,
                    $url,
                    $primaryName,
                    $profile
                );
            }
        }

        return [
            'artist_id' => $artistId,
            'canonical_name' => $canonicalName,
            'identity' => $identity,
            'source_groups' => [
                'identity_sources' => $identitySources,
                'signal_sources' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function view(int $artistId): array
    {
        $artist = $this->artistRepository->findById($artistId);

        if (!is_array($artist)) {
            return [];
        }

        return [
            'artist' => $artist,
            'aliases' => $this->artistRepository->aliases($artistId),
            'external_profiles' => $this->artistRepository->externalProfiles($artistId),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectIdentitySources(string $artistName): array
    {
        return [
            'musicbrainz' => $this->musicbrainzAdapter->fetchArtist($artistName),
            'spotify' => $this->spotifyAdapter->fetchArtist($artistName),
            'lastfm' => $this->lastfmAdapter->fetchArtist($artistName),
            'wikipedia' => $this->wikipediaAdapter->fetchArtist($artistName),
            'bandcamp' => $this->bandcampAdapter->fetchArtist($artistName),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $identitySources
     * @return array<int, array<string, mixed>>
     */
    private function buildSearchCandidates(string $requestedName, array $identitySources): array
    {
        $rows = [];

        foreach ($identitySources as $sourceName => $sourceData) {
            $status = (string) ($sourceData['status'] ?? 'error');
            if ($status !== 'ok' && $status !== 'partial') {
                continue;
            }

            $profile = $sourceData['payload']['profile'] ?? [];
            if (is_array($profile)) {
                $profileName = trim((string) ($profile['name'] ?? $profile['title'] ?? ''));
                if ($profileName !== '') {
                    $rows[] = [
                        'canonical_name' => $profileName,
                        'display_name' => $profileName,
                        'resolver_confidence' => $this->clampSearchConfidence(
                            (float) ($sourceData['confidence'] ?? 0.0),
                            $this->nameSimilarity($requestedName, $profileName)
                        ),
                        'match_type' => 'remote_candidate',
                        'source_origin' => [(string) $sourceName],
                    ];
                }
            }

            $searchCandidates = $sourceData['payload']['search_candidates'] ?? [];
            if (!is_array($searchCandidates)) {
                continue;
            }

            foreach ($searchCandidates as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $candidateName = trim((string) ($candidate['name'] ?? ''));
                if ($candidateName === '') {
                    continue;
                }

                $rows[] = [
                    'canonical_name' => $candidateName,
                    'display_name' => $candidateName,
                    'resolver_confidence' => $this->clampSearchConfidence(
                        (float) ($candidate['score'] ?? 0.0),
                        $this->nameSimilarity($requestedName, $candidateName)
                    ),
                    'match_type' => 'remote_candidate',
                    'source_origin' => [(string) $sourceName],
                ];
            }
        }

        $merged = [];
        foreach ($rows as $row) {
            $key = $this->normalizeForKey((string) ($row['canonical_name'] ?? ''));
            if ($key === '') {
                continue;
            }

            if (!isset($merged[$key])) {
                $merged[$key] = $row + [
                    'id' => null,
                    'created_at' => null,
                    'updated_at' => null,
                ];
                continue;
            }

            $existing = $merged[$key];
            $existingOrigins = is_array($existing['source_origin'] ?? null) ? $existing['source_origin'] : [];
            $rowOrigins = is_array($row['source_origin'] ?? null) ? $row['source_origin'] : [];

            $merged[$key]['resolver_confidence'] = max(
                (float) ($existing['resolver_confidence'] ?? 0.0),
                (float) ($row['resolver_confidence'] ?? 0.0)
            );
            $merged[$key]['source_origin'] = array_values(array_unique(array_merge($existingOrigins, $rowOrigins)));
        }

        $items = array_values($merged);
        usort(
            $items,
            static fn(array $a, array $b): int => (($b['resolver_confidence'] ?? 0.0) <=> ($a['resolver_confidence'] ?? 0.0))
        );

        return array_slice($items, 0, 12);
    }

    private function normalizeForKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/^the\s+/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $normalized) ?? $normalized;

        return $normalized;
    }

    private function nameSimilarity(string $left, string $right): float
    {
        $a = $this->normalizeForKey($left);
        $b = $this->normalizeForKey($right);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $max = max(strlen($a), strlen($b));
        if ($max === 0) {
            return 0.0;
        }

        $distance = levenshtein($a, $b);
        $score = 1 - ($distance / $max);

        return max(0.0, min(1.0, round($score, 4)));
    }

    private function clampSearchConfidence(float $sourceConfidence, float $nameSimilarity): float
    {
        $value = ($sourceConfidence * 0.7) + ($nameSimilarity * 0.3);

        return max(0.0, min(1.0, round($value, 4)));
    }
}
