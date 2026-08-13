<?php

declare(strict_types=1);

/**
 * Front controller. The only PHP file the web server ever executes directly.
 *
 * Probes one directory up for src/, then two, so the same code runs from a
 * local `php -S` in the repo root and from a shared host where public/ has been
 * renamed public_html and everything else moved a level above it.
 */

$root = is_dir(dirname(__DIR__) . '/src') ? dirname(__DIR__) : dirname(__DIR__, 2);

if (!is_dir($root . '/src')) {
    http_response_code(500);
    exit('Application directory not found. See README.md, "Directory layout".');
}

require $root . '/src/App.php';

use Cms\App;
use Cms\Csrf;
use Cms\Html;

$app = App::boot($root);
$app->sendSecurityHeaders();

$path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/';
$path   = '/' . trim(rawurldecode($path), '/');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (str_starts_with($path, '/admin')) {
    require $root . '/src/admin.php';
    exit;
}

// ---------------------------------------------------------------------------
// Public site
//
// The session is started for the whole public side, not just the contact
// handler: the contact form's CSRF token has to exist when the page renders,
// and the post-submit flash has to survive the redirect.
// ---------------------------------------------------------------------------

$app->auth->startSession();

if ($path === '/robots.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\n";
    echo "Disallow: /admin\n";
    if ($app->setting('base_url') !== '') {
        echo 'Sitemap: ' . $app->url('/sitemap.xml') . "\n";
    }
    exit;
}

if ($path === '/sitemap.xml') {
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($app->content->published('page') as $page) {
        $loc = $page['slug'] === 'home' ? '/' : '/' . $page['slug'];
        echo '  <url><loc>' . Html::escape($app->url($loc)) . '</loc>'
            . '<lastmod>' . Html::escape(substr((string) $page['updated_at'], 0, 10)) . '</lastmod></url>' . "\n";
    }

    foreach ($app->content->published('post') as $post) {
        echo '  <url><loc>' . Html::escape($app->url('/blog/' . $post['slug'])) . '</loc>'
            . '<lastmod>' . Html::escape(substr((string) $post['updated_at'], 0, 10)) . '</lastmod></url>' . "\n";
    }

    echo '</urlset>';
    exit;
}

// Contact form. Stores the message; it does not email it — see README.
if ($path === '/contact' && $method === 'POST') {
    Csrf::require($_POST['csrf'] ?? null);

    $name    = trim((string) ($_POST['name'] ?? ''));
    $email   = trim((string) ($_POST['email'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    // Honeypot: a field hidden in CSS that humans never fill and bots always do.
    $trapped = trim((string) ($_POST['website'] ?? '')) !== '';

    $valid = $name !== ''
        && $message !== ''
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

    if ($valid && !$trapped) {
        $app->store->mutate('messages', static function (array $data) use ($name, $email, $message): array {
            $data['items'] ??= [];
            array_unshift($data['items'], [
                'id'         => bin2hex(random_bytes(8)),
                'name'       => mb_substr($name, 0, 120),
                'email'      => mb_substr($email, 0, 190),
                'message'    => mb_substr($message, 0, 5000),
                'created_at' => gmdate('c'),
            ]);
            $data['items'] = array_slice($data['items'], 0, 500);

            return $data;
        });
    }

    // Report success to a trapped bot too — telling it the honeypot fired just
    // teaches whoever wrote it to leave the field alone next time.
    $_SESSION['flash'] = $valid
        ? 'Thanks — your message has been received.'
        : 'Please check your name, email and message.';

    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
    exit;
}

$blogSlug = '/blog';

if ($path === $blogSlug) {
    echo $app->render('site/blog', [
        'posts'    => $app->content->published('post'),
        'settings' => $app->settings(),
    ]);
    exit;
}

if (str_starts_with($path, $blogSlug . '/')) {
    $post = $app->content->findPublished('post', substr($path, strlen($blogSlug) + 1));

    if ($post === null) {
        http_response_code(404);
        echo $app->render('site/404', ['settings' => $app->settings()]);
        exit;
    }

    echo $app->render('site/post', ['post' => $post, 'settings' => $app->settings()]);
    exit;
}

$slug = $path === '/' ? 'home' : ltrim($path, '/');
$page = $app->content->findPublished('page', $slug);

if ($page === null) {
    http_response_code(404);
    echo $app->render('site/404', ['settings' => $app->settings()]);
    exit;
}

echo $app->render('site/page', [
    'page'     => $page,
    'settings' => $app->settings(),
    'pages'    => $app->content->published('page'),
]);
