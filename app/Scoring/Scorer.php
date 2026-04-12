<?php

declare(strict_types=1);

namespace App\Scoring;

final class Scorer
{
    /**
     * @param array<string, mixed> $normalized
     * @return array<string, mixed>
     */
    public function score(array $normalized): array
    {
        $metrics = $normalized['metrics'] ?? [];
        if (!is_array($metrics)) {
            $metrics = [];
        }

        $availability = $normalized['availability'] ?? [];
        if (!is_array($availability)) {
            $availability = [];
        }

        $categories = [
            'Reach' => $this->scoreReach($metrics),
            'Momentum' => $this->scoreMomentum($metrics),
            'Engagement' => $this->scoreEngagement($metrics),
            'Release Activity' => $this->scoreReleaseActivity($metrics),
            'Community Signal' => $this->scoreCommunitySignal($metrics),
            'Credibility' => $this->scoreCredibility($metrics),
        ];

        $weights = [
            'Reach' => 0.22,
            'Momentum' => 0.18,
            'Engagement' => 0.18,
            'Release Activity' => 0.16,
            'Community Signal' => 0.14,
            'Credibility' => 0.12,
        ];

        $weightedSum = 0.0;
        $breakdown = [];

        foreach ($categories as $name => $scoreRow) {
            $weight = $weights[$name] ?? 0;
            $weighted = $scoreRow['score'] * $weight;
            $weightedSum += $weighted;

            $breakdown[] = [
                'category' => $name,
                'score' => $scoreRow['score'],
                'weight' => $weight,
                'weighted' => round($weighted, 2),
                'explanation' => $scoreRow['explanation'],
                'inputs' => $scoreRow['inputs'],
            ];
        }

        $bandbriefScore = (int) round($weightedSum);
        $coverage = $this->coverageScore($availability);

        $confidence = round(($coverage * 0.6) + ((float) ($metrics['source_confidence_avg'] ?? 0.0) * 0.4), 4);

        return [
            'bandbrief_score' => $bandbriefScore,
            'confidence' => $confidence,
            'breakdown' => $breakdown,
            'explanation' => 'Score is weighted across six fixed categories with explicit handling for missing metrics.',
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreReach(array $metrics): array
    {
        $followers = (int) ($metrics['spotify_followers'] ?? 0);
        $listeners = (int) ($metrics['lastfm_listeners'] ?? 0);
        $score = (int) round(min(100, ($this->logScale($followers, 2_000_000) * 55) + ($this->logScale($listeners, 2_000_000) * 45)));

        return [
            'score' => $score,
            'explanation' => 'Combines Spotify followers and Last.fm listeners with logarithmic normalization.',
            'inputs' => [
                'spotify_followers' => $followers,
                'lastfm_listeners' => $listeners,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreMomentum(array $metrics): array
    {
        $recentReleases = (int) ($metrics['releases_last_12m'] ?? 0);
        $recentMentions = (int) ($metrics['reddit_mentions_90d'] ?? 0);

        $score = (int) round(min(100, ($recentReleases * 15) + min(55, $recentMentions * 2)));

        return [
            'score' => $score,
            'explanation' => 'Looks at recent release cadence and Reddit mentions in the past 90 days.',
            'inputs' => [
                'releases_last_12m' => $recentReleases,
                'reddit_mentions_90d' => $recentMentions,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreEngagement(array $metrics): array
    {
        $popularity = (int) ($metrics['spotify_popularity'] ?? 0);
        $upvotes = (int) ($metrics['reddit_total_upvotes'] ?? 0);
        $upvoteNorm = min(100, (int) round($this->logScale($upvotes, 10_000) * 100));

        $score = (int) round(($popularity * 0.6) + ($upvoteNorm * 0.4));

        return [
            'score' => $score,
            'explanation' => 'Blends Spotify popularity and Reddit upvote activity.',
            'inputs' => [
                'spotify_popularity' => $popularity,
                'reddit_total_upvotes' => $upvotes,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreReleaseActivity(array $metrics): array
    {
        $totalReleases = (int) ($metrics['total_releases_seen'] ?? 0);
        $recentReleases = (int) ($metrics['releases_last_24m'] ?? 0);

        $score = (int) round(min(100, ($recentReleases * 12) + min(40, $totalReleases * 2)));

        return [
            'score' => $score,
            'explanation' => 'Prioritizes recent release activity while retaining a baseline for catalog depth.',
            'inputs' => [
                'total_releases_seen' => $totalReleases,
                'releases_last_24m' => $recentReleases,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreCommunitySignal(array $metrics): array
    {
        $mentions = (int) ($metrics['reddit_mentions_total'] ?? 0);
        $subreddits = (int) ($metrics['reddit_subreddits_count'] ?? 0);
        $tags = (int) ($metrics['lastfm_tags_count'] ?? 0);

        $score = (int) round(min(100, ($mentions * 2) + ($subreddits * 6) + ($tags * 2)));

        return [
            'score' => $score,
            'explanation' => 'Measures breadth and volume of community discussion across Reddit and Last.fm tags.',
            'inputs' => [
                'reddit_mentions_total' => $mentions,
                'reddit_subreddits_count' => $subreddits,
                'lastfm_tags_count' => $tags,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreCredibility(array $metrics): array
    {
        $matchedSources = (int) ($metrics['matched_sources'] ?? 0);
        $hasWikipedia = (int) (($metrics['has_wikipedia'] ?? false) ? 1 : 0);
        $hasWebsite = (int) (($metrics['has_official_website'] ?? false) ? 1 : 0);

        $score = min(100, (int) round(($matchedSources * 18) + ($hasWikipedia * 20) + ($hasWebsite * 20)));

        return [
            'score' => $score,
            'explanation' => 'Scores cross-source identity consistency and verified presence signals.',
            'inputs' => [
                'matched_sources' => $matchedSources,
                'has_wikipedia' => (bool) $hasWikipedia,
                'has_official_website' => (bool) $hasWebsite,
            ],
        ];
    }

    private function logScale(int $value, int $ceiling): float
    {
        if ($value <= 0 || $ceiling <= 1) {
            return 0.0;
        }

        $capped = min($value, $ceiling);

        return log($capped + 1) / log($ceiling + 1);
    }

    /**
     * @param array<string, bool> $availability
     */
    private function coverageScore(array $availability): float
    {
        if ($availability === []) {
            return 0.0;
        }

        $total = count($availability);
        $available = 0;

        foreach ($availability as $isAvailable) {
            if ($isAvailable) {
                $available++;
            }
        }

        return round($available / $total, 4);
    }
}
