<?php

declare(strict_types=1);

use Cms\Store;

/**
 * The store is the security claim this whole project rests on, so the guard
 * line gets tested directly — including by executing a store file through PHP
 * and asserting it produces nothing, which is exactly what a web server would
 * do if the .htaccess were missing.
 */

return [

    // --- the guard line ---------------------------------------------------

    'every written file starts with the exit guard' => function (): void {
        $store = tmpStore();
        $store->write('settings', ['site_name' => 'Test']);

        $raw = file_get_contents((new ReflectionProperty(Store::class, 'dir'))
            ->getValue($store) . '/settings.php');

        Assert::true(
            str_starts_with((string) $raw, '<?php exit; ?>'),
            'the first line must be the guard, or the file is readable over HTTP'
        );
    },

    'executing a store file produces no output' => function (): void {
        // This is the real test. If a host ignores the .htaccess, the web server
        // runs the file through PHP — and it must reveal nothing.
        $store = tmpStore();
        $store->write('admin', ['username' => 'editor', 'hash' => '$2y$12$SECRETHASHVALUE']);

        $dir  = (new ReflectionProperty(Store::class, 'dir'))->getValue($store);
        $out  = shell_exec('php ' . escapeshellarg($dir . '/admin.php') . ' 2>&1');

        Assert::same('', trim((string) $out), 'executing the store must output nothing');
        Assert::missing('SECRETHASHVALUE', (string) $out, 'the hash must never reach output');
    },

    // --- round trip -------------------------------------------------------

    'read returns what write stored' => function (): void {
        $store = tmpStore();
        $data  = ['site_name' => 'Test', 'nested' => ['a' => 1, 'b' => [2, 3]], 'unicode' => 'café 日本'];

        $store->write('settings', $data);
        Assert::same($data, $store->read('settings'), 'a round trip must preserve structure exactly');
    },

    'read returns the default when the document is absent' => function (): void {
        $store = tmpStore();
        Assert::same(['fallback' => true], $store->read('nope', ['fallback' => true]), 'missing means default');
        Assert::same([], $store->read('nope'), 'and an empty array when no default is given');
    },

    'read survives a process restart' => function (): void {
        // Guards against a cache that masks a broken writer: the in-memory copy
        // would make a test pass even if nothing reached disk.
        $store = tmpStore();
        $store->write('posts', ['items' => [['slug' => 'x']]]);

        $dir     = (new ReflectionProperty(Store::class, 'dir'))->getValue($store);
        $reopened = new Store($dir);

        Assert::same(
            [['slug' => 'x']],
            $reopened->read('posts')['items'],
            'data must be on disk, not only in the cache'
        );
    },

    'a corrupt document throws rather than reading as empty' => function (): void {
        // Silently treating corruption as "empty" means the next write destroys
        // whatever could have been salvaged.
        $store = tmpStore();
        $dir   = (new ReflectionProperty(Store::class, 'dir'))->getValue($store);
        file_put_contents($dir . '/broken.php', "<?php exit; ?>\n{not json");

        Assert::throws(
            static fn () => $store->read('broken'),
            'not valid JSON',
            'a corrupt store must fail loudly'
        );
    },

    // --- path traversal ---------------------------------------------------

    'illegal store names are rejected' => function (): void {
        $store = tmpStore();

        foreach (['../admin', 'a/b', './x', '', 'a.b', 'foo/../../etc/passwd'] as $bad) {
            Assert::throws(
                static fn () => $store->read($bad),
                'Illegal store name',
                "'{$bad}' must not be accepted as a document name"
            );
        }
    },

    // --- atomicity and locking -------------------------------------------

    'write leaves no temporary files behind' => function (): void {
        $store = tmpStore();
        $store->write('settings', ['a' => 1]);
        $store->write('settings', ['a' => 2]);

        $dir   = (new ReflectionProperty(Store::class, 'dir'))->getValue($store);
        $temps = glob($dir . '/*.tmp') ?: [];

        Assert::same([], $temps, 'the temporary file must be renamed away, not left');
    },

    'mutate applies a change and persists it' => function (): void {
        $store = tmpStore();
        $store->write('messages', ['items' => [['id' => 'a']]]);

        $store->mutate('messages', static function (array $d): array {
            array_unshift($d['items'], ['id' => 'b']);

            return $d;
        });

        Assert::same(['b', 'a'], array_column($store->read('messages')['items'], 'id'), 'mutation must persist in order');
    },

    'mutate reads through the lock rather than the cache' => function (): void {
        // If mutate() trusted the in-memory cache, a change written by another
        // process between the read and the mutate would be silently discarded.
        $store = tmpStore();
        $store->write('counter', ['n' => 1]);
        $store->read('counter');                      // warm the cache

        $dir = (new ReflectionProperty(Store::class, 'dir'))->getValue($store);
        (new Store($dir))->write('counter', ['n' => 99]);   // another "process"

        $result = $store->mutate('counter', static fn (array $d): array => ['n' => $d['n'] + 1]);

        Assert::same(100, $result['n'], 'mutate must see the newer value, not the cached one');
    },

    // --- revisions --------------------------------------------------------

    'revise copies the current document' => function (): void {
        $store = tmpStore();
        $store->write('pages', ['items' => [['slug' => 'v1']]]);
        $store->revise('pages');

        $dir   = (new ReflectionProperty(Store::class, 'dir'))->getValue($store);
        $found = glob($dir . '/revisions/pages-*.php') ?: [];

        Assert::same(1, count($found), 'a revision file must be created');
        Assert::contains('v1', (string) file_get_contents($found[0]), 'the revision holds the previous content');
    },

    'revise on a missing document is a no-op' => function (): void {
        $store = tmpStore();
        $store->revise('never-written');              // must not throw
        Assert::true(true, 'revising a document that does not exist is harmless');
    },

    'pruneRevisions keeps only the newest' => function (): void {
        $store = tmpStore();
        $dir   = (new ReflectionProperty(Store::class, 'dir'))->getValue($store);
        mkdir($dir . '/revisions', 0700, true);

        foreach (range(1, 8) as $i) {
            file_put_contents(sprintf('%s/revisions/pages-2026010%d-000000.php', $dir, $i), "<?php exit; ?>\n{}");
        }

        $store->pruneRevisions('pages', 3);
        $left = glob($dir . '/revisions/pages-*.php') ?: [];

        Assert::same(3, count($left), 'only the requested number of revisions should remain');
        Assert::contains('20260108', implode(' ', $left), 'and they must be the newest ones');
    },

    'exists reports presence accurately' => function (): void {
        $store = tmpStore();
        Assert::false($store->exists('settings'), 'nothing written yet');
        $store->write('settings', ['a' => 1]);
        Assert::true($store->exists('settings'), 'written documents exist');
    },
];
