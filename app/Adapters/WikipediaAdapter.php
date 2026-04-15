<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Support\HttpClient;

final class WikipediaAdapter extends BaseAdapter
{
    public function sourceName(): string
    {
        return 'wikipedia';
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
        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent(),
        ];

        $searchUrl = 'https://en.wikipedia.org/w/api.php?action=query&list=search&srsearch=' . rawurlencode($artistName . ' band musician') . '&format=json&utf8=1';
        $searchResponse = $this->http->getJson($searchUrl, $headers);

        if (!$searchResponse['ok']) {
            return $this->failed('official_api', 'wikipedia_search_failed', 'Wikipedia search request failed');
        }

        $items = $searchResponse['data']['query']['search'] ?? [];
        if (!is_array($items) || $items === []) {
            return $this->partial('official_api', 0.0, ['match' => null], ['No Wikipedia result found']);
        }

        $top = $items[0];
        if (!is_array($top)) {
            return $this->partial('official_api', 0.0, ['match' => null], ['No Wikipedia result found']);
        }

        $title = (string) ($top['title'] ?? '');
        if ($title === '') {
            return $this->partial('official_api', 0.0, ['match' => null], ['No Wikipedia title found']);
        }

        $summaryUrl = 'https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode($title);
        $summaryResponse = $this->http->getJson($summaryUrl, $headers);

        if (!$summaryResponse['ok']) {
            return $this->partial('official_api', 0.2, ['title' => $title], ['Wikipedia summary fetch failed']);
        }

        $summary = $summaryResponse['data'];
        $wikidataId = (string) ($summary['wikibase_item'] ?? '');

        $officialWebsite = '';
        if ($wikidataId !== '') {
            $officialWebsite = $this->fetchOfficialWebsiteFromWikidata($wikidataId, $headers);
        }

        $name = (string) ($summary['title'] ?? $title);
        $confidence = $this->similarity($artistName, $name);

        return $this->success('official_api', $confidence, [
            'profile' => [
                'name' => $name,
                'title' => $title,
                'description' => (string) ($summary['description'] ?? ''),
                'extract' => (string) ($summary['extract'] ?? ''),
                'url' => (string) (($summary['content_urls']['desktop']['page'] ?? '') ?: ''),
                'wikidata_id' => $wikidataId,
                'official_website' => $officialWebsite,
            ],
        ]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetchOfficialWebsiteFromWikidata(string $wikidataId, array $headers): string
    {
        $url = 'https://www.wikidata.org/w/api.php?action=wbgetentities&ids=' . rawurlencode($wikidataId) . '&format=json&props=claims';
        $response = $this->http->getJson($url, $headers);

        if (!$response['ok']) {
            return '';
        }

        $claims = $response['data']['entities'][$wikidataId]['claims']['P856'] ?? [];
        if (!is_array($claims) || $claims === []) {
            return '';
        }

        $first = $claims[0];
        if (!is_array($first)) {
            return '';
        }

        return (string) (($first['mainsnak']['datavalue']['value'] ?? '') ?: '');
    }

    private function userAgent(): string
    {
        $configured = trim((string) ($this->config['user_agent'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        return 'BandBrief/1.0 (+https://yourdomain.example/contact)';
    }
}
