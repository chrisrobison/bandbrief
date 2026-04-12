<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\ApiBase;
use App\Core\ApiException;
use App\Services\ArtistService;

final class ArtistsApi extends ApiBase
{
    private ArtistService $artistService;

    public function __construct(?\App\Core\Request $request = null)
    {
        parent::__construct($request);
        $this->artistService = new ArtistService();
    }

    /**
     * @param string[] $routeParams
     * @return array<string, mixed>
     */
    public function search(array $routeParams = []): array
    {
        $this->requireMethod('GET');

        $query = trim((string) $this->getQuery('q', ''));
        if ($query === '') {
            throw new ApiException('Query parameter q is required', 'invalid_input', 422);
        }

        $limit = $this->request->queryInt('limit', 10, 1, 50);
        $items = $this->artistService->search($query, $limit);

        return $this->ok([
            'items' => $items,
            'count' => count($items),
        ], [
            'query' => $query,
            'limit' => $limit,
        ]);
    }

    /**
     * @param string[] $routeParams
     * @return array<string, mixed>
     */
    public function resolve(array $routeParams = []): array
    {
        $this->requireMethod('POST');

        $json = $this->getJson();
        $name = trim((string) ($json['name'] ?? ''));

        if ($name === '') {
            throw new ApiException('Field name is required', 'invalid_input', 422);
        }

        $resolved = $this->artistService->resolve($name);

        return $this->ok($resolved, [
            'requested_name' => $name,
        ]);
    }

    /**
     * @param string[] $routeParams
     * @return array<string, mixed>
     */
    public function view(array $routeParams = []): array
    {
        $this->requireMethod('GET');

        $id = isset($routeParams[0]) ? (int) $routeParams[0] : $this->request->queryInt('id', 0, 0, PHP_INT_MAX);
        if ($id <= 0) {
            throw new ApiException('Artist id is required', 'invalid_input', 422);
        }

        $artist = $this->artistService->view($id);
        if ($artist === []) {
            throw new ApiException('Artist not found', 'not_found', 404);
        }

        return $this->ok($artist);
    }
}
