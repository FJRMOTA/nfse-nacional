<?php

declare(strict_types=1);

$projectAutoload = getenv('PROJECT_AUTOLOAD');
if (is_string($projectAutoload) && $projectAutoload !== '') {
    require_once $projectAutoload;
}

$passed = 0;
$failed = 0;
$test = static function (string $name, callable $callback) use (&$passed, &$failed): void {
    try { $callback(); echo "PASS {$name}\n"; $passed++; }
    catch (Throwable $error) { echo "FAIL {$name}: {$error->getMessage()}\n"; $failed++; }
};
$assert = static function (bool $condition, string $message = 'assertion failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};

if (class_exists('NFePHP\\Common\\DOMImproved')) {
    require __DIR__ . '/DpsIbsCbsTest.php';
    if (is_file(__DIR__ . '/CnpjSchemaTest.php')) {
        require __DIR__ . '/CnpjSchemaTest.php';
    }
} else {
    echo "SKIP DPS: nfephp-org/sped-common não disponível no ambiente de teste\n";
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
