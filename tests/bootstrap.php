<?php

declare(strict_types=1);

/**
 * Test harness.
 *
 * Deliberately not PHPUnit. This project's whole claim is that it runs on a
 * host with nothing installed, and a test suite you cannot run without first
 * running `composer install` undercuts that for exactly the people most likely
 * to be evaluating it. `php tests/run.php` works on a clean clone.
 *
 * The trade is real: no mocking, no data providers, no coverage report. If this
 * suite ever needs those, it has outgrown the runner and should move to PHPUnit
 * as a dev dependency.
 */

require dirname(__DIR__) . '/src/App.php';

// App::boot() registers the autoloader, but booting also constructs a Store
// against the real data/ directory. Tests want the class map without that side
// effect, so register the same loader directly.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Cms\\')) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

final class Assert
{
    public static int $count = 0;

    /** @var list<string> */
    public static array $failures = [];

    public static function true(bool $actual, string $because): void
    {
        self::$count++;
        if ($actual !== true) {
            self::$failures[] = "{$because}\n      expected true, got false";
        }
    }

    public static function false(bool $actual, string $because): void
    {
        self::$count++;
        if ($actual !== false) {
            self::$failures[] = "{$because}\n      expected false, got true";
        }
    }

    public static function same(mixed $expected, mixed $actual, string $because): void
    {
        self::$count++;
        if ($expected !== $actual) {
            self::$failures[] = sprintf(
                "%s\n      expected: %s\n      actual:   %s",
                $because,
                self::show($expected),
                self::show($actual)
            );
        }
    }

    public static function contains(string $needle, string $haystack, string $because): void
    {
        self::$count++;
        if (!str_contains($haystack, $needle)) {
            self::$failures[] = sprintf(
                "%s\n      expected to find: %s\n      in:               %s",
                $because,
                self::show($needle),
                self::show($haystack)
            );
        }
    }

    public static function missing(string $needle, string $haystack, string $because): void
    {
        self::$count++;
        if (str_contains($haystack, $needle)) {
            self::$failures[] = sprintf(
                "%s\n      expected NOT to find: %s\n      but it is in:         %s",
                $because,
                self::show($needle),
                self::show($haystack)
            );
        }
    }

    /** Assert that $body throws, optionally matching a substring of the message. */
    public static function throws(callable $body, string $expectMessage, string $because): void
    {
        self::$count++;

        try {
            $body();
        } catch (\Throwable $e) {
            if ($expectMessage !== '' && !str_contains($e->getMessage(), $expectMessage)) {
                self::$failures[] = sprintf(
                    "%s\n      threw, but message did not contain: %s\n      actual message: %s",
                    $because,
                    self::show($expectMessage),
                    self::show($e->getMessage())
                );
            }

            return;
        }

        self::$failures[] = "{$because}\n      expected an exception, none thrown";
    }

    private static function show(mixed $value): string
    {
        $text = match (true) {
            is_string($value) => '"' . $value . '"',
            is_bool($value)   => $value ? 'true' : 'false',
            is_null($value)   => 'null',
            is_scalar($value) => (string) $value,
            default           => json_encode($value, JSON_UNESCAPED_SLASHES) ?: gettype($value),
        };

        return strlen($text) > 300 ? substr($text, 0, 300) . '…' : $text;
    }
}

/**
 * A Store backed by a fresh temporary directory, deleted when the test ends.
 *
 * Every test gets its own so ordering never matters and a failing test cannot
 * leave state that makes the next one pass.
 */
function tmpStore(): Cms\Store
{
    $dir = sys_get_temp_dir() . '/cms-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    register_shutdown_function(static function () use ($dir): void {
        rmrf($dir);
    });

    return new Cms\Store($dir);
}

function tmpDir(): string
{
    $dir = sys_get_temp_dir() . '/cms-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);

    register_shutdown_function(static function () use ($dir): void {
        rmrf($dir);
    });

    return $dir;
}

function rmrf(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            rmrf($path . '/' . $entry);
        }
    }

    @rmdir($path);
}

/**
 * Give the next test a genuinely empty session.
 *
 * Every test runs in one PHP process, and session_start() resumes whatever the
 * current session id points at — so clearing $_SESSION alone is not enough:
 * the next start reloads the state an earlier test wrote, and a test asserting
 * "not logged in" sees a login from three tests ago. Destroy the session and
 * take a fresh id.
 */
function resetSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        @session_destroy();
    }

    $_SESSION = [];
    @session_id(bin2hex(random_bytes(16)));
}
