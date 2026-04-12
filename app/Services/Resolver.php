<?php

declare(strict_types=1);

namespace App\Services;

final class Resolver
{
    /**
     * @param array<string, array<string, mixed>> $sourcePayloads
     * @return array<string, mixed>
     */
    public function resolve(string $requestedName, array $sourcePayloads): array
    {
        $requested = $this->normalize($requestedName);

        $candidates = [];
        foreach ($sourcePayloads as $sourceName => $sourceData) {
            $candidateName = $this->extractCandidateName($sourceData);
            if ($candidateName === '') {
                continue;
            }

            $sim = $this->similarity($requested, $this->normalize($candidateName));
            $sourceConfidence = (float) ($sourceData['confidence'] ?? 0.0);
            $finalConfidence = round(($sim * 0.7) + ($sourceConfidence * 0.3), 4);

            $candidates[] = [
                'source' => $sourceName,
                'candidate' => $candidateName,
                'name_similarity' => $sim,
                'confidence' => $finalConfidence,
                'matched' => $finalConfidence >= 0.5,
            ];
        }

        usort(
            $candidates,
            static fn(array $a, array $b): int => ($b['confidence'] <=> $a['confidence'])
        );

        $top = $candidates[0] ?? null;
        if (!is_array($top)) {
            return [
                'canonical_name' => $requestedName,
                'match_type' => 'unresolved',
                'confidence' => 0.0,
                'explanation' => 'No source returned a confident identity match.',
                'source_matches' => $candidates,
            ];
        }

        $confidence = (float) $top['confidence'];
        $matchType = 'weak';

        if ($confidence >= 0.9) {
            $matchType = 'exact';
        } elseif ($confidence >= 0.7) {
            $matchType = 'fuzzy';
        }

        $explanation = sprintf(
            'Best match from %s with confidence %.2f using normalized name similarity and source confidence.',
            (string) $top['source'],
            $confidence
        );

        return [
            'canonical_name' => (string) $top['candidate'],
            'match_type' => $matchType,
            'confidence' => round($confidence, 4),
            'explanation' => $explanation,
            'source_matches' => $candidates,
        ];
    }

    /**
     * @param array<string, mixed> $sourceData
     */
    private function extractCandidateName(array $sourceData): string
    {
        $payload = $sourceData['payload'] ?? [];
        if (!is_array($payload)) {
            return '';
        }

        $profile = $payload['profile'] ?? [];
        if (is_array($profile)) {
            foreach (['name', 'title'] as $field) {
                $value = $profile[$field] ?? '';
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        $title = $payload['title'] ?? '';

        return is_string($title) ? trim($title) : '';
    }

    private function normalize(string $value): string
    {
        $normalized = strtolower(trim($value));

        return preg_replace('/[^a-z0-9]+/i', '', $normalized) ?? $normalized;
    }

    private function similarity(string $left, string $right): float
    {
        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $maxLen = max(strlen($left), strlen($right));
        if ($maxLen === 0) {
            return 0.0;
        }

        $distance = levenshtein($left, $right);
        $score = 1 - ($distance / $maxLen);

        if ($score < 0) {
            return 0.0;
        }

        if ($score > 1) {
            return 1.0;
        }

        return round($score, 4);
    }
}
