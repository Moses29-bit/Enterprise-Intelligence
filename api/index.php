<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Application;
use Vantiq\Config\Config;
use Vantiq\Http\Request;
use Vantiq\Infrastructure\Database;
use Vantiq\Infrastructure\Logger;

try {
    $config = Config::load(dirname(__DIR__));
    $database = Database::connect($config);
    (new Application($config, $database, new Logger()))->handle(Request::fromGlobals());
} catch (Throwable $exception) {
    (new Logger())->error('Application bootstrap failed', ['exception' => $exception::class]);
    http_response_code(500);
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    echo json_encode([
        'error' => [
            'code' => 'internal_error',
            'message' => 'The service is temporarily unavailable.'
        ]
    ]);
}
