<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Support\HttpClient;

final class MusicbrainzAdapter extends BaseAdapter
{
    private const API_BASE = 'https://musicbrainz.org/ws/2';

    private static float $lastRequestAt = 0.0;

    public function sourceName(): string
    {
        return 'musicbrainz';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(HttpClient $http, array $config = [])
    {
        parent::__construct($http, $config);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchArtist(string $artistName): array
    {
        $query = trim($artistName);
        if ($query === '') {
            return $this->partial('official_api', 0.0, ['match' => null], ['Artist name is required']);
        }

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent(),
        ];

        $searchResponse = $this->requestJson(
            self::API_BASE . '/artist?fmt=json&limit=5&query=' . rawurlencode('artist:"' . $query . '"'),
            $headers
        );

        if (!$searchResponse['ok']) {
            return $this->failed('official_api', 'musicbrainz_search_failed', 'MusicBrainz artist search failed');
        }

        $artists = $searchResponse['data']['artists'] ?? [];
        if (!is_array($artists) || $artists === []) {
            return $this->partial('official_api', 0.0, ['search_candidates' => []], ['No MusicBrainz artist match found']);
        }

        $candidates = $this->buildCandidates($query, $artists);
        if ($candidates === []) {
            return $this->partial('official_api', 0.0, ['search_candidates' => []], ['No MusicBrainz candidate could be parsed']);
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => (($b['score'] ?? 0.0) <=> ($a['score'] ?? 0.0))
        );

        $top = $candidates[0] ?? null;
        if (!is_array($top) || (string) ($top['id'] ?? '') === '') {
            return $this->partial('official_api', 0.0, ['search_candidates' => $candidates], ['No MusicBrainz candidate could be selected']);
        }

        $isAmbiguous = $this->isAmbiguousTopCandidate($candidates);
        $topScore = (float) ($top['score'] ?? 0.0);

        if ($topScore < 0.45) {
            return $this->partial('official_api', $topScore, [
                'search_candidates' => $candidates,
                'match' => null,
            ], ['MusicBrainz confidence too low for a trustworthy canonical profile']);
        }

        $artistId = (string) $top['id'];
        $detailResponse = $this->requestJson(
            self::API_BASE . '/artist/' . rawurlencode($artistId) . '?fmt=json&inc=aliases+url-rels',
            $headers
        );

        if (!$detailResponse['ok']) {
            return $this->partial('official_api', $topScore, [
                'search_candidates' => $candidates,
                'match' => $top,
            ], ['MusicBrainz canonical artist lookup failed']);
        }

        $artist = $detailResponse['data'];
        if (!is_array($artist)) {
            return $this->partial('official_api', $topScore, [
                'search_candidates' => $candidates,
                'match' => $top,
            ], ['MusicBrainz canonical artist response was invalid']);
        }

        $releaseErrors = [];
        $releases = $this->fetchReleaseGroups($artistId, $headers, $releaseErrors);

        $aliases = $this->extractAliases($artist);
        $profileName = trim((string) ($artist['name'] ?? (string) ($top['name'] ?? $query)));
        $officialUrls = $this->extractOfficialUrls($artist);

        $payload = [
            'search_candidates' => array_slice($candidates, 0, 5),
            'profile' => [
                'external_id' => $artistId,
                'name' => $profileName,
                'sort_name' => (string) ($artist['sort-name'] ?? ''),
                'type' => (string) ($artist['type'] ?? ''),
                'country' => (string) ($artist['country'] ?? ''),
                'disambiguation' => (string) ($artist['disambiguation'] ?? ''),
                'area' => (string) (($artist['area']['name'] ?? '') ?: ''),
                'life_span' => [
                    'begin' => (string) (($artist['life-span']['begin'] ?? '') ?: ''),
                    'end' => (string) (($artist['life-span']['end'] ?? '') ?: ''),
                    'ended' => (bool) (($artist['life-span']['ended'] ?? false) ?: false),
                ],
                'url' => 'https://musicbrainz.org/artist/' . rawurlencode($artistId),
                'official_urls' => $officialUrls,
                'aliases' => $aliases,
            ],
            'aliases' => $aliases,
            'releases' => $releases,
            'ambiguity' => [
                'is_ambiguous' => $isAmbiguous,
                'second_candidate_score' => (float) (($candidates[1]['score'] ?? 0.0) ?: 0.0),
            ],
        ];

        $errors = $releaseErrors;
        if ($isAmbiguous) {
            $errors[] = 'MusicBrainz returned closely scored artist candidates; identity should be treated as ambiguous.';
        }

        $confidence = $this->clamp($topScore - ($isAmbiguous ? 0.12 : 0.0));

        if ($errors !== []) {
            return $this->partial('official_api', $confidence, $payload, $errors);
        }

        return $this->success('official_api', $confidence, $payload);
    }

    private function userAgent(): string
    {
        $configured = trim((string) ($this->config['user_agent'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return 'BandBrief/1.0 (+https://example.com/contact)';
    }

    /**
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    private function requestJson(string $url, array $headers): array
    {
        $this->respectRateLimit();

        return $this->http->getJson($url, $headers, 12);
    }

    private function respectRateLimit(): void
    {
        $now = microtime(true);
        $elapsed = $now - self::$lastRequestAt;

        if ($elapsed < 1.05) {
            $sleepMicros = (int) ((1.05 - $elapsed) * 1_000_000);
            if ($sleepMicros > 0) {
                usleep($sleepMicros);
            }
        }

        self::$lastRequestAt = microtime(true);
    }

    /**
     * @param array<int, mixed> $artists
     * @return array<int, array<string, mixed>>
     */
    private function buildCandidates(string $requestedName, array $artists): array
    {
        $rows = [];

        foreach ($artists as $artist) {
            if (!is_array($artist)) {
                continue;
            }

            $name = trim((string) ($artist['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $id = (string) ($artist['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $apiScoreRaw = (float) (($artist['score'] ?? 0.0) ?: 0.0);
            $apiScore = $this->clamp($apiScoreRaw / 100);
            $similarity = $this->similarity($requestedName, $name);

            $score = $this->clamp(($similarity * 0.65) + ($apiScore * 0.35));

            $rows[] = [
                'id' => $id,
                'name' => $name,
                'sort_name' => (string) ($artist['sort-name'] ?? ''),
                'disambiguation' => (string) ($artist['disambiguation'] ?? ''),
                'type' => (string) ($artist['type'] ?? ''),
                'country' => (string) ($artist['country'] ?? ''),
                'source_score' => $apiScore,
                'name_similarity' => $similarity,
                'score' => $score,
                'url' => 'https://musicbrainz.org/artist/' . rawurlencode($id),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     */
    private function isAmbiguousTopCandidate(array $candidates): bool
    {
        if (count($candidates) < 2) {
            return false;
        }

        $top = (float) ($candidates[0]['score'] ?? 0.0);
        $second = (float) ($candidates[1]['score'] ?? 0.0);

        return $top >= 0.58 && $second >= 0.52 && ($top - $second) <= 0.08;
    }

    /**
     * @param array<string, mixed> $artist
     * @return string[]
     */
    private function extractAliases(array $artist): array
    {
        $aliases = $artist['aliases'] ?? [];
        if (!is_array($aliases)) {
            return [];
        }

        $rows = [];
        foreach ($aliases as $alias) {
            if (!is_array($alias)) {
                continue;
            }
            $name = trim((string) ($alias['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $rows[] = $name;
        }

        return array_values(array_unique($rows));
    }

    /**
     * @param array<string, mixed> $artist
     * @return string[]
     */
    private function extractOfficialUrls(array $artist): array
    {
        $relations = $artist['relations'] ?? [];
        if (!is_array($relations)) {
            return [];
        }

        $acceptedTypes = [
            'official homepage' => true,
            'wikipedia' => true,
            'bandcamp' => true,
            'social network' => true,
            'streaming music' => true,
        ];

        $urls = [];

        foreach ($relations as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $type = strtolower(trim((string) ($relation['type'] ?? '')));
            if ($type === '' || !isset($acceptedTypes[$type])) {
                continue;
            }

            $url = trim((string) (($relation['url']['resource'] ?? '') ?: ''));
            if ($url === '') {
                continue;
            }

            $urls[] = $url;
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<string, string> $headers
     * @param string[] $errors
     * @return array<int, array<string, mixed>>
     */
    private function fetchReleaseGroups(string $artistId, array $headers, array &$errors): array
    {
        $url = self::API_BASE . '/release-group?fmt=json&artist=' . rawurlencode($artistId) . '&limit=12&offset=0&type=album|ep|single';
        $response = $this->requestJson($url, $headers);

        if (!$response['ok']) {
            $errors[] = 'MusicBrainz release-group lookup failed';
            return [];
        }

        $groups = $response['data']['release-groups'] ?? [];
        if (!is_array($groups)) {
            return [];
        }

        $rows = [];
        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $id = (string) ($group['id'] ?? '');
            $title = trim((string) ($group['title'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }

            $rows[] = [
                'source_id' => $id,
                'title' => $title,
                'release_date' => (string) ($group['first-release-date'] ?? ''),
                'release_type' => strtolower((string) (($group['primary-type'] ?? '') ?: 'release_group')),
                'secondary_types' => is_array($group['secondary-types'] ?? null) ? $group['secondary-types'] : [],
                'url' => 'https://musicbrainz.org/release-group/' . rawurlencode($id),
            ];
        }

        usort(
            $rows,
            static fn(array $a, array $b): int => strcmp((string) ($b['release_date'] ?? ''), (string) ($a['release_date'] ?? ''))
        );

        return array_slice($rows, 0, 12);
    }
}
