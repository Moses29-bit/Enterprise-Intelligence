<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/backend/bootstrap.php';

use Vantiq\Config\Config;
use Vantiq\Infrastructure\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = Database::connect(Config::load(dirname(__DIR__, 2)));
$version = '004_business_permissions';
$check = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version');
$check->execute(['version' => $version]);
if ($check->fetchColumn()) {
    fwrite(STDOUT, "Business permissions migration 004 is already applied.\n");
    exit(0);
}

$permissions = [
    'finance.write' => 'Create and manage financial records',
    'operations.write' => 'Manage products and inventory movements',
    'connect.manage' => 'Validate and manage data imports',
    'risks.manage' => 'Assign and resolve risk alerts',
];
$permissionInsert = $pdo->prepare(
    'INSERT INTO permissions (code, description) VALUES (:code, :description)
     ON DUPLICATE KEY UPDATE description = VALUES(description)'
);
foreach ($permissions as $code => $description) {
    $permissionInsert->execute(['code' => $code, 'description' => $description]);
}

$rolePermissions = [
    'Organisation Owner' => ['finance.write', 'operations.write', 'connect.manage', 'risks.manage'],
    'Executive / Director' => ['risks.manage'],
    'Financial Analyst' => ['finance.write'],
    'Operations Manager' => ['operations.write', 'connect.manage', 'risks.manage'],
];
$assign = $pdo->prepare(
    'INSERT IGNORE INTO role_permissions (role_id, permission_id)
     SELECT r.id, p.id
     FROM roles r
     JOIN permissions p ON p.code = :permission
     WHERE r.name = :role AND r.is_system = 0'
);
foreach ($rolePermissions as $role => $codes) {
    foreach ($codes as $code) {
        $assign->execute(['role' => $role, 'permission' => $code]);
    }
}

$record = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:version, CURRENT_TIMESTAMP)');
$record->execute(['version' => $version]);
fwrite(STDOUT, "Business permissions migration 004 completed.\n");
