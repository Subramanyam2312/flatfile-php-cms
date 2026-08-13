<?php

declare(strict_types=1);

namespace Cms;

/**
 * Wiring and template rendering.
 *
 * No dependency-injection container and no autoloader package — this has to run
 * on hosting where Composer may not exist, so the class map is explicit and the
 * whole thing boots from one require.
 */
final class App
{
    public readonly Store $store;
    public readonly Auth $auth;
    public readonly Content $content;
    public readonly Media $media;

    public function __construct(public readonly string $root)
    {
        $this->store   = new Store($root . '/data');
        $this->auth    = new Auth($this->store);
        $this->content = new Content($this->store);
        $this->media   = new Media($root . '/public/uploads', $this->store);
    }

    public static function boot(string $root): self
    {
        spl_autoload_register(static function (string $class) use ($root): void {
            if (!str_starts_with($class, 'Cms\\')) {
                return;
            }

            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        });

        return new self($root);
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return $this->store->read('settings', [
            'site_name'    => 'A new site',
            'tagline'      => '',
            'base_url'     => '',
            'contact_email'=> '',
            'accent'       => '#2b6cb0',
        ]);
    }

    public function setting(string $key, string $default = ''): string
    {
        $value = $this->settings()[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Render a template with $data extracted into scope.
     *
     * Templates escape their own output with Html::escape(). There is no
     * auto-escaping layer, so a template that forgets is a live XSS hole —
     * that trade buys zero dependencies, and it is the main reason to read
     * views/ carefully before extending it.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $file = $this->root . '/views/' . $template . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException("Template not found: {$template}");
        }

        $data['app'] = $this;

        extract($data, EXTR_SKIP);
        ob_start();
        require $file;

        return (string) ob_get_clean();
    }

    /**
     * Response headers applied to every request.
     *
     * The CSP has no 'unsafe-inline' for scripts, which is what makes it worth
     * having — if you add an inline <script> to a template it will silently
     * stop running, and the fix is to move it to a file, not to weaken this.
     */
    public function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('X-Frame-Options: SAMEORIGIN');
        header(
            "Content-Security-Policy: default-src 'self'; "
            . "img-src 'self' data:; "
            . "style-src 'self' 'unsafe-inline'; "
            . "script-src 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'; "
            . "base-uri 'self'"
        );

        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')) {
            // Short max-age on purpose. Raise it once HTTPS is proven — see the
            // README. A long HSTS header set before TLS works locks the domain
            // out of HTTP with no way to undo it server-side.
            header('Strict-Transport-Security: max-age=300');
        }
    }

    /** Absolute URL for a path, using base_url when configured. */
    public function url(string $path = '/'): string
    {
        $base = rtrim($this->setting('base_url'), '/');

        return $base . '/' . ltrim($path, '/');
    }
}
