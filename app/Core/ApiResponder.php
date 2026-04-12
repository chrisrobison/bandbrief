<?php

declare(strict_types=1);

namespace App\Core;

final class ApiResponder
{
    public static function success(array $data = [], array $warnings = [], array $meta = [], int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'ok' => true,
            'data' => $data,
            'warnings' => $warnings,
            'meta' => $meta,
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function error(string $code, string $message, int $status, array $warnings = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode([
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'warnings' => $warnings,
        ], JSON_UNESCAPED_SLASHES);
    }
}
