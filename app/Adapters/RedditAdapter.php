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
        $mentions = [];
        $subreddits = [];
        $upvotes = 0;
        $after = '';
        $pagesFetched = 0;

        while ($pagesFetched < 4) {
            $url = 'https://www.reddit.com/search.json?sort=new&t=year&limit=25&q=' . rawurlencode('"' . $artistName . '" music');
            if ($after !== '') {
                $url .= '&after=' . rawurlencode($after);
            }

            $response = $this->http->getJson($url, ['User-Agent' => $userAgent]);
            if (!$response['ok']) {
                if ($mentions === []) {
                    return $this->partial('search_api', 0.0, ['mentions' => []], ['Reddit search unavailable']);
                }
                break;
            }

            $children = $response['data']['data']['children'] ?? [];
            if (!is_array($children) || $children === []) {
                break;
            }

            foreach ($children as $row) {
                if (!is_array($row) || !is_array($row['data'] ?? null)) {
                    continue;
                }

                $data = $row['data'];
                $id = (string) ($data['id'] ?? '');
                if ($id === '') {
                    continue;
                }

                $subreddit = (string) ($data['subreddit'] ?? '');
                if ($subreddit !== '') {
                    $subreddits[$subreddit] = true;
                }

                $score = (int) (($data['score'] ?? 0) ?: 0);
                $upvotes += $score;

                $mentions[$id] = [
                    'id' => $id,
                    'title' => (string) ($data['title'] ?? ''),
                    'subreddit' => $subreddit,
                    'score' => $score,
                    'num_comments' => (int) (($data['num_comments'] ?? 0) ?: 0),
                    'created_utc' => (int) (($data['created_utc'] ?? 0) ?: 0),
                    'url' => (string) (($data['permalink'] ?? '') ? 'https://reddit.com' . $data['permalink'] : ''),
                ];
            }

            $afterValue = $response['data']['data']['after'] ?? '';
            $after = is_string($afterValue) ? $afterValue : '';
            $pagesFetched++;

            if ($after === '') {
                break;
            }
        }

        $mentionsList = array_values($mentions);
        usort(
            $mentionsList,
            static fn(array $a, array $b): int => ((int) ($b['created_utc'] ?? 0)) <=> ((int) ($a['created_utc'] ?? 0))
        );

        $confidence = $mentionsList === [] ? 0.0 : min(0.92, 0.30 + (count($mentionsList) / 130));

        return $this->success('search_api', $confidence, [
            'summary' => [
                'mentions_count' => count($mentionsList),
                'subreddits_count' => count($subreddits),
                'total_upvotes' => $upvotes,
                'pages_fetched' => $pagesFetched,
            ],
            'mentions' => $mentionsList,
        ]);
    }
}
