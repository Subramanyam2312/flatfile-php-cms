<?php

declare(strict_types=1);

/**
 * End-to-end tests against a real server.
 *
 * Boots `php -S` on a throwaway document root with its own data directory, then
 * drives it with real HTTP requests. This is where the properties that only
 * exist across a whole request are checked: that CSRF rejection actually fires,
 * that an unauthenticated request cannot reach the admin, that a draft 404s,
 * and that an uploaded file lands with a safe name.
 *
 * Skipped automatically if the port cannot be bound, so a sandbox without
 * networking reports a skip rather than a spurious failure.
 */

final class Server
{
    public string $base;
    private $process;
    private string $root;
    private string $cookies;

    public function __construct()
    {
        $this->root = tmpDir();
        $repo       = dirname(__DIR__);

        // A throwaway copy so tests never touch the repo's own data/.
        foreach (['src', 'views', 'public'] as $dir) {
            self::copyTree("{$repo}/{$dir}", "{$this->root}/{$dir}");
        }
        mkdir($this->root . '/data', 0700, true);

        $port          = random_int(8600, 8999);
        $this->base    = "http://127.0.0.1:{$port}";
        $this->cookies = $this->root . '/cookies.txt';

        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s %s > /dev/null 2>&1 & echo $!',
            $port,
            escapeshellarg($this->root . '/public'),
            escapeshellarg($this->root . '/public/index.php')
        );

        $this->process = (int) trim((string) shell_exec($cmd));

        for ($i = 0; $i < 40; $i++) {
            usleep(100_000);
            if (@fsockopen('127.0.0.1', $port, $e, $s, 0.2)) {
                return;
            }
        }

        throw new RuntimeException('server did not start');
    }

    /** @return array{status:int,body:string,location:string} */
    public function request(string $path, ?array $post = null, array $files = []): array
    {
        $cmd = 'curl -s -o /dev/stdout -w "\n%{http_code}\n%{redirect_url}" '
             . '-b ' . escapeshellarg($this->cookies) . ' -c ' . escapeshellarg($this->cookies) . ' ';

        if ($post !== null) {
            foreach ($post as $k => $v) {
                $cmd .= $files === []
                    ? '--data-urlencode ' . escapeshellarg("{$k}={$v}") . ' '
                    : '-F ' . escapeshellarg("{$k}={$v}") . ' ';
            }
            foreach ($files as $k => $v) {
                $cmd .= '-F ' . escapeshellarg("{$k}=@{$v}") . ' ';
            }
        }

        $cmd .= escapeshellarg($this->base . $path);
        $raw   = (string) shell_exec($cmd);
        $parts = explode("\n", $raw);

        $location = array_pop($parts);
        $status   = (int) array_pop($parts);

        return ['status' => $status, 'body' => implode("\n", $parts), 'location' => $location];
    }

    public function csrf(string $path): string
    {
        preg_match('/name="csrf" value="([^"]+)"/', $this->request($path)['body'], $m);

        return $m[1] ?? '';
    }

    public function stop(): void
    {
        if ($this->process > 0) {
            @shell_exec('kill ' . $this->process . ' 2>/dev/null');
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0755, true);

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $src = "{$from}/{$entry}";
            $dst = "{$to}/{$entry}";

            is_dir($src) ? self::copyTree($src, $dst) : copy($src, $dst);
        }
    }
}

if (!@fsockopen('127.0.0.1', 0, $e, $s, 0.1) && !function_exists('shell_exec')) {
    return ['HTTP tests skipped (no shell_exec)' => static fn () => Assert::true(true, 'skipped')];
}

try {
    $server = new Server();
} catch (Throwable $e) {
    return ['HTTP tests skipped (server would not start)' => static fn () => Assert::true(true, 'skipped')];
}

register_shutdown_function(static fn () => $server->stop());

$password = 'correct-horse-battery';

return [

    'a fresh install redirects to setup' => function () use ($server): void {
        $r = $server->request('/admin');
        Assert::same(302, $r['status'], 'an unconfigured admin redirects');
        Assert::contains('/admin/setup', $r['location'], 'and it goes to setup');
    },

    'setup rejects a weak password' => function () use ($server): void {
        $r = $server->request('/admin/setup', [
            'csrf'     => $server->csrf('/admin/setup'),
            'username' => 'editor',
            'password' => 'short',
        ]);

        Assert::contains('at least 12 characters', $r['body'], 'the policy is enforced over HTTP too');
    },

    'setup creates the account, then closes' => function () use ($server, $password): void {
        $r = $server->request('/admin/setup', [
            'csrf'     => $server->csrf('/admin/setup'),
            'username' => 'editor',
            'password' => $password,
        ]);
        Assert::same(302, $r['status'], 'a valid setup redirects to login');

        // Second visit must not offer setup again.
        $again = $server->request('/admin/setup');
        Assert::same(302, $again['status'], 'setup is closed once an account exists');
        Assert::contains('/admin', $again['location'], 'and redirects away');
    },

    'login rejects a wrong password' => function () use ($server): void {
        $r = $server->request('/admin/login', [
            'csrf'     => $server->csrf('/admin/login'),
            'username' => 'editor',
            'password' => 'definitely-wrong-x',
        ]);

        Assert::contains('Incorrect username or password', $r['body'], 'a bad password is refused');
    },

    'login succeeds and reaches the dashboard' => function () use ($server, $password): void {
        $r = $server->request('/admin/login', [
            'csrf'     => $server->csrf('/admin/login'),
            'username' => 'editor',
            'password' => $password,
        ]);
        Assert::same(302, $r['status'], 'a correct login redirects');

        $dash = $server->request('/admin');
        Assert::same(200, $dash['status'], 'the dashboard is now reachable');
        Assert::contains('Dashboard', $dash['body'], 'and renders');
    },

    'a forged CSRF token is rejected' => function () use ($server): void {
        $r = $server->request('/admin/pages/edit', [
            'csrf'   => 'forged-token-value',
            'title'  => 'Injected',
            'status' => 'published',
        ]);

        Assert::same(419, $r['status'], 'a bad token must be refused with 419');
    },

    'a published page renders on the public site' => function () use ($server): void {
        $server->request('/admin/pages/edit', [
            'csrf'   => $server->csrf('/admin/pages/edit'),
            'title'  => 'Welcome',
            'slug'   => 'home',
            'body'   => '<p>Hello <strong>world</strong></p><script>alert(1)</script>',
            'status' => 'published',
        ]);

        $home = $server->request('/');
        Assert::same(200, $home['status'], 'the front page loads');
        Assert::contains('<h1>Welcome</h1>', $home['body'], 'the title renders');
        Assert::contains('<strong>world</strong>', $home['body'], 'allowed markup survives');
        Assert::missing('<script', $home['body'], 'the script tag does not');
        Assert::missing('alert(1)', $home['body'], 'nor its contents');
    },

    'a draft post is not reachable publicly' => function () use ($server): void {
        $server->request('/admin/posts/edit', [
            'csrf'   => $server->csrf('/admin/posts/edit'),
            'title'  => 'Unfinished',
            'slug'   => 'unfinished',
            'body'   => '<p>secret</p>',
            'status' => 'draft',
        ]);

        $r = $server->request('/blog/unfinished');
        Assert::same(404, $r['status'], 'a draft must 404 on the public site');
        Assert::missing('secret', $r['body'], 'and its body must not leak into the 404 page');
    },

    'the data store is not reachable over HTTP' => function () use ($server): void {
        // The throwaway root puts data/ beside public/, matching the deployed
        // layout, so this asserts the layout itself is right.
        foreach (['/../data/admin.php', '/data/admin.php', '/../data/settings.php'] as $path) {
            $r = $server->request($path);
            Assert::missing('$2y$', $r['body'], "no password hash may be served from {$path}");
            Assert::missing('"hash"', $r['body'], "no store contents may be served from {$path}");
        }
    },

    'an uploaded image is renamed and indexed' => function () use ($server): void {
        // A real 1x1 PNG, so the content sniff and getimagesize both pass.
        $png = tmpDir() . '/pixel.png';
        file_put_contents($png, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));

        $server->request('/admin/media', ['csrf' => $server->csrf('/admin/media')], ['image' => $png]);

        $r = $server->request('/admin/media');
        Assert::contains('.png', $r['body'], 'the upload should appear in the library');
        Assert::missing('pixel.png', $r['body'], 'the original filename must not be kept');
        Assert::true(
            (bool) preg_match('/[a-f0-9]{32}\.png/', $r['body']),
            'the stored name should be random hex'
        );
    },

    'a PHP file disguised as an image is refused' => function () use ($server): void {
        $bad = tmpDir() . '/shell.png';
        file_put_contents($bad, "<?php echo 'pwned'; ?>");

        $server->request('/admin/media', ['csrf' => $server->csrf('/admin/media')], ['image' => $bad]);

        $r = $server->request('/admin/media');
        Assert::contains('Only JPEG, PNG, GIF and WebP', $r['body'], 'content sniffing must reject it');
        Assert::missing('shell', $r['body'], 'and it must not be stored');
    },

    'the sitemap lists published content only' => function () use ($server): void {
        $r = $server->request('/sitemap.xml');
        Assert::same(200, $r['status'], 'the sitemap is served');
        Assert::missing('unfinished', $r['body'], 'a draft must not be advertised to search engines');
    },

    'robots.txt disallows the admin' => function () use ($server): void {
        $r = $server->request('/robots.txt');
        Assert::contains('Disallow: /admin', $r['body'], 'the admin should not be crawled');
    },

    'signing out ends access to the admin' => function () use ($server): void {
        $server->request('/admin/logout', ['csrf' => $server->csrf('/admin')]);

        $r = $server->request('/admin/settings');
        Assert::same(302, $r['status'], 'after logout the admin redirects');
        Assert::contains('/admin/login', $r['location'], 'back to the login page');
    },

    'the admin is unreachable without a session' => function () use ($server): void {
        foreach (['/admin', '/admin/settings', '/admin/pages', '/admin/media', '/admin/messages'] as $path) {
            $r = $server->request($path);
            Assert::same(302, $r['status'], "{$path} must not serve to an anonymous request");
            Assert::contains('/admin/login', $r['location'], "{$path} must redirect to login");
        }
    },
];
