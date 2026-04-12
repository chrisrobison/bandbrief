<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        /** @var array{db: array<string, mixed>} $config */
        $config = require __DIR__ . '/../Config/config.php';
        $db = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            (string) $db['host'],
            (int) $db['port'],
            (string) $db['name'],
            (string) $db['charset']
        );

        try {
            self::$pdo = new PDO(
                $dsn,
                (string) $db['user'],
                (string) $db['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
        } catch (PDOException $e) {
            throw new ApiException('Database connection failed', 'db_unavailable', 503);
        }

        return self::$pdo;
    }
}
