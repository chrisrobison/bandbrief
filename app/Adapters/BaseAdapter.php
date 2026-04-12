<?php

declare(strict_types=1);

namespace App\Adapters;

use App\Support\HttpClient;

abstract class BaseAdapter
{
    protected HttpClient $http;

    /** @var array<string, mixed> */
    protected array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(HttpClient $http, array $config = [])
    {
        $this->http = $http;
        $this->config = $config;
    }

    abstract public function sourceName(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function fetchArtist(string $artistName): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function success(string $method, float $confidence, array $payload): array
    {
        return [
            'source' => $this->sourceName(),
            'collection_method' => $method,
            'status' => 'ok',
            'confidence' => $this->clamp($confidence),
            'fetched_at' => gmdate('c'),
            'payload' => $payload,
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param string[] $errors
     * @return array<string, mixed>
     */
    protected function partial(string $method, float $confidence, array $payload, array $errors = []): array
    {
        return [
            'source' => $this->sourceName(),
            'collection_method' => $method,
            'status' => 'partial',
            'confidence' => $this->clamp($confidence),
            'fetched_at' => gmdate('c'),
            'payload' => $payload,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function failed(string $method, string $errorCode, string $message): array
    {
        return [
            'source' => $this->sourceName(),
            'collection_method' => $method,
            'status' => 'error',
            'confidence' => 0.0,
            'fetched_at' => gmdate('c'),
            'payload' => [],
            'errors' => [[
                'code' => $errorCode,
                'message' => $message,
            ]],
        ];
    }

    protected function clamp(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        if ($value > 1.0) {
            return 1.0;
        }

        return round($value, 4);
    }

    protected function normalizeName(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', '', $normalized) ?? $normalized;

        return $normalized;
    }

    protected function similarity(string $a, string $b): float
    {
        $left = $this->normalizeName($a);
        $right = $this->normalizeName($b);

        if ($left === '' || $right === '') {
            return 0.0;
        }

        if ($left === $right) {
            return 1.0;
        }

        $max = max(strlen($left), strlen($right));
        if ($max === 0) {
            return 0.0;
        }

        $distance = levenshtein($left, $right);

        return $this->clamp(1 - ($distance / $max));
    }
}
