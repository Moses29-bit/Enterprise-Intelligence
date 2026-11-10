<?php
declare(strict_types=1);

namespace Vantiq\Infrastructure;

use PDO;
use Vantiq\Config\Config;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get('db_host'),
            $config->get('db_port'),
            $config->get('db_name')
        );

        $pdo = new PDO($dsn, (string) $config->get('db_user'), (string) $config->get('db_password'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
