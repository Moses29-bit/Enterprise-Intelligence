<?php
declare(strict_types=1);

namespace Vantiq\Infrastructure;

use PDO;
use Vantiq\Config\Config;

final class Database
{
    public static function connect(Config $config): PDO
    {
        self::assertMysqlHandshake(
            (string) $config->get('db_host'),
            (int) $config->get('db_port')
        );

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
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 3
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }

    private static function assertMysqlHandshake(string $host, int $port): void
    {
        $socketHost = str_contains($host, ':') && !str_starts_with($host, '[')
            ? '[' . $host . ']'
            : $host;
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $socketHost, $port),
            $errorCode,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            throw new \PDOException('Unable to connect to the configured MySQL server.');
        }

        stream_set_timeout($socket, 2);
        $greeting = fread($socket, 4);
        $metadata = stream_get_meta_data($socket);
        fclose($socket);

        if (!is_string($greeting) || strlen($greeting) !== 4 || $metadata['timed_out']) {
            throw new \PDOException('The MySQL server did not complete its handshake.');
        }
    }
}
