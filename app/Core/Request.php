<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;

    /** @var array<string, mixed> */
    private array $query;

    /** @var array<string, mixed> */
    private array $post;

    /** @var array<string, mixed>|null */
    private ?array $json = null;

    /** @var string[] */
    private array $routeParams;

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param string[] $routeParams
     */
    public function __construct(string $method, array $query, array $post, array $routeParams = [])
    {
        $this->method = strtoupper($method);
        $this->query = $query;
        $this->post = $post;
        $this->routeParams = $routeParams;
    }

    /**
     * @param string[] $routeParams
     */
    public static function fromGlobals(array $routeParams = []): self
    {
        return new self(
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $_GET,
            $_POST,
            $routeParams
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function queryString(string $key, string $default = ''): string
    {
        $value = $this->query($key, $default);

        return is_string($value) ? trim($value) : $default;
    }

    public function queryInt(string $key, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $raw = $this->query($key, $default);
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
        $safeValue = (int) $value;

        if ($safeValue < $min) {
            return $min;
        }

        if ($safeValue > $max) {
            return $max;
        }

        return $safeValue;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if (is_array($this->json)) {
            return $this->json;
        }

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (!str_contains($contentType, 'application/json')) {
            $this->json = [];
            return $this->json;
        }

        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            $this->json = [];
            return $this->json;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new ApiException('Malformed JSON payload', 'invalid_json', 400);
        }

        $this->json = $decoded;

        return $this->json;
    }

    /**
     * @return array<string, mixed>
     */
    public function post(): array
    {
        return $this->post;
    }

    public function routeParam(int $index, ?string $default = null): ?string
    {
        return $this->routeParams[$index] ?? $default;
    }

    /**
     * @return string[]
     */
    public function routeParams(): array
    {
        return $this->routeParams;
    }
}
