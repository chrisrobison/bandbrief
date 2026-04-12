<?php

declare(strict_types=1);

namespace App\Services;

final class Resolver
{
    /** @var array<string, float> */
    private array $sourcePriority = [
        'musicbrainz' => 1.0,
        'spotify' => 0.9,
        'lastfm' => 0.75,
        'wikipedia' => 0.7,
        'bandcamp' => 0.55,
    ];

    /**
     * @param array<string, array<string, mixed>> $sourcePayloads
     * @return array<string, mixed>
     */
    public function resolve(string $requestedName, array $sourcePayloads): array
    {
        $requestedVariants = $this->nameVariants($requestedName);
        $sourceMatches = [];
        $candidateGroups = [];

        foreach ($sourcePayloads as $sourceName => $sourceData) {
            $candidate = $this->extractCandidate((string) $sourceName, $sourceData);
            if ($candidate === null) {
                continue;
            }

            $best = $this->bestSimilarity($requestedVariants, $candidate['variants']);
            $nameSimilarity = (float) ($best['score'] ?? 0.0);
            $matchedName = (string) ($best['variant'] ?? $candidate['name']);
            $sourceConfidence = (float) ($sourceData['confidence'] ?? 0.0);
            $priority = $this->sourcePriority[(string) $sourceName] ?? 0.4;

            $quality = $this->clamp(
                ($nameSimilarity * 0.58)
                + ($sourceConfidence * 0.22)
                + ($priority * 0.20)
                - ((string) ($sourceData['status'] ?? '') === 'partial' ? 0.06 : 0.0)
            );

            $isMatched = $nameSimilarity >= 0.74;
            $candidateKey = $candidate['key'];

            $sourceMatches[] = [
                'source' => (string) $sourceName,
                'candidate' => $candidate['name'],
                'candidate_type' => $candidate['type'],
                'matched_name_variant' => $matchedName,
                'name_similarity' => $nameSimilarity,
                'source_confidence' => $sourceConfidence,
                'priority_weight' => $priority,
                'confidence' => $quality,
                'matched' => $isMatched,
                'status' => (string) ($sourceData['status'] ?? 'error'),
            ];

            if (!$isMatched || $candidateKey === '') {
                continue;
            }

            if (!isset($candidateGroups[$candidateKey])) {
                $candidateGroups[$candidateKey] = [
                    'key' => $candidateKey,
                    'canonical_name' => $candidate['name'],
                    'sources' => [],
                    'rows' => [],
                    'similarity_sum' => 0.0,
                    'priority_sum' => 0.0,
                    'quality_sum' => 0.0,
                    'max_source_confidence' => 0.0,
                    'types' => [],
                ];
            }

            $candidateGroups[$candidateKey]['sources'][] = (string) $sourceName;
            $candidateGroups[$candidateKey]['rows'][] = [
                'source' => (string) $sourceName,
                'name_similarity' => $nameSimilarity,
                'priority_weight' => $priority,
                'confidence' => $quality,
                'source_confidence' => $sourceConfidence,
                'candidate_type' => $candidate['type'],
            ];
            $candidateGroups[$candidateKey]['similarity_sum'] += $nameSimilarity;
            $candidateGroups[$candidateKey]['priority_sum'] += $priority;
            $candidateGroups[$candidateKey]['quality_sum'] += ($quality * $priority);
            $candidateGroups[$candidateKey]['max_source_confidence'] = max(
                (float) $candidateGroups[$candidateKey]['max_source_confidence'],
                $sourceConfidence
            );

            if ($candidate['type'] !== '') {
                $candidateGroups[$candidateKey]['types'][] = $candidate['type'];
            }

            $currentCanonical = (string) ($candidateGroups[$candidateKey]['canonical_name'] ?? '');
            if ($currentCanonical === '' || $this->preferName((string) $sourceName, $currentCanonical, $candidate['name'])) {
                $candidateGroups[$candidateKey]['canonical_name'] = $candidate['name'];
            }
        }

        $groupRows = [];
        foreach ($candidateGroups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $sources = array_values(array_unique(array_map('strval', (array) ($group['sources'] ?? []))));
            $sourceCount = count($sources);
            if ($sourceCount === 0) {
                continue;
            }

            $avgSimilarity = (float) ($group['similarity_sum'] ?? 0.0) / $sourceCount;
            $prioritySum = (float) ($group['priority_sum'] ?? 0.0);
            $qualitySum = (float) ($group['quality_sum'] ?? 0.0);

            $agreementRatio = $this->clamp($prioritySum / $this->maxPrioritySum());
            $qualityRatio = $this->clamp($qualitySum / max(0.8, $this->maxPrioritySum()));

            $typeConflict = $this->hasTypeConflict((array) ($group['types'] ?? []));
            $penalty = 0.0;
            $reducers = [];

            if ($typeConflict) {
                $penalty += 0.12;
                $reducers[] = 'Conflicting solo-vs-band type signals across sources.';
            }

            if ($sourceCount === 1 && !in_array('musicbrainz', $sources, true) && !in_array('spotify', $sources, true)) {
                $penalty += 0.08;
                $reducers[] = 'Only a lower-priority identity source matched.';
            }

            $confidence = $this->clamp(
                ($agreementRatio * 0.46)
                + ($avgSimilarity * 0.34)
                + ($qualityRatio * 0.16)
                + ((float) ($group['max_source_confidence'] ?? 0.0) * 0.04)
                - $penalty
            );

            $groupRows[] = [
                'canonical_name' => (string) ($group['canonical_name'] ?? $requestedName),
                'candidate_key' => (string) ($group['key'] ?? ''),
                'confidence' => $confidence,
                'source_count' => $sourceCount,
                'sources' => $sources,
                'avg_similarity' => round($avgSimilarity, 4),
                'priority_support' => round($prioritySum, 4),
                'type_signals' => array_values(array_unique(array_map('strval', (array) ($group['types'] ?? [])))),
                'reducers' => $reducers,
            ];
        }

        usort(
            $groupRows,
            static fn(array $a, array $b): int => (($b['confidence'] ?? 0.0) <=> ($a['confidence'] ?? 0.0))
        );

        $top = $groupRows[0] ?? null;
        $second = $groupRows[1] ?? null;

        if (!is_array($top)) {
            return [
                'canonical_name' => $requestedName,
                'match_type' => 'no_trustworthy_match',
                'confidence' => 0.0,
                'explanation' => 'No identity source returned a trustworthy artist candidate.',
                'source_matches' => $sourceMatches,
                'explainability' => [
                    'sources_agreed' => [],
                    'confidence_reducers' => ['No source candidate passed minimum name-similarity threshold.'],
                    'ambiguity_reasons' => [],
                    'candidate_rankings' => [],
                ],
            ];
        }

        $topConfidence = (float) ($top['confidence'] ?? 0.0);
        $secondConfidence = is_array($second) ? (float) ($second['confidence'] ?? 0.0) : 0.0;
        $confidenceGap = $topConfidence - $secondConfidence;

        $ambiguityReasons = [];
        if (is_array($second) && $secondConfidence >= 0.5 && $confidenceGap <= 0.08) {
            $ambiguityReasons[] = 'Two candidate identities have closely competing confidence scores.';
        }

        if (($top['source_count'] ?? 0) < 2 && $topConfidence < 0.84) {
            $ambiguityReasons[] = 'Only one source strongly supports the top candidate identity.';
        }

        $reducers = is_array($top['reducers'] ?? null) ? $top['reducers'] : [];

        $matchType = 'no_trustworthy_match';

        if ($topConfidence < 0.52) {
            $matchType = 'no_trustworthy_match';
            $reducers[] = 'Top candidate confidence is below trust threshold.';
        } elseif ($ambiguityReasons !== []) {
            $matchType = 'ambiguous_match';
        } elseif ($topConfidence >= 0.84 && (float) ($top['avg_similarity'] ?? 0.0) >= 0.95 && (int) ($top['source_count'] ?? 0) >= 2) {
            $matchType = 'exact_match';
        } else {
            $matchType = 'likely_match';
        }

        $confidence = $topConfidence;
        if ($matchType === 'ambiguous_match') {
            $confidence = $this->clamp($topConfidence - 0.12);
        }

        if ($matchType === 'no_trustworthy_match') {
            $confidence = min(0.5, $confidence);
        }

        $explanation = $this->buildExplanation(
            (string) ($top['canonical_name'] ?? $requestedName),
            $matchType,
            $confidence,
            is_array($top['sources'] ?? null) ? $top['sources'] : [],
            $ambiguityReasons,
            $reducers
        );

        return [
            'canonical_name' => (string) ($top['canonical_name'] ?? $requestedName),
            'match_type' => $matchType,
            'confidence' => round($confidence, 4),
            'explanation' => $explanation,
            'source_matches' => $sourceMatches,
            'explainability' => [
                'sources_agreed' => is_array($top['sources'] ?? null) ? $top['sources'] : [],
                'confidence_reducers' => array_values(array_unique($reducers)),
                'ambiguity_reasons' => $ambiguityReasons,
                'candidate_rankings' => array_slice($groupRows, 0, 5),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $sourceData
     * @return array{name: string, key: string, type: string, variants: array<int, string>}|null
     */
    private function extractCandidate(string $sourceName, array $sourceData): ?array
    {
        $payload = $sourceData['payload'] ?? [];
        if (!is_array($payload)) {
            return null;
        }

        $profile = $payload['profile'] ?? [];
        if (!is_array($profile)) {
            return null;
        }

        $name = trim((string) ($profile['name'] ?? $profile['title'] ?? ''));
        if ($name === '') {
            return null;
        }

        $aliases = [];

        $payloadAliases = $payload['aliases'] ?? [];
        if (is_array($payloadAliases)) {
            foreach ($payloadAliases as $alias) {
                $aliasName = trim((string) $alias);
                if ($aliasName !== '') {
                    $aliases[] = $aliasName;
                }
            }
        }

        $profileAliases = $profile['aliases'] ?? [];
        if (is_array($profileAliases)) {
            foreach ($profileAliases as $alias) {
                $aliasName = trim((string) $alias);
                if ($aliasName !== '') {
                    $aliases[] = $aliasName;
                }
            }
        }

        $sortName = trim((string) ($profile['sort_name'] ?? ''));
        if ($sortName !== '') {
            $aliases[] = $sortName;
        }

        $variants = array_values(array_unique(array_filter(array_merge([$name], $aliases), static fn(string $value): bool => $value !== '')));

        $type = strtolower(trim((string) ($profile['type'] ?? '')));
        if ($type === '' && $sourceName === 'wikipedia') {
            $description = strtolower((string) ($profile['description'] ?? ''));
            if (str_contains($description, 'band')) {
                $type = 'group';
            } elseif (str_contains($description, 'singer') || str_contains($description, 'musician')) {
                $type = 'person';
            }
        }

        return [
            'name' => $name,
            'key' => $this->normalizeKey($name),
            'type' => $this->normalizeType($type),
            'variants' => $variants,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function nameVariants(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        $variants = [$trimmed];

        if (preg_match('/^the\s+/i', $trimmed) === 1) {
            $variants[] = preg_replace('/^the\s+/i', '', $trimmed) ?? $trimmed;
        } else {
            $variants[] = 'The ' . $trimmed;
        }

        return array_values(array_unique(array_filter($variants, static fn(string $name): bool => trim($name) !== '')));
    }

    /**
     * @param array<int, string> $leftVariants
     * @param array<int, string> $rightVariants
     * @return array{score: float, variant: string}
     */
    private function bestSimilarity(array $leftVariants, array $rightVariants): array
    {
        $bestScore = 0.0;
        $bestVariant = $rightVariants[0] ?? '';

        foreach ($leftVariants as $left) {
            foreach ($rightVariants as $right) {
                $score = $this->similarity($left, $right);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestVariant = $right;
                }
            }
        }

        return [
            'score' => round($bestScore, 4),
            'variant' => $bestVariant,
        ];
    }

    private function normalizeKey(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/^the\s+/i', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $normalized) ?? $normalized;

        return $normalized;
    }

    private function similarity(string $left, string $right): float
    {
        $a = $this->normalizeKey($left);
        $b = $this->normalizeKey($right);

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 0.0;
        }

        $distance = levenshtein($a, $b);

        return $this->clamp(1 - ($distance / $maxLen));
    }

    /**
     * @param string[] $types
     */
    private function hasTypeConflict(array $types): bool
    {
        $values = array_values(array_unique(array_filter(array_map([$this, 'normalizeType'], $types), static fn(string $v): bool => $v !== '')));

        if ($values === []) {
            return false;
        }

        return in_array('group', $values, true) && in_array('person', $values, true);
    }

    private function normalizeType(string $value): string
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return '';
        }

        if (str_contains($normalized, 'group') || str_contains($normalized, 'band') || str_contains($normalized, 'orchestra')) {
            return 'group';
        }

        if (str_contains($normalized, 'person') || str_contains($normalized, 'solo') || str_contains($normalized, 'singer') || str_contains($normalized, 'musician')) {
            return 'person';
        }

        return $normalized;
    }

    private function maxPrioritySum(): float
    {
        return array_sum($this->sourcePriority);
    }

    private function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return round($value, 4);
    }

    /**
     * Prefer a canonical name from a higher-priority source, then shorter labels.
     */
    private function preferName(string $sourceName, string $current, string $candidate): bool
    {
        if ($current === '') {
            return true;
        }

        $currentKey = $this->normalizeKey($current);
        $candidateKey = $this->normalizeKey($candidate);
        if ($currentKey === $candidateKey && strlen($candidate) < strlen($current)) {
            return true;
        }

        $priority = $this->sourcePriority[$sourceName] ?? 0.0;

        return $priority >= 0.9;
    }

    /**
     * @param string[] $sources
     * @param string[] $ambiguityReasons
     * @param string[] $reducers
     */
    private function buildExplanation(
        string $canonical,
        string $matchType,
        float $confidence,
        array $sources,
        array $ambiguityReasons,
        array $reducers
    ): string {
        $sourceText = $sources === [] ? 'no sources' : implode(', ', $sources);

        if ($matchType === 'exact_match') {
            return sprintf(
                'Exact match for "%s" selected with %.2f confidence. Sources agreeing: %s.',
                $canonical,
                $confidence,
                $sourceText
            );
        }

        if ($matchType === 'likely_match') {
            return sprintf(
                'Likely match for "%s" selected with %.2f confidence. Sources agreeing: %s. Reducers: %s.',
                $canonical,
                $confidence,
                $sourceText,
                $reducers === [] ? 'none' : implode(' ', $reducers)
            );
        }

        if ($matchType === 'ambiguous_match') {
            return sprintf(
                'Ambiguous match for "%s" (%.2f confidence). Reasons: %s.',
                $canonical,
                $confidence,
                $ambiguityReasons === [] ? 'insufficient source agreement' : implode(' ', $ambiguityReasons)
            );
        }

        return sprintf(
            'No trustworthy match for "%s". Confidence %.2f is below threshold.',
            $canonical,
            $confidence
        );
    }
}
