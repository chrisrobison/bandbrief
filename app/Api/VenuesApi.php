<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\ApiException;
use App\Core\Db;
use PDO;
use PDOException;

final class VenuesApi extends BaseApi
{
    public function list(array $routeParams = []): array
    {
        $this->requireMethod('GET');

        $limit = $this->queryInt('limit', 20, 1, 100);
        $offset = $this->queryInt('offset', 0, 0, 100000);
        $search = $this->queryString('q', '');

        try {
            $pdo = Db::pdo();
            $result = $this->queryVenues($pdo, $search, $limit, $offset);
        } catch (ApiException $e) {
            if ($e->errorCode() === 'db_unavailable') {
                return [
                    'items' => [],
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'search' => $search,
                    'source_status' => [
                        'db' => 'partial',
                        'detail' => 'database unavailable',
                    ],
                    'warnings' => ['Database unavailable; returned empty result set.'],
                ];
            }

            throw $e;
        } catch (PDOException $e) {
            // 42S02 = Base table not found. Keep endpoint usable during bootstrap.
            if (($e->getCode() ?? '') === '42S02') {
                return [
                    'items' => [],
                    'total' => 0,
                    'limit' => $limit,
                    'offset' => $offset,
                    'search' => $search,
                    'source_status' => [
                        'db' => 'partial',
                        'detail' => 'venues table missing',
                    ],
                    'warnings' => ['Venues table is missing; returned empty result set.'],
                ];
            }

            throw new ApiException('Failed to query venues', 'query_failed', 500);
        }

        return [
            'items' => $result['items'],
            'total' => $result['total'],
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
            'source_status' => [
                'db' => 'ok',
            ],
        ];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    private function queryVenues(PDO $pdo, string $search, int $limit, int $offset): array
    {
        $whereSql = '';
        $whereParams = [];

        if ($search !== '') {
            $whereSql = 'WHERE name LIKE :search OR city LIKE :search';
            $whereParams[':search'] = '%' . $search . '%';
        }

        $countSql = "SELECT COUNT(*) AS total FROM venues {$whereSql}";
        $countStmt = $pdo->prepare($countSql);
        foreach ($whereParams as $key => $value) {
            $countStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $countStmt->execute();
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $listSql = "
            SELECT id, name, city, region, country, capacity, website_url
            FROM venues
            {$whereSql}
            ORDER BY name ASC
            LIMIT :limit OFFSET :offset
        ";

        $listStmt = $pdo->prepare($listSql);
        foreach ($whereParams as $key => $value) {
            $listStmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $listStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $listStmt->execute();

        $items = $listStmt->fetchAll() ?: [];

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
