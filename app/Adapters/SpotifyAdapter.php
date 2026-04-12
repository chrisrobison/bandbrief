<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Support\HttpClient;

final class SpotifyAdapter extends BaseAdapter
{
    private ?string $token = null;

    public function sourceName(): string
    {
        return 'spotify';
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
        $clientId = (string) ($this->config['client_id'] ?? '');
        $clientSecret = (string) ($this->config['client_secret'] ?? '');

        if ($clientId === '' || $clientSecret === '') {
            return $this->partial(
                'official_api',
                0.0,
                ['missing' => ['credentials']],
                ['Spotify credentials are not configured.']
            );
        }

        $token = $this->getToken($clientId, $clientSecret);
        if ($token === null) {
            return $this->failed('official_api', 'spotify_auth_failed', 'Unable to obtain Spotify access token');
        }

        $url = 'https://api.spotify.com/v1/search?type=artist&limit=1&q=' . rawurlencode($artistName);
        $response = $this->http->getJson($url, ['Authorization' => 'Bearer ' . $token]);

        if (!$response['ok']) {
            return $this->failed(
                'official_api',
                'spotify_search_failed',
                'Spotify search request failed: ' . ($response['error'] ?? 'unknown error')
            );
        }

        $items = $response['data']['artists']['items'] ?? [];
        if (!is_array($items) || $items === []) {
            return $this->partial('official_api', 0.0, ['match' => null], ['No Spotify artist match found']);
        }

        $artist = $items[0];
        if (!is_array($artist)) {
            return $this->partial('official_api', 0.0, ['match' => null], ['No Spotify artist match found']);
        }

        $artistId = (string) ($artist['id'] ?? '');
        $releases = [];

        if ($artistId !== '') {
            $releasesUrl = 'https://api.spotify.com/v1/artists/' . rawurlencode($artistId) . '/albums?include_groups=album,single&limit=20';
            $releasesResponse = $this->http->getJson($releasesUrl, ['Authorization' => 'Bearer ' . $token]);
            if ($releasesResponse['ok']) {
                $itemsRaw = $releasesResponse['data']['items'] ?? [];
                if (is_array($itemsRaw)) {
                    foreach ($itemsRaw as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $releases[] = [
                            'title' => (string) ($row['name'] ?? ''),
                            'release_date' => (string) ($row['release_date'] ?? ''),
                            'release_type' => (string) ($row['album_type'] ?? ''),
                            'source_id' => (string) ($row['id'] ?? ''),
                            'url' => (string) (($row['external_urls']['spotify'] ?? '') ?: ''),
                        ];
                    }
                }
            }
        }

        $name = (string) ($artist['name'] ?? '');
        $confidence = $this->similarity($artistName, $name);

        return $this->success('official_api', $confidence, [
            'profile' => [
                'external_id' => $artistId,
                'name' => $name,
                'url' => (string) (($artist['external_urls']['spotify'] ?? '') ?: ''),
                'genres' => is_array($artist['genres'] ?? null) ? $artist['genres'] : [],
                'popularity' => (int) ($artist['popularity'] ?? 0),
                'followers' => (int) (($artist['followers']['total'] ?? 0) ?: 0),
                'images' => is_array($artist['images'] ?? null) ? $artist['images'] : [],
            ],
            'releases' => $releases,
        ]);
    }

    private function getToken(string $clientId, string $clientSecret): ?string
    {
        if (is_string($this->token) && $this->token !== '') {
            return $this->token;
        }

        $auth = base64_encode($clientId . ':' . $clientSecret);
        if ($auth === false) {
            return null;
        }

        $response = $this->http->postForm(
            'https://accounts.spotify.com/api/token',
            [
                'Authorization' => 'Basic ' . $auth,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            ['grant_type' => 'client_credentials']
        );

        if (!$response['ok']) {
            return null;
        }

        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return null;
        }

        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            return null;
        }

        $this->token = $token;

        return $this->token;
    }
}
