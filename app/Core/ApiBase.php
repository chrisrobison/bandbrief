<?php

declare(strict_types=1);

namespace App\Core;

abstract class ApiBase
{
    protected Request $request;

    public function __construct(?Request $request = null)
    {
        $this->request = $request ?? Request::fromGlobals();
    }

    protected function getQuery(string $key, mixed $default = null): mixed
    {
        return $this->request->query($key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getJson(): array
    {
        return $this->request->json();
    }

    protected function requireMethod(string $expectedMethod): void
    {
        $actualMethod = $this->request->method();

        if ($actualMethod !== strtoupper($expectedMethod)) {
            throw new ApiException('Method not allowed', 'method_not_allowed', 405);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    protected function ok(array $data = [], array $meta = []): array
    {
        return Response::ok($data, $meta);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fail(string $code, string $message): array
    {
        return Response::fail($code, $message);
    }
}
