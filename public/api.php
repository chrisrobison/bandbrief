<?php

declare(strict_types=1);

use App\Core\ApiBase;
use App\Core\ApiException;
use App\Core\Request;
use App\Core\Response;
use App\Support\Env;

require_once __DIR__ . '/../app/Core/Autoload.php';
Env::load(__DIR__ . '/../.env');

try {
    $pathInfo = resolvePathInfo();
    $segments = parsePathSegments($pathInfo);

    $resource = $segments[0] ?? '';
    $action = $segments[1] ?? 'index';
    $routeParams = array_slice($segments, 2);

    if ($resource === '') {
        Response::send(Response::ok([
            'routes' => [
                '/api.php/{resource}/{action}',
                '/api.php/artists/search?q=radiohead',
                '/api.php/reports/create',
            ],
        ], [
            'resource' => 'index',
            'action' => 'index',
        ]));
        exit;
    }

    if (!isValidSegment($resource) || !isValidSegment($action)) {
        throw new ApiException('Invalid API route', 'invalid_route', 404);
    }

    $className = 'App\\Api\\' . toStudly($resource) . 'Api';

    if (!class_exists($className)) {
        throw new ApiException('API resource not found', 'not_found', 404);
    }

    $request = Request::fromGlobals($routeParams);
    $api = new $className($request);

    if (!$api instanceof ApiBase) {
        throw new ApiException('Invalid API resource', 'invalid_resource', 500);
    }

    if (!method_exists($api, $action)) {
        throw new ApiException('API action not found', 'not_found', 404);
    }

    $method = new ReflectionMethod($api, $action);
    if (!$method->isPublic() || $method->isStatic() || str_starts_with($action, '__')) {
        throw new ApiException('API action not callable', 'not_found', 404);
    }

    $result = $api->$action($routeParams);
    if (!is_array($result)) {
        throw new ApiException('API action returned invalid payload', 'invalid_response', 500);
    }

    $status = 200;

    if (!array_key_exists('ok', $result)) {
        $result = Response::ok($result, [
            'resource' => $resource,
            'action' => $action,
        ]);
    } else {
        if (($result['ok'] ?? false) === true) {
            $meta = $result['meta'] ?? [];
            if (!is_array($meta)) {
                $meta = [];
            }
            $meta['resource'] = $resource;
            $meta['action'] = $action;
            $result['meta'] = $meta;
        } else {
            $status = 400;
        }
    }

    Response::send($result, $status);
} catch (ApiException $e) {
    Response::send(Response::fail($e->errorCode(), $e->getMessage()), $e->httpStatus());
} catch (Throwable $e) {
    Response::send(Response::fail('internal_error', 'Internal server error'), 500);
}

function resolvePathInfo(): string
{
    if (!empty($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])) {
        return (string) $_SERVER['PATH_INFO'];
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

    if ($requestUri === '' || $scriptName === '') {
        return '';
    }

    $uriPath = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($uriPath)) {
        return '';
    }

    if (str_starts_with($uriPath, $scriptName)) {
        return substr($uriPath, strlen($scriptName)) ?: '';
    }

    return '';
}

/**
 * @return string[]
 */
function parsePathSegments(string $pathInfo): array
{
    $trimmed = trim($pathInfo, '/');

    if ($trimmed === '') {
        return [];
    }

    return array_values(array_filter(explode('/', $trimmed), static fn(string $part): bool => $part !== ''));
}

function isValidSegment(string $segment): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $segment);
}

function toStudly(string $value): string
{
    $parts = explode('_', $value);
    $parts = array_map(static fn(string $part): string => ucfirst($part), $parts);

    return implode('', $parts);
}
