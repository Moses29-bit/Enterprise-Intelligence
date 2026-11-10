<?php
declare(strict_types=1);

http_response_code(410);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'error' => [
        'code' => 'legacy_login_removed',
        'message' => 'The demo login has been removed. Use the React authentication application.'
    ]
]);
