<?php
// Minimal hand-rolled test runner (no PHPUnit in this repo): discovers every
// test_* function defined across test/*.test.php and runs it.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    die();
}

function assertSame($expected, $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? "$message: " : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assertTrue($condition, string $message = ''): void {
    assertSame(true, $condition, $message);
}

function assertNull($value, string $message = ''): void {
    assertSame(null, $value, $message);
}

// Shared fixture: a throwaway FTree backed by a temp file, cleaned up after use.
function withTempFTree(callable $fn): void {
    $path = tempnam(sys_get_temp_dir(), 'ftree_test_');
    unlink($path);
    try {
        $ft = new FTree($path);
        $fn($ft);
    } finally {
        unset($ft);
        @unlink($path);
    }
}

foreach (glob(__DIR__ . '/test/*.test.php') as $file) {
    require $file;
}

$passed = 0;
$failures = [];

foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_')) {
        continue;
    }
    try {
        $fn();
        echo "PASS  $fn\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "FAIL  $fn\n";
        $failures[] = ['name' => $fn, 'message' => $e->getMessage()];
    }
}

printf("\n%d passed, %d failed\n", $passed, count($failures));

if ($failures) {
    echo "\nFailures:\n";
    foreach ($failures as $failure) {
        echo "  {$failure['name']}\n";
        echo "    {$failure['message']}\n";
    }
}

exit($failures ? 1 : 0);
