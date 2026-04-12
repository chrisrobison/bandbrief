<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @return array{ok: bool, data: array<string, mixed>, meta: array<string, mixed>}
     */
    public static function ok(array $data = [], array $meta = []): array
    {
        return [
            'ok' => true,
            'data' => $data,
            'meta' => $meta,
        ];
    }

    /**
     * @return array{ok: bool, error: array{code: string, message: string}}
     */
    public static function fail(string $code, string $message): array
    {
        return [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function send(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
