<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\ApiBase;
use App\Core\ApiException;
use App\Services\ReportService;

final class ReportsApi extends ApiBase
{
    private ReportService $reportService;

    public function __construct(?\App\Core\Request $request = null)
    {
        parent::__construct($request);
        $this->reportService = new ReportService();
    }

    /**
     * @param string[] $routeParams
     * @return array<string, mixed>
     */
    public function create(array $routeParams = []): array
    {
        $this->requireMethod('POST');

        $json = $this->getJson();
        $name = trim((string) ($json['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('Field name is required', 'invalid_input', 422);
        }

        $force = (bool) ($json['force'] ?? false);

        $result = $this->reportService->create($name, $force);

        return $this->ok($result, [
            'requested_name' => $name,
            'force' => $force,
            'source_status' => is_array($result['source_status'] ?? null) ? $result['source_status'] : [],
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
            throw new ApiException('Report id is required', 'invalid_input', 422);
        }

        $result = $this->reportService->view($id);
        if ($result === []) {
            throw new ApiException('Report not found', 'not_found', 404);
        }

        return $this->ok($result);
    }

    /**
     * @param string[] $routeParams
     * @return array<string, mixed>
     */
    public function status(array $routeParams = []): array
    {
        $this->requireMethod('GET');

        $id = isset($routeParams[0]) ? (int) $routeParams[0] : $this->request->queryInt('id', 0, 0, PHP_INT_MAX);
        if ($id <= 0) {
            throw new ApiException('Report id is required', 'invalid_input', 422);
        }

        $status = $this->reportService->status($id);
        if ($status === []) {
            throw new ApiException('Report not found', 'not_found', 404);
        }

        return $this->ok($status);
    }
}
