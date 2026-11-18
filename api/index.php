<?php
declare(strict_types=1);

require dirname(__DIR__) . '/backend/bootstrap.php';

use Vantiq\Application;
use Vantiq\Config\Config;
use Vantiq\Http\Request;
use Vantiq\Infrastructure\Database;
use Vantiq\Infrastructure\Logger;

$request = null;
try {
    $config = Config::load(dirname(__DIR__));
    $logger = new Logger();
    $request = Request::fromGlobals();
    try {
        $database = Database::connect($config);
    } catch (Throwable $exception) {
        $referenceId = $logger->exception('Database connection failed', $exception, [
            'operation' => substr($request->method . ' ' . $request->path, 0, 256),
            'stage' => 'database_connect'
        ]);
        (new Application($config, null, $logger, $referenceId))->handle($request);
        return;
    }
    (new Application($config, $database, $logger))->handle($request);
} catch (Throwable $exception) {
    $context = ['operation' => 'API bootstrap', 'stage' => 'bootstrap'];
    if ($request instanceof Request) {
        $context['operation'] = substr($request->method . ' ' . $request->path, 0, 256);
    }
    $referenceId = (new Logger())->exception('Application bootstrap failed', $exception, $context);
    http_response_code(500);
    header_remove('X-Powered-By');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    echo json_encode([
        'success' => false,
        'data' => null,
        'error' => [
            'code' => 'internal_error',
            'message' => 'The service is temporarily unavailable.',
            'details' => null,
            'reference_id' => $referenceId
        ]
    ]);
}
