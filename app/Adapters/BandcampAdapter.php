<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Support\HttpClient;

final class BandcampAdapter extends BaseAdapter
{
    public function sourceName(): string
    {
        return 'bandcamp';
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
        $enabled = (bool) ($this->config['enabled'] ?? true);
        if (!$enabled) {
            return $this->partial('scraping', 0.0, ['match' => null], ['Bandcamp adapter disabled by config']);
        }

        $url = 'https://bandcamp.com/search?q=' . rawurlencode($artistName) . '&item_type=b';
        $response = $this->http->get($url, ['User-Agent' => 'BandBrief/1.0']);

        if (!$response['ok']) {
            return $this->partial('scraping', 0.0, ['match' => null], ['Bandcamp search request failed']);
        }

        $html = $response['body'];
        $matches = [];

        preg_match('/<div class="heading">\s*<a href="([^"]+)"[^>]*>([^<]+)<\/a>/i', $html, $matches);

        if ($matches === []) {
            return $this->partial('scraping', 0.0, ['match' => null], ['No Bandcamp result parsed']);
        }

        $profileUrl = trim((string) ($matches[1] ?? ''));
        $name = trim(html_entity_decode((string) ($matches[2] ?? ''), ENT_QUOTES | ENT_HTML5));

        if ($profileUrl === '' || $name === '') {
            return $this->partial('scraping', 0.0, ['match' => null], ['Bandcamp parsing returned incomplete data']);
        }

        $confidence = $this->similarity($artistName, $name);

        return $this->success('scraping', $confidence, [
            'profile' => [
                'name' => $name,
                'url' => $profileUrl,
            ],
        ]);
    }
}
