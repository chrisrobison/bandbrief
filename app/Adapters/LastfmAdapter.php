<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Support\HttpClient;

final class LastfmAdapter extends BaseAdapter
{
    public function sourceName(): string
    {
        return 'lastfm';
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
        $apiKey = (string) ($this->config['api_key'] ?? '');

        if ($apiKey === '') {
            return $this->partial('official_api', 0.0, ['missing' => ['api_key']], ['Last.fm API key is not configured.']);
        }

        $base = 'https://ws.audioscrobbler.com/2.0/?format=json&autocorrect=1&api_key=' . rawurlencode($apiKey);

        $infoUrl = $base . '&method=artist.getinfo&artist=' . rawurlencode($artistName);
        $infoResponse = $this->http->getJson($infoUrl);

        if (!$infoResponse['ok']) {
            return $this->failed('official_api', 'lastfm_info_failed', 'Last.fm artist.getinfo failed');
        }

        $artist = $infoResponse['data']['artist'] ?? null;
        if (!is_array($artist)) {
            return $this->partial('official_api', 0.0, ['match' => null], ['No Last.fm artist match found']);
        }

        $albumsUrl = $base . '&method=artist.gettopalbums&limit=10&artist=' . rawurlencode($artistName);
        $albumsResponse = $this->http->getJson($albumsUrl);
        $albums = [];

        if ($albumsResponse['ok']) {
            $albumRows = $albumsResponse['data']['topalbums']['album'] ?? [];
            if (is_array($albumRows)) {
                foreach ($albumRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $albums[] = [
                        'title' => (string) ($row['name'] ?? ''),
                        'playcount' => (int) (($row['playcount'] ?? 0) ?: 0),
                        'url' => (string) ($row['url'] ?? ''),
                    ];
                }
            }
        }

        $name = (string) ($artist['name'] ?? '');
        $confidence = $this->similarity($artistName, $name);

        $tags = $artist['tags']['tag'] ?? [];
        $normalizedTags = [];
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_array($tag)) {
                    $normalizedTags[] = (string) ($tag['name'] ?? '');
                }
            }
        }

        return $this->success('official_api', $confidence, [
            'profile' => [
                'name' => $name,
                'url' => (string) ($artist['url'] ?? ''),
                'listeners' => (int) (($artist['stats']['listeners'] ?? 0) ?: 0),
                'playcount' => (int) (($artist['stats']['playcount'] ?? 0) ?: 0),
                'summary' => (string) (($artist['bio']['summary'] ?? '') ?: ''),
                'tags' => $normalizedTags,
            ],
            'releases' => $albums,
        ]);
    }
}
