<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Support\HttpClient;

final class RedditAdapter extends BaseAdapter
{
    public function sourceName(): string
    {
        return 'reddit';
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
        $userAgent = (string) ($this->config['user_agent'] ?? 'BandBrief/1.0');
        $url = 'https://www.reddit.com/search.json?sort=new&t=year&limit=25&q=' . rawurlencode('"' . $artistName . '" music');

        $response = $this->http->getJson($url, ['User-Agent' => $userAgent]);

        if (!$response['ok']) {
            return $this->partial('search_api', 0.0, ['mentions' => []], ['Reddit search unavailable']);
        }

        $children = $response['data']['data']['children'] ?? [];
        if (!is_array($children)) {
            $children = [];
        }

        $mentions = [];
        $subreddits = [];
        $upvotes = 0;

        foreach ($children as $row) {
            if (!is_array($row) || !is_array($row['data'] ?? null)) {
                continue;
            }

            $data = $row['data'];
            $subreddit = (string) ($data['subreddit'] ?? '');
            if ($subreddit !== '') {
                $subreddits[$subreddit] = true;
            }

            $score = (int) (($data['score'] ?? 0) ?: 0);
            $upvotes += $score;

            $mentions[] = [
                'id' => (string) ($data['id'] ?? ''),
                'title' => (string) ($data['title'] ?? ''),
                'subreddit' => $subreddit,
                'score' => $score,
                'num_comments' => (int) (($data['num_comments'] ?? 0) ?: 0),
                'created_utc' => (int) (($data['created_utc'] ?? 0) ?: 0),
                'url' => (string) (($data['permalink'] ?? '') ? 'https://reddit.com' . $data['permalink'] : ''),
            ];
        }

        $confidence = $mentions === [] ? 0.0 : min(0.85, 0.35 + (count($mentions) / 60));

        return $this->success('search_api', $confidence, [
            'summary' => [
                'mentions_count' => count($mentions),
                'subreddits_count' => count($subreddits),
                'total_upvotes' => $upvotes,
            ],
            'mentions' => $mentions,
        ]);
    }
}
