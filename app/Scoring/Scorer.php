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
            'Credibility / Presence' => $this->scoreCredibilityPresence($metrics),
        ];

        $weights = [
            'Reach' => 0.21,
            'Momentum' => 0.19,
            'Engagement' => 0.17,
            'Release Activity' => 0.18,
            'Community Signal' => 0.12,
            'Credibility / Presence' => 0.13,
        ];

        $weightedSum = 0.0;
        $breakdown = [];

        foreach ($categories as $name => $scoreRow) {
            $weight = $weights[$name] ?? 0;
            $weighted = (float) ($scoreRow['score'] ?? 0) * $weight;
            $weightedSum += $weighted;

            $breakdown[] = [
                'category' => $name,
                'score' => (int) ($scoreRow['score'] ?? 0),
                'weight' => $weight,
                'weighted' => round($weighted, 2),
                'explanation' => (string) ($scoreRow['explanation'] ?? ''),
                'inputs' => is_array($scoreRow['inputs'] ?? null) ? $scoreRow['inputs'] : [],
            ];
        }

        $identityConfidence = (float) ($metrics['identity_confidence'] ?? 0.0);
        $coverage = $this->coverageScore($availability);
        $sourceConfidence = (float) ($metrics['source_confidence_avg'] ?? 0.0);
        $identityFactor = 0.62 + (max(0.0, min(1.0, $identityConfidence)) * 0.40);
        $bandbriefScore = (int) round(max(0.0, min(100.0, $weightedSum * $identityFactor)));

        $confidence = round(
            ($identityConfidence * 0.55)
            + ($coverage * 0.30)
            + ($sourceConfidence * 0.15),
            4
        );

        return [
            'bandbrief_score' => $bandbriefScore,
            'confidence' => $confidence,
            'identity_confidence' => round($identityConfidence, 4),
            'coverage_confidence' => round($coverage, 4),
            'evidence_confidence' => round($sourceConfidence, 4),
            'breakdown' => $breakdown,
            'explanation' => 'Transparent weighted scoring across six fixed categories; identity confidence is tracked separately from engagement and momentum.',
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreReach(array $metrics): array
    {
        $listeners = (int) ($metrics['lastfm_listeners'] ?? 0);
        $spotifyCatalogTotal = (int) ($metrics['spotify_catalog_total'] ?? 0);
        $releaseDepth = (int) ($metrics['musicbrainz_release_groups_total'] ?? 0);

        $score = (int) round(min(
            100,
            ($this->logScale($listeners, 20_000_000) * 56)
            + ($this->logScale($releaseDepth, 900) * 28)
            + ($this->logScale($spotifyCatalogTotal, 320) * 16)
        ));

        return [
            'score' => $score,
            'explanation' => 'Combines audience scale with catalog depth across Last.fm, MusicBrainz, and Spotify release totals.',
            'inputs' => [
                'lastfm_listeners' => $listeners,
                'musicbrainz_release_groups_total' => $releaseDepth,
                'spotify_catalog_total' => $spotifyCatalogTotal,
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
        $upvotes = (int) ($metrics['reddit_total_upvotes'] ?? 0);
        $releaseCoverage = (int) ($metrics['release_sources_covered'] ?? 0);
        $pagesFetched = (int) ($metrics['reddit_pages_fetched'] ?? 0);

        $score = (int) round(min(
            100,
            ($this->logScale($recentReleases, 20) * 48)
            + ($this->logScale($recentMentions, 140) * 22)
            + ($this->logScale($upvotes, 20_000) * 20)
            + min(6, $pagesFetched * 1.5)
            + min(4, $releaseCoverage * 2)
        ));

        return [
            'score' => $score,
            'explanation' => 'Looks at recent release cadence and recent community activity, with an evidence bonus when multiple release sources agree.',
            'inputs' => [
                'releases_last_12m' => $recentReleases,
                'reddit_mentions_90d' => $recentMentions,
                'reddit_total_upvotes' => $upvotes,
                'reddit_pages_fetched' => $pagesFetched,
                'release_sources_covered' => $releaseCoverage,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreEngagement(array $metrics): array
    {
        $upvotes = (int) ($metrics['reddit_total_upvotes'] ?? 0);
        $playcount = (int) ($metrics['lastfm_playcount'] ?? 0);
        $mentions = max(1, (int) ($metrics['reddit_mentions_total'] ?? 0));
        $subreddits = max(1, (int) ($metrics['reddit_subreddits_count'] ?? 0));
        $discussionDepth = $upvotes / $mentions;
        $communityBreadth = $mentions / $subreddits;

        $upvoteNorm = $this->logScale($upvotes, 20_000) * 28;
        $playcountNorm = $this->logScale($playcount, 5_000_000_000) * 48;
        $discussionNorm = $this->logScale((int) round($discussionDepth * 100), 2_000) * 16;
        $breadthNorm = $this->logScale((int) round($communityBreadth * 100), 1_500) * 8;

        $score = (int) round(min(100, $upvoteNorm + $playcountNorm + $discussionNorm + $breadthNorm));

        return [
            'score' => $score,
            'explanation' => 'Blends social vote quality with Last.fm consumption scale and cross-community participation depth.',
            'inputs' => [
                'reddit_total_upvotes' => $upvotes,
                'lastfm_playcount' => $playcount,
                'reddit_mentions_total' => $mentions,
                'reddit_subreddits_count' => $subreddits,
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
        $mbReleaseGroups = (int) ($metrics['musicbrainz_release_groups_total'] ?? 0);
        $spotifyCatalogTotal = (int) ($metrics['spotify_catalog_total'] ?? 0);
        $releaseDepth = max($totalReleases, $mbReleaseGroups, $spotifyCatalogTotal);

        $score = (int) round(min(
            100,
            ($this->logScale($recentReleases, 24) * 52)
            + ($this->logScale($releaseDepth, 900) * 38)
            + min(10, $recentReleases * 2)
        ));

        return [
            'score' => $score,
            'explanation' => 'Prioritizes recent releases, then uses total catalog depth as a secondary stabilizer for sustained activity.',
            'inputs' => [
                'total_releases_seen' => $totalReleases,
                'releases_last_24m' => $recentReleases,
                'musicbrainz_release_groups_total' => $mbReleaseGroups,
                'spotify_catalog_total' => $spotifyCatalogTotal,
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
        $upvotes = (int) ($metrics['reddit_total_upvotes'] ?? 0);
        $pagesFetched = (int) ($metrics['reddit_pages_fetched'] ?? 0);

        $score = (int) round(min(
            100,
            ($this->logScale($mentions, 140) * 34)
            + ($this->logScale($subreddits, 80) * 29)
            + ($this->logScale($upvotes, 40_000) * 25)
            + ($this->logScale($tags, 60) * 8)
            + min(4, $pagesFetched)
        ));

        return [
            'score' => $score,
            'explanation' => 'Measures breadth and quality of public discussion from Reddit plus Last.fm tagging context without saturating on capped result windows.',
            'inputs' => [
                'reddit_mentions_total' => $mentions,
                'reddit_subreddits_count' => $subreddits,
                'lastfm_tags_count' => $tags,
                'reddit_total_upvotes' => $upvotes,
                'reddit_pages_fetched' => $pagesFetched,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $metrics
     * @return array{score: int, explanation: string, inputs: array<string, mixed>}
     */
    private function scoreCredibilityPresence(array $metrics): array
    {
        $matchedSources = (int) ($metrics['matched_identity_sources'] ?? 0);
        $identitySourcesTotal = max(1, (int) ($metrics['identity_sources_total'] ?? 5));
        $identityConfidence = (float) ($metrics['identity_confidence'] ?? 0.0);
        $identitySourceConfidence = (float) ($metrics['identity_source_confidence_avg'] ?? 0.0);
        $hasMusicbrainz = (int) (($metrics['has_musicbrainz'] ?? false) ? 1 : 0);
        $hasWikipedia = (int) (($metrics['has_wikipedia'] ?? false) ? 1 : 0);
        $hasWebsite = (int) (($metrics['has_official_website'] ?? false) ? 1 : 0);
        $matchedRatio = min(1.0, max(0.0, $matchedSources / $identitySourcesTotal));

        $score = min(
            100,
            (int) round(
                ($matchedRatio * 40)
                + ($identityConfidence * 30)
                + ($identitySourceConfidence * 20)
                + ($hasMusicbrainz * 3)
                + ($hasWikipedia * 2)
                + ($hasWebsite * 5)
            )
        );

        return [
            'score' => $score,
            'explanation' => 'Prioritizes identity agreement quality and stable cross-platform presence checks.',
            'inputs' => [
                'matched_identity_sources' => $matchedSources,
                'identity_sources_total' => $identitySourcesTotal,
                'identity_confidence' => round($identityConfidence, 4),
                'identity_source_confidence_avg' => round($identitySourceConfidence, 4),
                'has_musicbrainz' => (bool) $hasMusicbrainz,
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

        $identityKeys = ['musicbrainz', 'spotify', 'lastfm', 'wikipedia', 'bandcamp', 'official_website'];
        $signalKeys = ['reddit'];

        $identityAvailable = 0;
        $identityTotal = 0;

        foreach ($identityKeys as $key) {
            if (!array_key_exists($key, $availability)) {
                continue;
            }
            $identityTotal++;
            if ((bool) $availability[$key]) {
                $identityAvailable++;
            }
        }

        $signalAvailable = 0;
        $signalTotal = 0;

        foreach ($signalKeys as $key) {
            if (!array_key_exists($key, $availability)) {
                continue;
            }
            $signalTotal++;
            if ((bool) $availability[$key]) {
                $signalAvailable++;
            }
        }

        $identityRatio = $identityTotal > 0 ? ($identityAvailable / $identityTotal) : 0.0;
        $signalRatio = $signalTotal > 0 ? ($signalAvailable / $signalTotal) : 0.0;

        return round(($identityRatio * 0.75) + ($signalRatio * 0.25), 4);
    }
}
