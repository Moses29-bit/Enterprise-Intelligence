<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
$failed = 0;

foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }

    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $output, $status);
    if ($status !== 0) {
        $failed++;
        fwrite(STDERR, implode(PHP_EOL, $output) . PHP_EOL);
    }
    $output = [];
}

fwrite(STDOUT, $failed === 0 ? "PHP syntax checks passed.\n" : "{$failed} PHP syntax error(s).\n");
exit($failed === 0 ? 0 : 1);
