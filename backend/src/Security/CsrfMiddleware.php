<?php
declare(strict_types=1);

namespace Vantiq\Security;

use Vantiq\Http\Request;

final class CsrfMiddleware
{
    public function __construct(private readonly SessionManager $session)
    {
    }

    public function handle(Request $request): void
    {
        if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $this->session->assertCsrf($request->header('x-csrf-token'));
        }
    }
}
