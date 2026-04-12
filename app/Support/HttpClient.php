<?php

declare(strict_types=1);

namespace App\Support;

final class HttpClient
{
    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, body: string, error: string|null}
     */
    public function get(string $url, array $headers = [], int $timeoutSeconds = 10): array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Failed to initialize cURL',
            ];
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = sprintf('%s: %s', $key, $value);
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl) ?: null;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => $error ?? 'HTTP request failed',
            ];
        }

        $isOk = $status >= 200 && $status < 300;

        return [
            'ok' => $isOk,
            'status' => $status,
            'body' => $body,
            'error' => $isOk ? null : ($error ?? ('HTTP ' . $status)),
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int, data: array<string, mixed>, error: string|null}
     */
    public function getJson(string $url, array $headers = [], int $timeoutSeconds = 10): array
    {
        $response = $this->get($url, $headers, $timeoutSeconds);

        if ($response['body'] === '') {
            return [
                'ok' => false,
                'status' => $response['status'],
                'data' => [],
                'error' => $response['error'] ?? 'Empty response body',
            ];
        }

        $decoded = json_decode($response['body'], true);

        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'status' => $response['status'],
                'data' => [],
                'error' => 'Invalid JSON response',
            ];
        }

        return [
            'ok' => $response['ok'],
            'status' => $response['status'],
            'data' => $decoded,
            'error' => $response['error'],
        ];
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $formParams
     * @return array{ok: bool, status: int, body: string, error: string|null}
     */
    public function postForm(string $url, array $headers = [], array $formParams = [], int $timeoutSeconds = 10): array
    {
        $curl = curl_init($url);

        if ($curl === false) {
            return [
                'ok' => false,
                'status' => 0,
                'body' => '',
                'error' => 'Failed to initialize cURL',
            ];
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = sprintf('%s: %s', $key, $value);
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($formParams),
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl) ?: null;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($body === false) {
            return [
                'ok' => false,
                'status' => $status,
                'body' => '',
                'error' => $error ?? 'HTTP request failed',
            ];
        }

        $isOk = $status >= 200 && $status < 300;

        return [
            'ok' => $isOk,
            'status' => $status,
            'body' => $body,
            'error' => $isOk ? null : ($error ?? ('HTTP ' . $status)),
        ];
    }
}
