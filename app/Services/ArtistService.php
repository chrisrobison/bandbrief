<?php

declare(strict_types=1);

namespace App\Services;

use App\Adapters\BandcampAdapter;
use App\Adapters\LastfmAdapter;
use App\Adapters\SpotifyAdapter;
use App\Adapters\WikipediaAdapter;
use App\Core\Db;
use App\Repositories\ArtistRepository;
use App\Support\HttpClient;

final class ArtistService
{
    private ArtistRepository $artistRepository;
    private Resolver $resolver;
    private SpotifyAdapter $spotifyAdapter;
    private LastfmAdapter $lastfmAdapter;
    private WikipediaAdapter $wikipediaAdapter;
    private BandcampAdapter $bandcampAdapter;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->artistRepository = new ArtistRepository(Db::pdo());
        $this->resolver = new Resolver();
        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../Config/config.php';
        $this->config = $config;

        $http = new HttpClient();
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

        $items = $this->artistRepository->findByNameLike($trimmed, $limit);

        if ($items !== []) {
            return $items;
        }

        $wiki = $this->wikipediaAdapter->fetchArtist($trimmed);
        if (($wiki['status'] ?? '') === 'ok') {
            $profile = $wiki['payload']['profile'] ?? [];
            if (is_array($profile)) {
                return [[
                    'id' => null,
                    'canonical_name' => (string) ($profile['name'] ?? $trimmed),
                    'display_name' => (string) ($profile['name'] ?? $trimmed),
                    'resolver_confidence' => (float) ($wiki['confidence'] ?? 0.0),
                    'created_at' => null,
                    'updated_at' => null,
                ]];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $artistName): array
    {
        $sourcePayloads = $this->collectIdentitySources($artistName);
        $identity = $this->resolver->resolve($artistName, $sourcePayloads);

        $canonicalName = (string) ($identity['canonical_name'] ?? $artistName);
        $confidence = (float) ($identity['confidence'] ?? 0.0);

        $artistId = $this->artistRepository->upsertArtist(
            $canonicalName,
            $canonicalName,
            $confidence,
            [
                'requested_name' => $artistName,
                'match_type' => (string) ($identity['match_type'] ?? 'unresolved'),
                'resolution_explanation' => (string) ($identity['explanation'] ?? ''),
            ]
        );

        foreach ($sourcePayloads as $source => $payload) {
            $profile = $payload['payload']['profile'] ?? [];
            if (!is_array($profile)) {
                continue;
            }

            $sourceName = (string) $source;
            $profileName = trim((string) ($profile['name'] ?? ''));
            if ($profileName !== '') {
                $this->artistRepository->saveAlias($artistId, $profileName, $sourceName, (float) ($payload['confidence'] ?? 0.0));
            }

            $url = trim((string) ($profile['url'] ?? ''));
            if ($url !== '' || $profileName !== '') {
                $externalId = (string) ($profile['external_id'] ?? $profileName ?: $artistName);
                $this->artistRepository->saveExternalProfile(
                    $artistId,
                    $sourceName,
                    $externalId,
                    $url,
                    $profileName,
                    $profile
                );
            }
        }

        return [
            'artist_id' => $artistId,
            'canonical_name' => $canonicalName,
            'identity' => $identity,
            'sources' => $sourcePayloads,
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
            'spotify' => $this->spotifyAdapter->fetchArtist($artistName),
            'lastfm' => $this->lastfmAdapter->fetchArtist($artistName),
            'wikipedia' => $this->wikipediaAdapter->fetchArtist($artistName),
            'bandcamp' => $this->bandcampAdapter->fetchArtist($artistName),
        ];
    }
}
