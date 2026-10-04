<?php
declare(strict_types=1);

namespace Evh;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = $config->get('DB_DSN');
        if ($dsn === '') {
            throw new \RuntimeException('DB_DSN manquant dans evh_private/.env');
        }

        return new PDO($dsn, $config->get('DB_USER'), $config->get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
    }
}
