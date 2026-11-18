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
                    Response::success($handler($request, $params));
                    return;
                }
            }

            throw new ApiException(404, 'not_found', 'The requested endpoint was not found.');
        } catch (ApiException $exception) {
            Response::error(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->details === [] ? null : $exception->details,
                $exception->status
            );
        } catch (Throwable $exception) {
            $referenceId = $this->logger->exception(
                'Unhandled API exception',
                $exception,
                self::errorContext($request)
            );
            Response::error('internal_error', 'The service is temporarily unavailable.', null, 500, $referenceId);
        }
    }

    private static function errorContext(Request $request): array
    {
        return [
            'operation' => substr($request->method . ' ' . $request->path, 0, 256),
            'stage' => 'route_dispatch',
            'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            'organisation_id' => isset($_SESSION['organisation_id']) ? (int) $_SESSION['organisation_id'] : null
        ];
    }
}
