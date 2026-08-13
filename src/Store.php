<?php

declare(strict_types=1);

namespace Cms;

use RuntimeException;

/**
 * The data store: JSON documents held in files that cannot be read over HTTP.
 *
 * Every store file is a .php file whose first line is exactly "<?php exit; ?>".
 * Requested directly, PHP executes the file, hits exit, and returns an empty
 * body. The reader strips that first line and decodes the JSON beneath it.
 *
 * This matters because the usual protection — an .htaccess denying access to
 * data/ — only works if the server reads .htaccess. It does not on nginx, it
 * does not when AllowOverride is off, and it does not when the file is lost in
 * an upload. In each of those cases a plain data/admin.json would serve the
 * admin password hash to anyone who asked for it. The guard line does not
 * depend on server configuration, so it holds when the .htaccess does not.
 *
 * Keep both layers. The .htaccess is still the first line of defence; this is
 * what remains when it fails.
 */
final class Store
{
    private const GUARD = "<?php exit; ?>\n";

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(private string $dir)
    {
        if (!is_dir($this->dir)) {
            throw new RuntimeException("Data directory does not exist: {$this->dir}");
        }
    }

    /**
     * Read a document. Returns $default if it does not exist yet.
     *
     * @return array<string, mixed>
     */
    public function read(string $name, array $default = []): array
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $path = $this->path($name);
        if (!is_file($path)) {
            return $default;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Could not read store: {$name}");
        }

        $decoded = json_decode($this->unwrap($raw), true);
        if (!is_array($decoded)) {
            // A corrupt store is not the same as an empty one. Failing loudly
            // here is better than silently serving a blank site and letting the
            // next write overwrite whatever was salvageable.
            throw new RuntimeException("Store is not valid JSON: {$name}");
        }

        return $this->cache[$name] = $decoded;
    }

    /**
     * Write a document atomically.
     *
     * Writes to a temporary file in the same directory, then renames over the
     * target. rename() is atomic within a filesystem, so a reader never sees a
     * half-written document, and a crash mid-write leaves the previous version
     * intact rather than a truncated file.
     *
     * @param array<string, mixed> $data
     */
    public function write(string $name, array $data): void
    {
        $path = $this->path($name);
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException("Could not encode store: {$name} — " . json_last_error_msg());
        }

        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($tmp, self::GUARD . $json, LOCK_EX) === false) {
            throw new RuntimeException("Could not write store: {$name}");
        }

        // The store is inside the application directory, not the web root, but
        // set restrictive permissions anyway — a misconfigured deploy that puts
        // it somewhere reachable should not also make it world-readable.
        @chmod($tmp, 0640);

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not commit store: {$name}");
        }

        $this->cache[$name] = $data;
    }

    /**
     * Read, modify and write under an exclusive lock.
     *
     * Use this for anything read-modify-write — appending a lead, incrementing
     * a counter. Without the lock, two concurrent requests both read the old
     * document and the second write silently discards the first.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $mutator
     * @return array<string, mixed> The written document.
     */
    public function mutate(string $name, callable $mutator, array $default = []): array
    {
        $lock = fopen($this->lockPath($name), 'c');
        if ($lock === false) {
            throw new RuntimeException("Could not open lock for store: {$name}");
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException("Could not lock store: {$name}");
            }

            unset($this->cache[$name]);          // force a read through the lock
            $updated = $mutator($this->read($name, $default));
            $this->write($name, $updated);

            return $updated;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    /**
     * Write a timestamped copy of a document to data/revisions/.
     *
     * Flat-file content has no transaction log, so a bad edit is otherwise
     * unrecoverable. Call this before overwriting anything a human authored.
     */
    public function revise(string $name): void
    {
        if (!$this->exists($name)) {
            return;
        }

        $dir = $this->dir . '/revisions';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;                               // revisions are best-effort
        }

        $stamp = date('Ymd-His');
        @copy($this->path($name), "{$dir}/{$name}-{$stamp}.php");
    }

    /**
     * Delete revisions older than $keep, newest first.
     */
    public function pruneRevisions(string $name, int $keep = 20): void
    {
        $found = glob($this->dir . "/revisions/{$name}-*.php") ?: [];
        if (count($found) <= $keep) {
            return;
        }

        rsort($found);                            // filenames sort chronologically
        foreach (array_slice($found, $keep) as $old) {
            @unlink($old);
        }
    }

    /**
     * Reject anything that is not a bare document name.
     *
     * The name reaches this class from route parameters in some flows, so it is
     * treated as untrusted: no slashes, no dots, no traversal, ever.
     */
    private function path(string $name): string
    {
        if ($name === '' || !preg_match('/^[a-z0-9_-]+$/i', $name)) {
            throw new RuntimeException("Illegal store name: {$name}");
        }

        return "{$this->dir}/{$name}.php";
    }

    private function lockPath(string $name): string
    {
        return $this->path($name) . '.lock';
    }

    /**
     * Strip the guard line. Tolerates a store written without one.
     */
    private function unwrap(string $raw): string
    {
        if (!str_starts_with($raw, '<?php')) {
            return $raw;
        }

        $newline = strpos($raw, "\n");

        return $newline === false ? '' : substr($raw, $newline + 1);
    }
}
