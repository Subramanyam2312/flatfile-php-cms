<?php

declare(strict_types=1);

/**
 * Test runner.  Usage:
 *
 *   php tests/run.php                 run everything
 *   php tests/run.php Html            run only files matching "Html"
 *
 * Exits non-zero on any failure, so it can gate a deploy script.
 */

require __DIR__ . '/bootstrap.php';

// Buffer everything until the end.
//
// Not cosmetic: PHP refuses session_start() once headers_sent() is true, and on
// CLI that flips the moment anything is echoed. Printing progress as we go
// would break every test that touches a session, so output is held and flushed
// once at the end.
ob_start();
register_shutdown_function(static function (): void {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
});

$filter = $argv[1] ?? '';
$files  = glob(__DIR__ . '/*Test.php') ?: [];
sort($files);

$colour = stream_isatty(STDOUT);
$green  = $colour ? "\033[32m" : '';
$red    = $colour ? "\033[31m" : '';
$dim    = $colour ? "\033[2m"  : '';
$bold   = $colour ? "\033[1m"  : '';
$off    = $colour ? "\033[0m"  : '';

$totalPassed = 0;
$totalFailed = 0;
$started     = microtime(true);

foreach ($files as $file) {
    $group = basename($file, 'Test.php');

    if ($filter !== '' && stripos($group, $filter) === false) {
        continue;
    }

    /** @var array<string, callable> $tests */
    $tests = require $file;

    echo "{$bold}{$group}{$off}\n";

    foreach ($tests as $name => $test) {
        $before = count(Assert::$failures);

        try {
            $test();
            $error = null;
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage()
                . "\n      at " . $e->getFile() . ':' . $e->getLine();
        }

        $new = array_slice(Assert::$failures, $before);

        if ($error === null && $new === []) {
            $totalPassed++;
            echo "  {$green}✓{$off} {$dim}{$name}{$off}\n";
            continue;
        }

        $totalFailed++;
        echo "  {$red}✗{$off} {$name}\n";

        foreach ($new as $failure) {
            echo "      {$red}{$failure}{$off}\n";
        }

        if ($error !== null) {
            echo "      {$red}{$error}{$off}\n";
        }
    }

    echo "\n";
}

$elapsed = number_format((microtime(true) - $started) * 1000);
$summary = "{$totalPassed} passed, {$totalFailed} failed, "
    . Assert::$count . " assertions, {$elapsed}ms";

echo $totalFailed === 0
    ? "{$green}{$bold}✓ {$summary}{$off}\n"
    : "{$red}{$bold}✗ {$summary}{$off}\n";

exit($totalFailed === 0 ? 0 : 1);
