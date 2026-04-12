<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\BandcampAdapter;
use App\Adapters\LastfmAdapter;
use App\Adapters\MusicbrainzAdapter;
use App\Adapters\RedditAdapter;
use App\Adapters\SpotifyAdapter;
use App\Adapters\WikipediaAdapter;
use App\Core\ApiException;
use App\Core\Db;
use App\Reporting\ReportBuilder;
use App\Repositories\ArtistRepository;
use App\Repositories\ReportRepository;
use App\Scoring\Scorer;
use App\Support\HttpClient;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class ReportService
{
    private ArtistRepository $artistRepository;
    private ReportRepository $reportRepository;
    private Resolver $resolver;
    private Scorer $scorer;
    private ReportBuilder $reportBuilder;
    private MusicbrainzAdapter $musicbrainzAdapter;
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

        $this->musicbrainzAdapter = new MusicbrainzAdapter($http, (array) ($config['sources']['musicbrainz'] ?? []));
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
                    'source_status' => (is_array($cached['report']['source_status'] ?? null) ? $cached['report']['source_status'] : []),
                ];
            }
        }

        $identitySources = $this->collectIdentitySources($requestedName);
        $signalSources = $this->collectSignalSources($requestedName);

        $identity = $this->resolver->resolve($requestedName, $identitySources);
        $canonicalName = (string) ($identity['canonical_name'] ?? $requestedName);

        $artistId = $this->artistRepository->upsertArtist(
            $canonicalName,
            $canonicalName,
            (float) ($identity['confidence'] ?? 0.0),
            [
                'requested_name' => $requestedName,
                'match_type' => (string) ($identity['match_type'] ?? 'no_trustworthy_match'),
                'resolution_explanation' => (string) ($identity['explanation'] ?? ''),
                'resolution_detail' => $identity['explainability'] ?? [],
            ]
        );

        $runId = $this->reportRepository->startIngestionRun($artistId);

        $this->persistSourceSnapshots($runId, $artistId, $identitySources, 'identity');
        $this->persistSourceSnapshots($runId, $artistId, $signalSources, 'signal');
        $this->persistIdentityProfiles($artistId, $requestedName, $identitySources);

        $normalized = $this->normalize($requestedName, $identity, $identitySources, $signalSources);
        $score = $this->scorer->score($normalized);
        $report = $this->reportBuilder->build($normalized, $score);

        $status = 'complete';
        if (count($normalized['missing_data']) > 0 || in_array((string) ($identity['match_type'] ?? ''), ['ambiguous_match', 'no_trustworthy_match'], true)) {
            $status = 'partial';
        }

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
            'source_status' => $normalized['source_status'],
            'identity' => $identity,
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
            'source_status' => (is_array($row['report']['source_status'] ?? null) ? $row['report']['source_status'] : []),
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
     * @return array<string, array<string, mixed>>
     */
    private function collectSignalSources(string $artistName): array
    {
        return [
            'reddit' => $this->redditAdapter->fetchArtist($artistName),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $sources
     */
    private function persistSourceSnapshots(int $runId, int $artistId, array $sources, string $role): void
    {
        foreach ($sources as $sourceName => $sourceData) {
            $errors = $this->normalizeSourceErrors($sourceData);
            $errorRows = [];

            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }

                $errorRows[] = [
                    'code' => (string) ($error['code'] ?? 'source_error'),
                    'message' => (string) ($error['message'] ?? 'Source returned an unspecified error'),
                    'context' => (array) ($error['context'] ?? []),
                ];
            }

            $this->reportRepository->saveSourceSnapshot(
                $runId,
                $artistId,
                (string) $sourceName,
                (string) ($sourceData['status'] ?? 'error'),
                (float) ($sourceData['confidence'] ?? 0.0),
                (string) ($sourceData['collection_method'] ?? 'unknown'),
                is_array($sourceData['payload'] ?? null) ? $sourceData['payload'] : [],
                (string) ($sourceData['fetched_at'] ?? ''),
                $errorRows,
                is_array($sourceData['raw_payload'] ?? null) ? $sourceData['raw_payload'] : [],
                $role
            );

            foreach ($errorRows as $error) {
                $this->reportRepository->saveSourceError(
                    $runId,
                    $artistId,
                    (string) $sourceName,
                    (string) ($error['code'] ?? 'source_error'),
                    (string) ($error['message'] ?? 'Source returned an unspecified error'),
                    is_array($error['context'] ?? null) ? $error['context'] : []
                );
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $identitySources
     */
    private function persistIdentityProfiles(int $artistId, string $requestedName, array $identitySources): void
    {
        foreach ($identitySources as $sourceName => $sourceData) {
            $profile = $sourceData['payload']['profile'] ?? [];
            if (!is_array($profile)) {
                continue;
            }

            $sourceConfidence = (float) ($sourceData['confidence'] ?? 0.0);
            $aliasName = trim((string) ($profile['name'] ?? ''));
            if ($aliasName !== '') {
                $this->artistRepository->saveAlias($artistId, $aliasName, (string) $sourceName, $sourceConfidence);
            }

            $payloadAliases = $sourceData['payload']['aliases'] ?? [];
            if (is_array($payloadAliases)) {
                foreach ($payloadAliases as $aliasValue) {
                    $alias = trim((string) $aliasValue);
                    if ($alias === '') {
                        continue;
                    }
                    $this->artistRepository->saveAlias($artistId, $alias, (string) $sourceName, max(0.45, $sourceConfidence * 0.92));
                }
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

    /**
     * @param array<string, mixed> $identity
     * @param array<string, array<string, mixed>> $identitySources
     * @param array<string, array<string, mixed>> $signalSources
     * @return array<string, mixed>
     */
    private function normalize(string $requestedName, array $identity, array $identitySources, array $signalSources): array
    {
        $spotifyProfile = $this->sourceProfile($identitySources, 'spotify');
        $lastfmProfile = $this->sourceProfile($identitySources, 'lastfm');
        $wikiProfile = $this->sourceProfile($identitySources, 'wikipedia');
        $bandcampProfile = $this->sourceProfile($identitySources, 'bandcamp');
        $musicbrainzProfile = $this->sourceProfile($identitySources, 'musicbrainz');

        $redditPayload = $signalSources['reddit']['payload'] ?? [];
        if (!is_array($redditPayload)) {
            $redditPayload = [];
        }

        $officialWebsite = trim((string) ($wikiProfile['official_website'] ?? ''));
        if ($officialWebsite === '') {
            $officialUrls = $musicbrainzProfile['official_urls'] ?? [];
            if (is_array($officialUrls) && $officialUrls !== []) {
                $officialWebsite = trim((string) $officialUrls[0]);
            }
        }

        $platformPresence = [
            'musicbrainz' => [
                'available' => $this->isAvailable($identitySources, 'musicbrainz'),
                'url' => (string) ($musicbrainzProfile['url'] ?? ''),
            ],
            'spotify' => [
                'available' => $this->isAvailable($identitySources, 'spotify'),
                'url' => (string) ($spotifyProfile['url'] ?? ''),
            ],
            'lastfm' => [
                'available' => $this->isAvailable($identitySources, 'lastfm'),
                'url' => (string) ($lastfmProfile['url'] ?? ''),
            ],
            'wikipedia' => [
                'available' => $this->isAvailable($identitySources, 'wikipedia'),
                'url' => (string) ($wikiProfile['url'] ?? ''),
            ],
            'bandcamp' => [
                'available' => $this->isAvailable($identitySources, 'bandcamp'),
                'url' => (string) ($bandcampProfile['url'] ?? ''),
            ],
            'reddit' => [
                'available' => $this->isAvailable($signalSources, 'reddit'),
                'url' => 'https://www.reddit.com/search/?q=' . rawurlencode($requestedName),
            ],
            'official_website' => [
                'available' => $officialWebsite !== '',
                'url' => $officialWebsite,
            ],
        ];

        $identitySourceConfidences = [];
        foreach ($identitySources as $sourceRow) {
            $identitySourceConfidences[] = (float) ($sourceRow['confidence'] ?? 0.0);
        }

        $signalSourceConfidences = [];
        foreach ($signalSources as $sourceRow) {
            $signalSourceConfidences[] = (float) ($sourceRow['confidence'] ?? 0.0);
        }

        $spotifyReleases = $identitySources['spotify']['payload']['releases'] ?? [];
        if (!is_array($spotifyReleases)) {
            $spotifyReleases = [];
        }

        $lastfmReleases = $identitySources['lastfm']['payload']['releases'] ?? [];
        if (!is_array($lastfmReleases)) {
            $lastfmReleases = [];
        }

        $musicbrainzReleases = $identitySources['musicbrainz']['payload']['releases'] ?? [];
        if (!is_array($musicbrainzReleases)) {
            $musicbrainzReleases = [];
        }

        $releases = $this->mergeReleases($spotifyReleases, $lastfmReleases, $musicbrainzReleases);
        $communityMentions = is_array($redditPayload['mentions'] ?? null) ? $redditPayload['mentions'] : [];

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
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
        $matchedIdentitySources = 0;
        if (is_array($sourceMatches)) {
            foreach ($sourceMatches as $match) {
                if (is_array($match) && (bool) ($match['matched'] ?? false)) {
                    $matchedIdentitySources++;
                }
            }
        }

        $missingData = [];
        foreach ($identitySources as $sourceName => $sourceData) {
            $status = (string) ($sourceData['status'] ?? 'error');
            if ($status !== 'ok') {
                $missingData[] = (string) $sourceName;
            }
        }

        foreach ($signalSources as $sourceName => $sourceData) {
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

        $sourceStatus = $this->buildSourceStatus($identitySources, $signalSources);
        $identityAvailableCount = $this->availableCount($identitySources);
        $signalAvailableCount = $this->availableCount($signalSources);

        return [
            'identity' => $identity,
            'profile' => [
                'requested_name' => $requestedName,
                'canonical_name' => (string) ($identity['canonical_name'] ?? $requestedName),
                'description' => (string) (($wikiProfile['extract'] ?? $wikiProfile['description'] ?? '') ?: ''),
                'official_website' => $officialWebsite,
                'aliases' => is_array($musicbrainzProfile['aliases'] ?? null) ? $musicbrainzProfile['aliases'] : [],
            ],
            'source_groups' => [
                'identity_sources' => $identitySources,
                'signal_sources' => $signalSources,
            ],
            'source_status' => $sourceStatus,
            'platform_presence' => $platformPresence,
            'releases' => $releases,
            'community_mentions' => array_slice($communityMentions, 0, 20),
            'availability' => [
                'musicbrainz' => $this->isAvailable($identitySources, 'musicbrainz'),
                'spotify' => $this->isAvailable($identitySources, 'spotify'),
                'lastfm' => $this->isAvailable($identitySources, 'lastfm'),
                'wikipedia' => $this->isAvailable($identitySources, 'wikipedia'),
                'bandcamp' => $this->isAvailable($identitySources, 'bandcamp'),
                'reddit' => $this->isAvailable($signalSources, 'reddit'),
                'official_website' => $officialWebsite !== '',
            ],
            'missing_data' => array_values(array_unique($missingData)),
            'metrics' => [
                'spotify_followers' => (int) ($spotifyProfile['followers'] ?? 0),
                'spotify_popularity' => (int) ($spotifyProfile['popularity'] ?? 0),
                'lastfm_listeners' => (int) ($lastfmProfile['listeners'] ?? 0),
                'lastfm_playcount' => (int) ($lastfmProfile['playcount'] ?? 0),
                'lastfm_tags_count' => count($lastfmTags),
                'reddit_mentions_total' => (int) (($signalSources['reddit']['payload']['summary']['mentions_count'] ?? 0) ?: 0),
                'reddit_subreddits_count' => (int) (($signalSources['reddit']['payload']['summary']['subreddits_count'] ?? 0) ?: 0),
                'reddit_total_upvotes' => $totalUpvotes,
                'reddit_mentions_90d' => $mentions90d,
                'releases_last_12m' => $releasesLast12,
                'releases_last_24m' => $releasesLast24,
                'total_releases_seen' => count($releases),
                'musicbrainz_release_groups_total' => count($musicbrainzReleases),
                'release_sources_covered' => $this->countReleaseSources($releases),
                'source_confidence_avg' => $this->average($identitySourceConfidences, $signalSourceConfidences),
                'identity_source_confidence_avg' => $this->average($identitySourceConfidences),
                'signal_source_confidence_avg' => $this->average($signalSourceConfidences),
                'matched_identity_sources' => $matchedIdentitySources,
                'identity_confidence' => (float) ($identity['confidence'] ?? 0.0),
                'identity_sources_total' => count($identitySources),
                'identity_sources_available' => $identityAvailableCount,
                'signal_sources_total' => count($signalSources),
                'signal_sources_available' => $signalAvailableCount,
                'has_musicbrainz' => $this->isAvailable($identitySources, 'musicbrainz'),
                'has_wikipedia' => $this->isAvailable($identitySources, 'wikipedia'),
                'has_official_website' => $officialWebsite !== '',
                'has_bandcamp' => $this->isAvailable($identitySources, 'bandcamp'),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $sources
     */
    private function isAvailable(array $sources, string $source): bool
    {
        if (!isset($sources[$source])) {
            return false;
        }

        $status = (string) ($sources[$source]['status'] ?? 'error');
        if ($status === 'ok') {
            return true;
        }

        if ($status === 'partial') {
            $profile = $sources[$source]['payload']['profile'] ?? [];
            return is_array($profile) && trim((string) ($profile['name'] ?? $profile['title'] ?? '')) !== '';
        }

        return false;
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
     * @param array<int, array<string, mixed>> $musicbrainzReleases
     * @return array<int, array<string, mixed>>
     */
    private function mergeReleases(array $spotifyReleases, array $lastfmReleases, array $musicbrainzReleases): array
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
                'source_id' => (string) ($release['source_id'] ?? ''),
                'url' => (string) ($release['url'] ?? ''),
            ];
        }

        foreach ($lastfmReleases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $rows[] = [
                'title' => (string) ($release['title'] ?? ''),
                'release_date' => (string) ($release['release_date'] ?? ''),
                'release_type' => (string) (($release['release_type'] ?? '') ?: 'top_album'),
                'source' => 'lastfm',
                'source_id' => (string) ($release['source_id'] ?? ''),
                'url' => (string) ($release['url'] ?? ''),
            ];
        }

        foreach ($musicbrainzReleases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $rows[] = [
                'title' => (string) ($release['title'] ?? ''),
                'release_date' => (string) ($release['release_date'] ?? ''),
                'release_type' => (string) ($release['release_type'] ?? 'release_group'),
                'source' => 'musicbrainz',
                'source_id' => (string) ($release['source_id'] ?? ''),
                'url' => (string) ($release['url'] ?? ''),
            ];
        }

        $seen = [];
        $unique = [];

        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $title) ?? $title) . '|' . strtolower(trim((string) ($row['release_date'] ?? '')));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $releaseKeyRaw = implode('|', [
                (string) ($row['source'] ?? ''),
                (string) ($row['source_id'] ?? ''),
                strtolower($title),
                (string) ($row['release_date'] ?? ''),
            ]);

            $row['release_key'] = substr(sha1($releaseKeyRaw), 0, 16);
            $row['can_expand'] = false;
            $row['detail_stub'] = [
                'tracks' => [],
                'album_info' => [],
                'context' => [],
                'related_releases' => [],
                'similar_artists' => [],
            ];

            $unique[] = $row;
        }

        usort(
            $unique,
            static function (array $a, array $b): int {
                return strcmp((string) ($b['release_date'] ?? ''), (string) ($a['release_date'] ?? ''));
            }
        );

        return array_slice($unique, 0, 25);
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

    /**
     * @param array<string, mixed> $sourceData
     * @return array<int, array<string, mixed>>
     */
    private function normalizeSourceErrors(array $sourceData): array
    {
        $errors = $sourceData['errors'] ?? [];
        if (!is_array($errors)) {
            return [];
        }

        $rows = [];

        foreach ($errors as $error) {
            if (is_string($error)) {
                $rows[] = [
                    'code' => 'source_partial',
                    'message' => $error,
                    'context' => [],
                ];
                continue;
            }

            if (!is_array($error)) {
                continue;
            }

            $message = trim((string) ($error['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            $rows[] = [
                'code' => (string) (($error['code'] ?? '') ?: 'source_partial'),
                'message' => $message,
                'context' => is_array($error['context'] ?? null) ? $error['context'] : [],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $identitySources
     * @param array<string, array<string, mixed>> $signalSources
     * @return array<string, array<string, mixed>>
     */
    private function buildSourceStatus(array $identitySources, array $signalSources): array
    {
        $rows = [];

        foreach ($identitySources as $source => $sourceData) {
            $rows[(string) $source] = [
                'role' => 'identity',
                'status' => (string) ($sourceData['status'] ?? 'error'),
                'confidence' => (float) ($sourceData['confidence'] ?? 0.0),
                'fetched_at' => (string) ($sourceData['fetched_at'] ?? ''),
                'collection_method' => (string) ($sourceData['collection_method'] ?? 'unknown'),
                'error_count' => is_array($sourceData['errors'] ?? null) ? count($sourceData['errors']) : 0,
            ];
        }

        foreach ($signalSources as $source => $sourceData) {
            $rows[(string) $source] = [
                'role' => 'signal',
                'status' => (string) ($sourceData['status'] ?? 'error'),
                'confidence' => (float) ($sourceData['confidence'] ?? 0.0),
                'fetched_at' => (string) ($sourceData['fetched_at'] ?? ''),
                'collection_method' => (string) ($sourceData['collection_method'] ?? 'unknown'),
                'error_count' => is_array($sourceData['errors'] ?? null) ? count($sourceData['errors']) : 0,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $sources
     */
    private function availableCount(array $sources): int
    {
        $count = 0;
        foreach ($sources as $sourceName => $row) {
            if ($this->isAvailable($sources, (string) $sourceName)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param float[] ...$sets
     */
    private function average(array ...$sets): float
    {
        $all = [];
        foreach ($sets as $set) {
            foreach ($set as $value) {
                $all[] = (float) $value;
            }
        }

        if ($all === []) {
            return 0.0;
        }

        return round(array_sum($all) / count($all), 4);
    }

    /**
     * @param array<int, array<string, mixed>> $releases
     */
    private function countReleaseSources(array $releases): int
    {
        $sources = [];
        foreach ($releases as $release) {
            if (!is_array($release)) {
                continue;
            }

            $source = trim((string) ($release['source'] ?? ''));
            if ($source !== '') {
                $sources[$source] = true;
            }
        }

        return count($sources);
    }
}
