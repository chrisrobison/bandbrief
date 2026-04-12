<?php

declare(strict_types=1);

use App\Api\BaseApi;
use App\Core\ApiException;
use App\Core\ApiResponder;

require_once __DIR__ . '/../app/Core/Autoload.php';

try {
    $pathInfo = resolvePathInfo();
    $segments = parsePathSegments($pathInfo);

    $resource = $segments[0] ?? '';
    $action = $segments[1] ?? 'index';
    $routeParams = array_slice($segments, 2);

    if ($resource === '') {
        ApiResponder::success([
            'routes' => [
                '/api.php/{resource}/{action}',
            ],
        ], [], ['resource' => 'index', 'action' => 'index']);
        exit;
    }

    if (!isValidSegment($resource) || !isValidSegment($action)) {
        throw new ApiException('Invalid API route', 'invalid_route', 400);
    }

    $class = 'App\\Api\\' . toStudly($resource) . 'Api';

    if (!class_exists($class)) {
        throw new ApiException('API resource not found', 'not_found', 404);
    }

    $api = new $class();

    if (!$api instanceof BaseApi) {
        throw new ApiException('Invalid API resource', 'invalid_resource', 500);
    }

    if (!method_exists($api, $action)) {
        throw new ApiException('API action not found', 'not_found', 404);
    }

    $refMethod = new ReflectionMethod($api, $action);
    if (!$refMethod->isPublic() || $refMethod->isStatic() || str_starts_with($action, '__')) {
        throw new ApiException('API action not callable', 'not_callable', 404);
    }

    $result = $api->$action($routeParams);
    if (!is_array($result)) {
        throw new ApiException('API action must return an array', 'invalid_response', 500);
    }

    $warnings = [];
    if (isset($result['warnings']) && is_array($result['warnings'])) {
        $warnings = $result['warnings'];
        unset($result['warnings']);
    }

    ApiResponder::success($result, $warnings, [
        'resource' => $resource,
        'action' => $action,
    ]);
} catch (ApiException $e) {
    ApiResponder::error($e->errorCode(), $e->getMessage(), $e->httpStatus());
} catch (Throwable $e) {
    ApiResponder::error('internal_error', 'Internal server error', 500);
}

function resolvePathInfo(): string
{
    if (!empty($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])) {
        return $_SERVER['PATH_INFO'];
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

    return array_values(array_filter(explode('/', $trimmed), static fn($part) => $part !== ''));
}

function isValidSegment(string $segment): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $segment);
}

function toStudly(string $value): string
{
    $parts = explode('_', $value);
    $parts = array_map(static fn($part) => ucfirst($part), $parts);

    return implode('', $parts);
}
