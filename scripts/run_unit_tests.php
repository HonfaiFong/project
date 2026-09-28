<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/TestCase.php';

$testFiles = glob(__DIR__ . '/../tests/Unit/*Test.php');
$passed = 0;
$failed = 0;

echo "Running Unit Tests...\n";
echo "========================================\n";

foreach ($testFiles as $file) {
    require_once $file;
    $className = 'Tests\\Unit\\' . basename($file, '.php');

    if (!class_exists($className)) {
        continue;
    }

    $ref = new ReflectionClass($className);
    $instance = $ref->newInstance();

    foreach ($ref->getMethods() as $method) {
        if (!str_starts_with($method->getName(), 'test')) {
            continue;
        }

        // Run setUp if exists
        if ($ref->hasMethod('setUp')) {
            $setUp = $ref->getMethod('setUp');
            $setUp->invoke($instance);
        }

        $methodName = $method->getName();
        try {
            $method->invoke($instance);
            echo " [PASS] {$className}::{$methodName}\n";
            $passed++;
        } catch (\Throwable $e) {
            echo " [FAIL] {$className}::{$methodName} -> " . $e->getMessage() . "\n";
            $failed++;
        }
    }
}

echo "========================================\n";
echo "Total: " . ($passed + $failed) . " | Passed: {$passed} | Failed: {$failed}\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
