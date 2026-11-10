<?php
declare(strict_types=1);

namespace Vantiq\Http;

use Throwable;
use Vantiq\Infrastructure\Logger;

final class Router
{
    private array $routes = [];

    public function __construct(private readonly Logger $logger)
    {
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    public function dispatch(Request $request): void
    {
        try {
            foreach ($this->routes as [$method, $pattern, $handler]) {
                if ($request->method === $method && preg_match($pattern, $request->path, $matches)) {
                    $params = array_filter($matches, static fn (string|int $key): bool => is_string($key), ARRAY_FILTER_USE_KEY);
                    Response::json(['data' => $handler($request, $params)]);
                    return;
                }
            }

            throw new ApiException(404, 'not_found', 'The requested endpoint was not found.');
        } catch (ApiException $exception) {
            Response::json([
                'error' => [
                    'code' => $exception->errorCode,
                    'message' => $exception->getMessage(),
                    'details' => $exception->details === [] ? null : $exception->details
                ]
            ], $exception->status);
        } catch (Throwable $exception) {
            $this->logger->error('Unhandled API exception', ['exception' => $exception::class]);
            Response::json([
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'The service is temporarily unavailable.'
                ]
            ], 500);
        }
    }
}
