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

        $identity = $normalized['identity'] ?? [];
        if (!is_array($identity)) {
            $identity = [];
        }

        $sourceStatus = $normalized['source_status'] ?? [];
        if (!is_array($sourceStatus)) {
            $sourceStatus = [];
        }

        $overviewSummary = $this->overviewSummary($profile, $identity, $metrics);
        $audienceSummary = $this->audienceSummary($metrics);
        $momentumSummary = $this->momentumSummary($metrics);
        $communitySummary = $this->communitySummary($metrics);

        $sections = [
            [
                'name' => 'Overview',
                'content' => [
                    'summary' => $overviewSummary,
                    'artist' => [
                        'requested_name' => (string) ($profile['requested_name'] ?? ''),
                        'canonical_name' => (string) ($profile['canonical_name'] ?? ''),
                        'description' => (string) ($profile['description'] ?? ''),
                        'official_website' => (string) ($profile['official_website'] ?? ''),
                    ],
                    'identity_resolution' => [
                        'match_type' => (string) ($identity['match_type'] ?? 'no_trustworthy_match'),
                        'confidence' => (float) ($identity['confidence'] ?? 0.0),
                        'sources_agreed' => $identity['explainability']['sources_agreed'] ?? [],
                        'explanation' => (string) ($identity['explanation'] ?? ''),
                    ],
                ],
            ],
            [
                'name' => 'Platform Presence',
                'content' => [
                    'summary' => sprintf(
                        'Identity coverage: %d/%d identity sources available. Signal coverage: %d/%d.',
                        (int) ($metrics['identity_sources_available'] ?? 0),
                        (int) ($metrics['identity_sources_total'] ?? 0),
                        (int) ($metrics['signal_sources_available'] ?? 0),
                        (int) ($metrics['signal_sources_total'] ?? 0)
                    ),
                    'platforms' => $presence,
                    'source_status' => $sourceStatus,
                ],
            ],
            [
                'name' => 'Releases',
                'content' => [
                    'summary' => sprintf(
                        'Release evidence: %d total items, %d in last 12 months, from %d source(s).',
                        (int) ($metrics['total_releases_seen'] ?? 0),
                        (int) ($metrics['releases_last_12m'] ?? 0),
                        (int) ($metrics['release_sources_covered'] ?? 0)
                    ),
                    'items' => $releases,
                    'totals' => [
                        'total_releases_seen' => (int) ($metrics['total_releases_seen'] ?? 0),
                        'releases_last_12m' => (int) ($metrics['releases_last_12m'] ?? 0),
                        'releases_last_24m' => (int) ($metrics['releases_last_24m'] ?? 0),
                        'musicbrainz_release_groups_total' => (int) ($metrics['musicbrainz_release_groups_total'] ?? 0),
                    ],
                ],
            ],
            [
                'name' => 'Audience / Engagement',
                'content' => [
                    'summary' => $audienceSummary,
                    'spotify_followers' => (int) ($metrics['spotify_followers'] ?? 0),
                    'spotify_popularity' => (int) ($metrics['spotify_popularity'] ?? 0),
                    'lastfm_listeners' => (int) ($metrics['lastfm_listeners'] ?? 0),
                    'lastfm_playcount' => (int) ($metrics['lastfm_playcount'] ?? 0),
                    'reddit_total_upvotes' => (int) ($metrics['reddit_total_upvotes'] ?? 0),
                ],
            ],
            [
                'name' => 'Community Signal',
                'content' => [
                    'summary' => $communitySummary,
                    'mentions' => $community,
                    'summary_metrics' => [
                        'mentions_total' => (int) ($metrics['reddit_mentions_total'] ?? 0),
                        'subreddits_count' => (int) ($metrics['reddit_subreddits_count'] ?? 0),
                        'lastfm_tags_count' => (int) ($metrics['lastfm_tags_count'] ?? 0),
                    ],
                ],
            ],
            [
                'name' => 'Momentum',
                'content' => [
                    'summary' => $momentumSummary,
                    'releases_last_12m' => (int) ($metrics['releases_last_12m'] ?? 0),
                    'releases_last_24m' => (int) ($metrics['releases_last_24m'] ?? 0),
                    'reddit_mentions_90d' => (int) ($metrics['reddit_mentions_90d'] ?? 0),
                ],
            ],
            [
                'name' => 'Risks / Missing Data',
                'content' => [
                    'items' => $this->buildRisks($missing, (float) ($score['confidence'] ?? 0.0), $identity),
                    'missing_data' => $missing,
                ],
            ],
            [
                'name' => 'Booking Take',
                'content' => [
                    'summary' => $this->bookingTake(
                        (int) ($score['bandbrief_score'] ?? 0),
                        (float) ($score['confidence'] ?? 0.0),
                        $missing,
                        (string) ($identity['match_type'] ?? 'no_trustworthy_match')
                    ),
                ],
            ],
            [
                'name' => 'Score Summary',
                'content' => [
                    'bandbrief_score' => (int) ($score['bandbrief_score'] ?? 0),
                    'confidence' => (float) ($score['confidence'] ?? 0.0),
                    'identity_confidence' => (float) ($score['identity_confidence'] ?? 0.0),
                    'coverage_confidence' => (float) ($score['coverage_confidence'] ?? 0.0),
                    'evidence_confidence' => (float) ($score['evidence_confidence'] ?? 0.0),
                    'breakdown' => $score['breakdown'] ?? [],
                    'explanation' => (string) ($score['explanation'] ?? ''),
                ],
            ],
        ];

        return [
            'generated_at' => gmdate('c'),
            'summary' => [
                'canonical_name' => (string) ($profile['canonical_name'] ?? ''),
                'match_type' => (string) ($identity['match_type'] ?? 'no_trustworthy_match'),
                'identity_confidence' => (float) ($identity['confidence'] ?? 0.0),
                'overview' => $overviewSummary,
                'booking_take' => $this->bookingTake(
                    (int) ($score['bandbrief_score'] ?? 0),
                    (float) ($score['confidence'] ?? 0.0),
                    $missing,
                    (string) ($identity['match_type'] ?? 'no_trustworthy_match')
                ),
            ],
            'normalized_profile' => $profile,
            'identity' => $identity,
            'platform_presence' => $presence,
            'source_status' => $sourceStatus,
            'releases' => $releases,
            'engagement' => [
                'spotify_followers' => (int) ($metrics['spotify_followers'] ?? 0),
                'spotify_popularity' => (int) ($metrics['spotify_popularity'] ?? 0),
                'lastfm_listeners' => (int) ($metrics['lastfm_listeners'] ?? 0),
                'lastfm_playcount' => (int) ($metrics['lastfm_playcount'] ?? 0),
                'reddit_total_upvotes' => (int) ($metrics['reddit_total_upvotes'] ?? 0),
            ],
            'community_signal' => [
                'mentions_total' => (int) ($metrics['reddit_mentions_total'] ?? 0),
                'subreddits_count' => (int) ($metrics['reddit_subreddits_count'] ?? 0),
                'mentions' => $community,
            ],
            'bandbrief_score' => (int) ($score['bandbrief_score'] ?? 0),
            'score_breakdown' => $score['breakdown'] ?? [],
            'score_confidence' => [
                'overall' => (float) ($score['confidence'] ?? 0.0),
                'identity_confidence' => (float) ($score['identity_confidence'] ?? 0.0),
                'coverage_confidence' => (float) ($score['coverage_confidence'] ?? 0.0),
                'evidence_confidence' => (float) ($score['evidence_confidence'] ?? 0.0),
            ],
            'booking_take' => $this->bookingTake(
                (int) ($score['bandbrief_score'] ?? 0),
                (float) ($score['confidence'] ?? 0.0),
                $missing,
                (string) ($identity['match_type'] ?? 'no_trustworthy_match')
            ),
            'missing_data' => $missing,
            'sections' => $sections,
        ];
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $metrics
     */
    private function overviewSummary(array $profile, array $identity, array $metrics): string
    {
        $name = (string) ($profile['canonical_name'] ?? $profile['requested_name'] ?? 'Unknown artist');
        $matchType = (string) ($identity['match_type'] ?? 'no_trustworthy_match');
        $identityConfidence = (float) ($metrics['identity_confidence'] ?? 0.0);

        return sprintf(
            '%s resolved as %s (identity confidence %.2f).',
            $name,
            str_replace('_', ' ', $matchType),
            $identityConfidence
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function audienceSummary(array $metrics): string
    {
        return sprintf(
            'Spotify followers %d, Spotify popularity %d, Last.fm listeners %d.',
            (int) ($metrics['spotify_followers'] ?? 0),
            (int) ($metrics['spotify_popularity'] ?? 0),
            (int) ($metrics['lastfm_listeners'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function communitySummary(array $metrics): string
    {
        return sprintf(
            'Reddit mentions %d across %d subreddit(s), Last.fm tags %d.',
            (int) ($metrics['reddit_mentions_total'] ?? 0),
            (int) ($metrics['reddit_subreddits_count'] ?? 0),
            (int) ($metrics['lastfm_tags_count'] ?? 0)
        );
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function momentumSummary(array $metrics): string
    {
        return sprintf(
            '%d release(s) seen in the last 12 months and %d Reddit mention(s) in the last 90 days.',
            (int) ($metrics['releases_last_12m'] ?? 0),
            (int) ($metrics['reddit_mentions_90d'] ?? 0)
        );
    }

    /**
     * @param string[] $missing
     * @param array<string, mixed> $identity
     * @return string[]
     */
    private function buildRisks(array $missing, float $scoreConfidence, array $identity): array
    {
        $risks = [];

        if ($scoreConfidence < 0.5) {
            $risks[] = 'Score confidence is low due to limited source coverage or weak source confidence.';
        }

        $matchType = (string) ($identity['match_type'] ?? 'no_trustworthy_match');
        if (in_array($matchType, ['ambiguous_match', 'no_trustworthy_match'], true)) {
            $risks[] = 'Identity resolution is not definitive; review candidate identity sources before decisioning.';
        }

        foreach ($missing as $source) {
            $risks[] = sprintf('Missing or partial data from %s reduces certainty.', (string) $source);
        }

        if ($risks === []) {
            $risks[] = 'No material data quality risks detected in this run.';
        }

        return $risks;
    }

    /**
     * @param string[] $missing
     */
    private function bookingTake(int $score, float $confidence, array $missing, string $matchType): string
    {
        if (in_array($matchType, ['ambiguous_match', 'no_trustworthy_match'], true)) {
            return 'Identity is ambiguous; hold booking spend until artist identity is confirmed across canonical sources.';
        }

        if ($score >= 75 && $confidence >= 0.65) {
            return 'Strong booking signal: cross-source presence and audience momentum support higher-tier opportunities.';
        }

        if ($score >= 55) {
            return 'Moderate booking signal: viable for targeted rooms and support slots; verify missing sources before committing spend.';
        }

        if (count($missing) >= 3) {
            return 'Weak booking signal with sparse evidence coverage; gather additional source data before committing.';
        }

        return 'Early-stage booking signal: suitable for smaller tests while monitoring momentum and community growth.';
    }
}
