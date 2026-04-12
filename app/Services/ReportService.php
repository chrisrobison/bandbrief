<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\BandcampAdapter;
use App\Adapters\LastfmAdapter;
use App\Adapters\RedditAdapter;
use App\Adapters\SpotifyAdapter;
use App\Adapters\WikipediaAdapter;
use App\Core\Db;
use App\Core\ApiException;
use App\Repositories\ArtistRepository;
use App\Repositories\ReportRepository;
use App\Reporting\ReportBuilder;
use App\Scoring\Scorer;
use App\Support\HttpClient;
use DateInterval;
use DateTimeImmutable;
use Exception;

final class ReportService
{
    private ArtistRepository $artistRepository;
    private ReportRepository $reportRepository;
    private Resolver $resolver;
    private Scorer $scorer;
    private ReportBuilder $reportBuilder;
    private SpotifyAdapter $spotifyAdapter;
    private LastfmAdapter $lastfmAdapter;
    private WikipediaAdapter $wikipediaAdapter;
    private RedditAdapter $redditAdapter;
    private BandcampAdapter $bandcampAdapter;

    public function __construct()
    {
        $pdo = Db::pdo();
        $this->artistRepository = new ArtistRepository($pdo);
        $this->reportRepository = new ReportRepository($pdo);
        $this->resolver = new Resolver();
        $this->scorer = new Scorer();
        $this->reportBuilder = new ReportBuilder();

        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../Config/config.php';
        $http = new HttpClient();

        $this->spotifyAdapter = new SpotifyAdapter($http, (array) ($config['sources']['spotify'] ?? []));
        $this->lastfmAdapter = new LastfmAdapter($http, (array) ($config['sources']['lastfm'] ?? []));
        $this->wikipediaAdapter = new WikipediaAdapter($http, []);
        $this->redditAdapter = new RedditAdapter($http, (array) ($config['sources']['reddit'] ?? []));
        $this->bandcampAdapter = new BandcampAdapter($http, (array) ($config['sources']['bandcamp'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    public function create(string $artistName, bool $force = false): array
    {
        $requestedName = trim($artistName);
        if ($requestedName === '') {
            throw new ApiException('Artist name is required', 'invalid_input', 422);
        }

        $existingArtist = $this->artistRepository->findByCanonicalName($requestedName);
        if (is_array($existingArtist) && !$force) {
            $cached = $this->reportRepository->latestByArtist((int) $existingArtist['id']);
            if (is_array($cached) && $this->isFresh((string) ($cached['created_at'] ?? ''))) {
                return [
                    'report_id' => (int) $cached['id'],
                    'artist_id' => (int) $cached['artist_id'],
                    'status' => (string) ($cached['status'] ?? 'complete'),
                    'cached' => true,
                    'report' => $cached['report'],
                ];
            }
        }

        $sources = $this->collectSources($requestedName);
        $identity = $this->resolver->resolve($requestedName, $sources);
        $canonicalName = (string) ($identity['canonical_name'] ?? $requestedName);
        $artistId = $this->artistRepository->upsertArtist(
            $canonicalName,
            $canonicalName,
            (float) ($identity['confidence'] ?? 0.0),
            [
                'requested_name' => $requestedName,
                'match_type' => (string) ($identity['match_type'] ?? 'unresolved'),
                'resolution_explanation' => (string) ($identity['explanation'] ?? ''),
            ]
        );

        $runId = $this->reportRepository->startIngestionRun($artistId);

        foreach ($sources as $sourceName => $sourceData) {
            $this->reportRepository->saveSourceSnapshot(
                $runId,
                $artistId,
                $sourceName,
                (string) ($sourceData['status'] ?? 'error'),
                (float) ($sourceData['confidence'] ?? 0.0),
                (string) ($sourceData['collection_method'] ?? 'unknown'),
                is_array($sourceData['payload'] ?? null) ? $sourceData['payload'] : []
            );

            $errors = $sourceData['errors'] ?? [];
            if (is_array($errors)) {
                foreach ($errors as $error) {
                    if (!is_string($error)) {
                        continue;
                    }
                    $this->reportRepository->saveSourceError(
                        $runId,
                        $artistId,
                        (string) $sourceName,
                        'source_partial',
                        $error,
                        []
                    );
                }
            }

            $profile = $sourceData['payload']['profile'] ?? [];
            if (is_array($profile)) {
                $aliasName = trim((string) ($profile['name'] ?? ''));
                if ($aliasName !== '') {
                    $this->artistRepository->saveAlias($artistId, $aliasName, (string) $sourceName, (float) ($sourceData['confidence'] ?? 0.0));
                }

                $url = trim((string) ($profile['url'] ?? ''));
                if ($url !== '' || $aliasName !== '') {
                    $externalId = (string) ($profile['external_id'] ?? $aliasName ?: $requestedName);
                    $this->artistRepository->saveExternalProfile(
                        $artistId,
                        (string) $sourceName,
                        $externalId,
                        $url,
                        $aliasName,
                        $profile
                    );
                }
            }
        }

        $normalized = $this->normalize($requestedName, $identity, $sources);
        $score = $this->scorer->score($normalized);
        $report = $this->reportBuilder->build($normalized, $score);

        $status = count($normalized['missing_data']) > 0 ? 'partial' : 'complete';
        $reportId = $this->reportRepository->saveReport(
            $artistId,
            $runId,
            $status,
            (int) ($score['bandbrief_score'] ?? 0),
            (float) ($score['confidence'] ?? 0.0),
            $report
        );

        $sections = $report['sections'] ?? [];
        if (is_array($sections)) {
            $this->reportRepository->saveReportSections($reportId, $sections);
        }

        $this->reportRepository->saveScoreBreakdown($reportId, $score);
        $this->storeDerivedTables($artistId, $runId, $normalized, $score);
        $this->reportRepository->finishIngestionRun($runId, $status);

        return [
            'report_id' => $reportId,
            'artist_id' => $artistId,
            'status' => $status,
            'cached' => false,
            'report' => $report,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function view(int $reportId): array
    {
        $row = $this->reportRepository->findById($reportId);
        if (!is_array($row)) {
            return [];
        }

        return [
            'report_id' => (int) $row['id'],
            'artist_id' => (int) $row['artist_id'],
            'status' => (string) $row['status'],
            'bandbrief_score' => (int) $row['bandbrief_score'],
            'confidence' => (float) $row['confidence'],
            'created_at' => (string) $row['created_at'],
            'report' => $row['report'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $reportId): array
    {
        $row = $this->reportRepository->status($reportId);

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectSources(string $artistName): array
    {
        return [
            'spotify' => $this->spotifyAdapter->fetchArtist($artistName),
            'lastfm' => $this->lastfmAdapter->fetchArtist($artistName),
            'wikipedia' => $this->wikipediaAdapter->fetchArtist($artistName),
            'reddit' => $this->redditAdapter->fetchArtist($artistName),
            'bandcamp' => $this->bandcampAdapter->fetchArtist($artistName),
        ];
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, array<string, mixed>> $sources
     * @return array<string, mixed>
     */
    private function normalize(string $requestedName, array $identity, array $sources): array
    {
        $spotifyProfile = $this->sourceProfile($sources, 'spotify');
        $lastfmProfile = $this->sourceProfile($sources, 'lastfm');
        $wikiProfile = $this->sourceProfile($sources, 'wikipedia');
        $bandcampProfile = $this->sourceProfile($sources, 'bandcamp');
        $redditPayload = $sources['reddit']['payload'] ?? [];

        $officialWebsite = trim((string) ($wikiProfile['official_website'] ?? ''));

        $platformPresence = [
            'spotify' => [
                'available' => $this->isAvailable($sources, 'spotify'),
                'url' => (string) ($spotifyProfile['url'] ?? ''),
            ],
            'lastfm' => [
                'available' => $this->isAvailable($sources, 'lastfm'),
                'url' => (string) ($lastfmProfile['url'] ?? ''),
            ],
            'wikipedia' => [
                'available' => $this->isAvailable($sources, 'wikipedia'),
                'url' => (string) ($wikiProfile['url'] ?? ''),
            ],
            'reddit' => [
                'available' => $this->isAvailable($sources, 'reddit'),
                'url' => 'https://www.reddit.com/search/?q=' . rawurlencode($requestedName),
            ],
            'bandcamp' => [
                'available' => $this->isAvailable($sources, 'bandcamp'),
                'url' => (string) ($bandcampProfile['url'] ?? ''),
            ],
            'official_website' => [
                'available' => $officialWebsite !== '',
                'url' => $officialWebsite,
            ],
        ];

        $sourceConfidences = [];
        foreach ($sources as $sourceRow) {
            $sourceConfidences[] = (float) ($sourceRow['confidence'] ?? 0.0);
        }

        $spotifyReleases = $sources['spotify']['payload']['releases'] ?? [];
        if (!is_array($spotifyReleases)) {
            $spotifyReleases = [];
        }

        $lastfmReleases = $sources['lastfm']['payload']['releases'] ?? [];
        if (!is_array($lastfmReleases)) {
            $lastfmReleases = [];
        }

        $releases = $this->mergeReleases($spotifyReleases, $lastfmReleases);
        $communityMentions = is_array($redditPayload['mentions'] ?? null) ? $redditPayload['mentions'] : [];

        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $cutoff12 = $now->sub(new DateInterval('P12M'));
        $cutoff24 = $now->sub(new DateInterval('P24M'));
        $cutoff90Days = $now->sub(new DateInterval('P90D'))->getTimestamp();

        $releasesLast12 = 0;
        $releasesLast24 = 0;

        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $dateRaw = (string) ($release['release_date'] ?? '');
            if ($dateRaw === '') {
                continue;
            }

            try {
                $date = new DateTimeImmutable($dateRaw);
            } catch (Exception $e) {
                continue;
            }

            if ($date >= $cutoff12) {
                $releasesLast12++;
            }
            if ($date >= $cutoff24) {
                $releasesLast24++;
            }
        }

        $mentions90d = 0;
        $totalUpvotes = 0;
        foreach ($communityMentions as $mention) {
            if (!is_array($mention)) {
                continue;
            }

            $createdUtc = (int) (($mention['created_utc'] ?? 0) ?: 0);
            if ($createdUtc >= $cutoff90Days) {
                $mentions90d++;
            }

            $totalUpvotes += (int) (($mention['score'] ?? 0) ?: 0);
        }

        $sourceMatches = $identity['source_matches'] ?? [];
        $matchedSources = 0;
        if (is_array($sourceMatches)) {
            foreach ($sourceMatches as $match) {
                if (is_array($match) && (bool) ($match['matched'] ?? false)) {
                    $matchedSources++;
                }
            }
        }

        $missingData = [];
        foreach ($sources as $sourceName => $sourceData) {
            $status = (string) ($sourceData['status'] ?? 'error');
            if ($status !== 'ok') {
                $missingData[] = (string) $sourceName;
            }
        }

        if ($officialWebsite === '') {
            $missingData[] = 'official_website';
        }

        $lastfmTags = $lastfmProfile['tags'] ?? [];
        if (!is_array($lastfmTags)) {
            $lastfmTags = [];
        }

        return [
            'identity' => $identity,
            'profile' => [
                'requested_name' => $requestedName,
                'canonical_name' => (string) ($identity['canonical_name'] ?? $requestedName),
                'description' => (string) (($wikiProfile['extract'] ?? $wikiProfile['description'] ?? '') ?: ''),
                'official_website' => $officialWebsite,
            ],
            'platform_presence' => $platformPresence,
            'releases' => $releases,
            'community_mentions' => array_slice($communityMentions, 0, 15),
            'availability' => [
                'spotify' => $this->isAvailable($sources, 'spotify'),
                'lastfm' => $this->isAvailable($sources, 'lastfm'),
                'wikipedia' => $this->isAvailable($sources, 'wikipedia'),
                'reddit' => $this->isAvailable($sources, 'reddit'),
                'bandcamp' => $this->isAvailable($sources, 'bandcamp'),
                'official_website' => $officialWebsite !== '',
            ],
            'missing_data' => array_values(array_unique($missingData)),
            'metrics' => [
                'spotify_followers' => (int) ($spotifyProfile['followers'] ?? 0),
                'spotify_popularity' => (int) ($spotifyProfile['popularity'] ?? 0),
                'lastfm_listeners' => (int) ($lastfmProfile['listeners'] ?? 0),
                'lastfm_playcount' => (int) ($lastfmProfile['playcount'] ?? 0),
                'lastfm_tags_count' => count($lastfmTags),
                'reddit_mentions_total' => (int) (($sources['reddit']['payload']['summary']['mentions_count'] ?? 0) ?: 0),
                'reddit_subreddits_count' => (int) (($sources['reddit']['payload']['summary']['subreddits_count'] ?? 0) ?: 0),
                'reddit_total_upvotes' => $totalUpvotes,
                'reddit_mentions_90d' => $mentions90d,
                'releases_last_12m' => $releasesLast12,
                'releases_last_24m' => $releasesLast24,
                'total_releases_seen' => count($releases),
                'source_confidence_avg' => $sourceConfidences === []
                    ? 0.0
                    : round(array_sum($sourceConfidences) / count($sourceConfidences), 4),
                'matched_sources' => $matchedSources,
                'has_wikipedia' => $this->isAvailable($sources, 'wikipedia'),
                'has_official_website' => $officialWebsite !== '',
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $sources
     */
    private function isAvailable(array $sources, string $source): bool
    {
        $status = $sources[$source]['status'] ?? 'error';

        return $status === 'ok';
    }

    /**
     * @param array<string, array<string, mixed>> $sources
     * @return array<string, mixed>
     */
    private function sourceProfile(array $sources, string $source): array
    {
        $profile = $sources[$source]['payload']['profile'] ?? [];

        return is_array($profile) ? $profile : [];
    }

    /**
     * @param array<int, array<string, mixed>> $spotifyReleases
     * @param array<int, array<string, mixed>> $lastfmReleases
     * @return array<int, array<string, mixed>>
     */
    private function mergeReleases(array $spotifyReleases, array $lastfmReleases): array
    {
        $rows = [];

        foreach ($spotifyReleases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $rows[] = [
                'title' => (string) ($release['title'] ?? ''),
                'release_date' => (string) ($release['release_date'] ?? ''),
                'release_type' => (string) ($release['release_type'] ?? ''),
                'source' => 'spotify',
                'url' => (string) ($release['url'] ?? ''),
            ];
        }

        foreach ($lastfmReleases as $release) {
            if (!is_array($release)) {
                continue;
            }
            $rows[] = [
                'title' => (string) ($release['title'] ?? ''),
                'release_date' => '',
                'release_type' => 'top_album',
                'source' => 'lastfm',
                'url' => (string) ($release['url'] ?? ''),
            ];
        }

        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $key = strtolower(trim($row['title'])) . '|' . strtolower(trim($row['release_date']));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        usort(
            $unique,
            static function (array $a, array $b): int {
                return strcmp((string) ($b['release_date'] ?? ''), (string) ($a['release_date'] ?? ''));
            }
        );

        return $unique;
    }

    private function isFresh(string $createdAt): bool
    {
        if ($createdAt === '') {
            return false;
        }

        try {
            $created = new DateTimeImmutable($createdAt);
        } catch (Exception $e) {
            return false;
        }

        $maxAge = (new DateTimeImmutable('now'))->sub(new DateInterval('PT12H'));

        return $created >= $maxAge;
    }

    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $score
     */
    private function storeDerivedTables(int $artistId, int $runId, array $normalized, array $score): void
    {
        $pdo = Db::pdo();

        $releaseStmt = $pdo->prepare('INSERT INTO releases (artist_id, source, title, release_date, release_type, url, ingestion_run_id, created_at, updated_at)
                                      VALUES (:artist_id, :source, :title, :release_date, :release_type, :url, :ingestion_run_id, NOW(), NOW())');

        $releases = $normalized['releases'] ?? [];
        if (is_array($releases)) {
            foreach ($releases as $release) {
                if (!is_array($release)) {
                    continue;
                }
                $releaseStmt->bindValue(':artist_id', $artistId, \PDO::PARAM_INT);
                $releaseStmt->bindValue(':source', (string) ($release['source'] ?? ''), \PDO::PARAM_STR);
                $releaseStmt->bindValue(':title', (string) ($release['title'] ?? ''), \PDO::PARAM_STR);
                $rawDate = (string) ($release['release_date'] ?? '');
                $normalizedDate = $this->normalizeSqlDate($rawDate);
                $releaseStmt->bindValue(
                    ':release_date',
                    $normalizedDate,
                    $normalizedDate !== null ? \PDO::PARAM_STR : \PDO::PARAM_NULL
                );
                $releaseStmt->bindValue(':release_type', (string) ($release['release_type'] ?? ''), \PDO::PARAM_STR);
                $releaseStmt->bindValue(':url', (string) ($release['url'] ?? ''), \PDO::PARAM_STR);
                $releaseStmt->bindValue(':ingestion_run_id', $runId, \PDO::PARAM_INT);
                $releaseStmt->execute();
            }
        }

        $communityStmt = $pdo->prepare('INSERT INTO community_mentions (artist_id, source, external_id, title, url, score, comment_count, mentioned_at, ingestion_run_id, created_at)
                                        VALUES (:artist_id, :source, :external_id, :title, :url, :score, :comment_count, :mentioned_at, :ingestion_run_id, NOW())');

        $mentions = $normalized['community_mentions'] ?? [];
        if (is_array($mentions)) {
            foreach ($mentions as $mention) {
                if (!is_array($mention)) {
                    continue;
                }

                $communityStmt->bindValue(':artist_id', $artistId, \PDO::PARAM_INT);
                $communityStmt->bindValue(':source', 'reddit', \PDO::PARAM_STR);
                $communityStmt->bindValue(':external_id', (string) ($mention['id'] ?? ''), \PDO::PARAM_STR);
                $communityStmt->bindValue(':title', (string) ($mention['title'] ?? ''), \PDO::PARAM_STR);
                $communityStmt->bindValue(':url', (string) ($mention['url'] ?? ''), \PDO::PARAM_STR);
                $communityStmt->bindValue(':score', (int) (($mention['score'] ?? 0) ?: 0), \PDO::PARAM_INT);
                $communityStmt->bindValue(':comment_count', (int) (($mention['num_comments'] ?? 0) ?: 0), \PDO::PARAM_INT);
                $timestamp = (int) (($mention['created_utc'] ?? 0) ?: 0);
                $communityStmt->bindValue(':mentioned_at', $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : null, $timestamp > 0 ? \PDO::PARAM_STR : \PDO::PARAM_NULL);
                $communityStmt->bindValue(':ingestion_run_id', $runId, \PDO::PARAM_INT);
                $communityStmt->execute();
            }
        }

        $socialStmt = $pdo->prepare('INSERT INTO social_metrics (artist_id, source, metric_name, metric_value, ingestion_run_id, captured_at, created_at)
                                     VALUES (:artist_id, :source, :metric_name, :metric_value, :ingestion_run_id, NOW(), NOW())');

        $metrics = $normalized['metrics'] ?? [];
        if (is_array($metrics)) {
            foreach ($metrics as $metric => $value) {
                if (!is_int($value) && !is_float($value)) {
                    continue;
                }
                $socialStmt->bindValue(':artist_id', $artistId, \PDO::PARAM_INT);
                $socialStmt->bindValue(':source', 'derived', \PDO::PARAM_STR);
                $socialStmt->bindValue(':metric_name', (string) $metric, \PDO::PARAM_STR);
                $socialStmt->bindValue(':metric_value', (float) $value);
                $socialStmt->bindValue(':ingestion_run_id', $runId, \PDO::PARAM_INT);
                $socialStmt->execute();
            }
        }

        $derivedStmt = $pdo->prepare('INSERT INTO derived_metrics (artist_id, ingestion_run_id, metric_name, metric_value, metric_json, created_at)
                                      VALUES (:artist_id, :ingestion_run_id, :metric_name, :metric_value, :metric_json, NOW())');

        $derivedStmt->bindValue(':artist_id', $artistId, \PDO::PARAM_INT);
        $derivedStmt->bindValue(':ingestion_run_id', $runId, \PDO::PARAM_INT);
        $derivedStmt->bindValue(':metric_name', 'bandbrief_score', \PDO::PARAM_STR);
        $derivedStmt->bindValue(':metric_value', (float) (($score['bandbrief_score'] ?? 0) ?: 0));
        $derivedStmt->bindValue(':metric_json', json_encode($score, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), \PDO::PARAM_STR);
        $derivedStmt->execute();
    }

    private function normalizeSqlDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($trimmed);
            return $dt->format('Y-m-d');
        } catch (Exception $e) {
            if (preg_match('/^\\d{4}$/', $trimmed) === 1) {
                return $trimmed . '-01-01';
            }

            if (preg_match('/^\\d{4}-\\d{2}$/', $trimmed) === 1) {
                return $trimmed . '-01';
            }
        }

        return null;
    }
}
