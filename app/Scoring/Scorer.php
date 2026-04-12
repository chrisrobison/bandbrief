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

        $bandbriefScore = (int) round($weightedSum);

        $identityConfidence = (float) ($metrics['identity_confidence'] ?? 0.0);
        $coverage = $this->coverageScore($availability);
        $sourceConfidence = (float) ($metrics['source_confidence_avg'] ?? 0.0);

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
        $followers = (int) ($metrics['spotify_followers'] ?? 0);
        $listeners = (int) ($metrics['lastfm_listeners'] ?? 0);
        $releaseDepth = (int) ($metrics['musicbrainz_release_groups_total'] ?? 0);

        $score = (int) round(min(
            100,
            ($this->logScale($followers, 2_500_000) * 52)
            + ($this->logScale($listeners, 2_500_000) * 40)
            + min(8, $releaseDepth)
        ));

        return [
            'score' => $score,
            'explanation' => 'Combines Spotify followers, Last.fm listeners, and a small baseline for catalog depth from MusicBrainz release groups.',
            'inputs' => [
                'spotify_followers' => $followers,
                'lastfm_listeners' => $listeners,
                'musicbrainz_release_groups_total' => $releaseDepth,
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
        $releaseCoverage = (int) ($metrics['release_sources_covered'] ?? 0);

        $score = (int) round(min(
            100,
            min(62, $recentReleases * 17)
            + min(26, $recentMentions * 2)
            + min(12, $releaseCoverage * 4)
        ));

        return [
            'score' => $score,
            'explanation' => 'Looks at recent release cadence and recent community chatter, with a bonus when multiple release sources agree.',
            'inputs' => [
                'releases_last_12m' => $recentReleases,
                'reddit_mentions_90d' => $recentMentions,
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
        $popularity = (int) ($metrics['spotify_popularity'] ?? 0);
        $upvotes = (int) ($metrics['reddit_total_upvotes'] ?? 0);
        $playcount = (int) ($metrics['lastfm_playcount'] ?? 0);

        $upvoteNorm = $this->logScale($upvotes, 15_000) * 30;
        $playcountNorm = $this->logScale($playcount, 50_000_000) * 20;

        $score = (int) round(min(100, ($popularity * 0.5) + $upvoteNorm + $playcountNorm));

        return [
            'score' => $score,
            'explanation' => 'Blends Spotify popularity with social upvotes and playcount scale to avoid over-rewarding only one platform.',
            'inputs' => [
                'spotify_popularity' => $popularity,
                'reddit_total_upvotes' => $upvotes,
                'lastfm_playcount' => $playcount,
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

        $score = (int) round(min(
            100,
            min(50, $recentReleases * 14)
            + min(25, $totalReleases * 1.8)
            + min(25, $mbReleaseGroups * 2.5)
        ));

        return [
            'score' => $score,
            'explanation' => 'Uses recent output first, then total known catalog, plus MusicBrainz release-group confirmation for durable release activity.',
            'inputs' => [
                'total_releases_seen' => $totalReleases,
                'releases_last_24m' => $recentReleases,
                'musicbrainz_release_groups_total' => $mbReleaseGroups,
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

        $score = (int) round(min(100, ($mentions * 1.8) + ($subreddits * 6.5) + ($tags * 2.0)));

        return [
            'score' => $score,
            'explanation' => 'Measures breadth and volume of public discussion from Reddit plus Last.fm tagging context.',
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
    private function scoreCredibilityPresence(array $metrics): array
    {
        $matchedSources = (int) ($metrics['matched_identity_sources'] ?? 0);
        $identityConfidence = (float) ($metrics['identity_confidence'] ?? 0.0);
        $hasMusicbrainz = (int) (($metrics['has_musicbrainz'] ?? false) ? 1 : 0);
        $hasWikipedia = (int) (($metrics['has_wikipedia'] ?? false) ? 1 : 0);
        $hasWebsite = (int) (($metrics['has_official_website'] ?? false) ? 1 : 0);

        $score = min(
            100,
            (int) round(
                ($matchedSources * 14)
                + ($identityConfidence * 34)
                + ($hasMusicbrainz * 16)
                + ($hasWikipedia * 15)
                + ($hasWebsite * 21)
            )
        );

        return [
            'score' => $score,
            'explanation' => 'Prioritizes identity agreement quality and stable cross-platform presence checks.',
            'inputs' => [
                'matched_identity_sources' => $matchedSources,
                'identity_confidence' => round($identityConfidence, 4),
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
