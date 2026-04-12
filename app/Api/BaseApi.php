<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\ApiException;

abstract class BaseApi
{
    protected function requireMethod(string $expectedMethod): void
    {
        $actualMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if ($actualMethod !== strtoupper($expectedMethod)) {
            throw new ApiException('Method not allowed', 'method_not_allowed', 405);
        }
    }

    protected function queryString(string $name, string $default = ''): string
    {
        $value = $_GET[$name] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    protected function queryInt(string $name, int $default, int $min, int $max): int
    {
        $raw = $_GET[$name] ?? $default;
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
        $value = max($min, min($max, (int) $value));

        return $value;
    }
}
