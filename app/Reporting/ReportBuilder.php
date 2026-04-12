<?php

declare(strict_types=1);

namespace App\Reporting;

final class ReportBuilder
{
    /**
     * @param array<string, mixed> $normalized
     * @param array<string, mixed> $score
     * @return array<string, mixed>
     */
    public function build(array $normalized, array $score): array
    {
        $profile = $normalized['profile'] ?? [];
        if (!is_array($profile)) {
            $profile = [];
        }

        $metrics = $normalized['metrics'] ?? [];
        if (!is_array($metrics)) {
            $metrics = [];
        }

        $presence = $normalized['platform_presence'] ?? [];
        if (!is_array($presence)) {
            $presence = [];
        }

        $releases = $normalized['releases'] ?? [];
        if (!is_array($releases)) {
            $releases = [];
        }

        $community = $normalized['community_mentions'] ?? [];
        if (!is_array($community)) {
            $community = [];
        }

        $missing = $normalized['missing_data'] ?? [];
        if (!is_array($missing)) {
            $missing = [];
        }

        $sections = [
            [
                'name' => 'Overview',
                'content' => [
                    'artist' => $profile,
                    'identity_resolution' => $normalized['identity'] ?? [],
                    'missing_data' => $missing,
                ],
            ],
            [
                'name' => 'Platform Presence',
                'content' => [
                    'platforms' => $presence,
                    'official_website' => $profile['official_website'] ?? null,
                ],
            ],
            [
                'name' => 'Releases',
                'content' => [
                    'items' => $releases,
                    'totals' => [
                        'total_releases_seen' => (int) ($metrics['total_releases_seen'] ?? 0),
                        'releases_last_12m' => (int) ($metrics['releases_last_12m'] ?? 0),
                    ],
                ],
            ],
            [
                'name' => 'Engagement',
                'content' => [
                    'spotify_popularity' => (int) ($metrics['spotify_popularity'] ?? 0),
                    'reddit_total_upvotes' => (int) ($metrics['reddit_total_upvotes'] ?? 0),
                    'lastfm_playcount' => (int) ($metrics['lastfm_playcount'] ?? 0),
                ],
            ],
            [
                'name' => 'Community Signal',
                'content' => [
                    'mentions' => $community,
                    'summary' => [
                        'mentions_total' => (int) ($metrics['reddit_mentions_total'] ?? 0),
                        'subreddits_count' => (int) ($metrics['reddit_subreddits_count'] ?? 0),
                        'lastfm_tags_count' => (int) ($metrics['lastfm_tags_count'] ?? 0),
                    ],
                ],
            ],
            [
                'name' => 'Momentum',
                'content' => [
                    'releases_last_12m' => (int) ($metrics['releases_last_12m'] ?? 0),
                    'reddit_mentions_90d' => (int) ($metrics['reddit_mentions_90d'] ?? 0),
                ],
            ],
            [
                'name' => 'Risks',
                'content' => [
                    'items' => $this->buildRisks($missing, (float) ($score['confidence'] ?? 0.0)),
                ],
            ],
            [
                'name' => 'Booking Take',
                'content' => [
                    'summary' => $this->bookingTake(
                        (int) ($score['bandbrief_score'] ?? 0),
                        (float) ($score['confidence'] ?? 0.0),
                        $missing
                    ),
                ],
            ],
            [
                'name' => 'Score Summary',
                'content' => [
                    'bandbrief_score' => (int) ($score['bandbrief_score'] ?? 0),
                    'confidence' => (float) ($score['confidence'] ?? 0.0),
                    'breakdown' => $score['breakdown'] ?? [],
                    'explanation' => (string) ($score['explanation'] ?? ''),
                ],
            ],
        ];

        return [
            'generated_at' => gmdate('c'),
            'normalized_profile' => $profile,
            'platform_presence' => $presence,
            'releases' => $releases,
            'engagement' => [
                'spotify_popularity' => (int) ($metrics['spotify_popularity'] ?? 0),
                'lastfm_playcount' => (int) ($metrics['lastfm_playcount'] ?? 0),
                'reddit_total_upvotes' => (int) ($metrics['reddit_total_upvotes'] ?? 0),
            ],
            'community_signal' => [
                'mentions_total' => (int) ($metrics['reddit_mentions_total'] ?? 0),
                'subreddits_count' => (int) ($metrics['reddit_subreddits_count'] ?? 0),
                'mentions' => $community,
            ],
            'live_activity' => [
                'status' => 'missing',
                'note' => 'No live event source is integrated in the MVP yet.',
            ],
            'bandbrief_score' => (int) ($score['bandbrief_score'] ?? 0),
            'score_breakdown' => $score['breakdown'] ?? [],
            'booking_take' => $this->bookingTake((int) ($score['bandbrief_score'] ?? 0), (float) ($score['confidence'] ?? 0.0), $missing),
            'missing_data' => $missing,
            'sections' => $sections,
        ];
    }

    /**
     * @param string[] $missing
     * @return string[]
     */
    private function buildRisks(array $missing, float $scoreConfidence): array
    {
        $risks = [];

        if ($scoreConfidence < 0.5) {
            $risks[] = 'Score confidence is low due to sparse cross-source coverage.';
        }

        foreach ($missing as $source) {
            $risks[] = sprintf('Missing or partial data from %s reduces decision certainty.', $source);
        }

        if ($risks === []) {
            $risks[] = 'No material data quality risks detected in this run.';
        }

        return $risks;
    }

    /**
     * @param string[] $missing
     */
    private function bookingTake(int $score, float $confidence, array $missing): string
    {
        if ($score >= 75 && $confidence >= 0.65) {
            return 'Strong booking signal: broad reach and active audience response support higher-tier opportunities.';
        }

        if ($score >= 55) {
            return 'Moderate booking signal: viable for targeted rooms and support slots; verify missing sources before committing spend.';
        }

        if (count($missing) >= 3) {
            return 'Weak booking signal with low evidence coverage; collect more data before making a booking decision.';
        }

        return 'Early-stage booking signal: suitable for small or local tests while monitoring momentum and community growth.';
    }
}
