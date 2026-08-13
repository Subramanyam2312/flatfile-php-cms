<?php

declare(strict_types=1);

/**
 * Admin routes. Included by public/index.php for any path under /admin.
 *
 * Expects $app, $path and $method from the front controller.
 *
 * @var Cms\App $app
 * @var string  $path
 * @var string  $method
 */

use Cms\Csrf;
use Cms\Html;

// The admin is never cached and never indexed, regardless of what a proxy in
// front of it decides to do.
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$app->auth->startSession();

$route  = substr($path, strlen('/admin'));
$route  = $route === '' ? '/' : $route;
$post   = static fn (string $k, string $d = ''): string => trim((string) ($_POST[$k] ?? $d));
$flash  = static function (string $message, string $type = 'ok'): void {
    $_SESSION['admin_flash'][] = ['message' => $message, 'type' => $type];
};
$render = static function (string $template, array $data = []) use ($app): void {
    $data['flashes'] = $_SESSION['admin_flash'] ?? [];
    unset($_SESSION['admin_flash']);
    echo $app->render($template, $data);
};

// ---------------------------------------------------------------------------
// First run: create the single account. Closed as soon as one exists.
// ---------------------------------------------------------------------------

if (!$app->auth->isConfigured()) {
    if ($route !== '/setup') {
        header('Location: /admin/setup');
        exit;
    }

    $error = null;

    if ($method === 'POST') {
        Csrf::require($_POST['csrf'] ?? null);
        $result = $app->auth->install($post('username'), $post('password'));

        if ($result === true) {
            $flash('Account created. Sign in to continue.');
            header('Location: /admin/login');
            exit;
        }

        $error = $result;
    }

    $render('admin/setup', ['error' => $error]);
    exit;
}

if ($route === '/setup') {
    header('Location: /admin');
    exit;
}

// ---------------------------------------------------------------------------
// Login / logout
// ---------------------------------------------------------------------------

if ($route === '/login') {
    if ($app->auth->isLoggedIn()) {
        header('Location: /admin');
        exit;
    }

    $error = null;

    if ($method === 'POST') {
        Csrf::require($_POST['csrf'] ?? null);
        $result = $app->auth->login($post('username'), (string) ($_POST['password'] ?? ''));

        if ($result === true) {
            header('Location: /admin');
            exit;
        }

        $error = $result;
    }

    $render('admin/login', ['error' => $error]);
    exit;
}

if ($route === '/logout') {
    // POST only: a GET logout can be triggered by any <img> on any page.
    if ($method === 'POST') {
        Csrf::require($_POST['csrf'] ?? null);
        $app->auth->logout();
    }

    header('Location: /admin/login');
    exit;
}

// ---------------------------------------------------------------------------
// Everything past here requires a session.
// ---------------------------------------------------------------------------

if (!$app->auth->isLoggedIn()) {
    header('Location: /admin/login');
    exit;
}

switch ($route) {
    case '/':
        $render('admin/dashboard', [
            'pages'    => $app->content->all('page'),
            'posts'    => $app->content->all('post'),
            'messages' => $app->store->read('messages')['items'] ?? [],
            'media'    => $app->media->all(),
        ]);
        break;

    case '/pages':
    case '/posts':
        $type = $route === '/pages' ? 'page' : 'post';

        if ($method === 'POST') {
            Csrf::require($_POST['csrf'] ?? null);

            if ($post('action') === 'delete') {
                $app->content->delete($type, $post('slug'));
                $flash('Deleted.');
            }

            header("Location: /admin{$route}");
            exit;
        }

        $render('admin/list', ['type' => $type, 'items' => $app->content->all($type)]);
        break;

    case '/pages/edit':
    case '/posts/edit':
        $type     = str_starts_with($route, '/pages') ? 'page' : 'post';
        $slug     = trim((string) ($_GET['slug'] ?? ''));
        $existing = $slug !== '' ? $app->content->find($type, $slug) : null;
        $error    = null;

        if ($method === 'POST') {
            Csrf::require($_POST['csrf'] ?? null);

            $result = $app->content->save($type, [
                'title'      => $post('title'),
                'slug'       => $post('slug'),
                'body'       => (string) ($_POST['body'] ?? ''),
                'status'     => $post('status'),
                'meta_title' => $post('meta_title'),
                'meta_desc'  => $post('meta_desc'),
                'image'      => $post('image'),
            ], $existing !== null ? (string) $existing['slug'] : null);

            if ($result['ok']) {
                $flash('Saved.');
                header("Location: /admin{$type}s/edit?slug=" . urlencode($result['slug']));
                exit;
            }

            $error = $result['error'];
        }

        $render('admin/edit', [
            'type'   => $type,
            'item'   => $existing,
            'error'  => $error,
            'media'  => $app->media->all(),
        ]);
        break;

    case '/media':
        if ($method === 'POST') {
            Csrf::require($_POST['csrf'] ?? null);

            if ($post('action') === 'delete') {
                $app->media->delete($post('file'));
                $flash('Image deleted.');
            } elseif (isset($_FILES['image'])) {
                $result = $app->media->accept($_FILES['image']);
                $result['ok']
                    ? $flash('Uploaded.')
                    : $flash($result['error'], 'error');
            }

            header('Location: /admin/media');
            exit;
        }

        $render('admin/media', ['files' => $app->media->all()]);
        break;

    case '/messages':
        if ($method === 'POST') {
            Csrf::require($_POST['csrf'] ?? null);
            $id = $post('id');

            $app->store->mutate('messages', static function (array $data) use ($id): array {
                $data['items'] = array_values(array_filter(
                    $data['items'] ?? [],
                    static fn (array $m): bool => ($m['id'] ?? '') !== $id
                ));

                return $data;
            });

            $flash('Message deleted.');
            header('Location: /admin/messages');
            exit;
        }

        $render('admin/messages', ['messages' => $app->store->read('messages')['items'] ?? []]);
        break;

    case '/settings':
        if ($method === 'POST') {
            Csrf::require($_POST['csrf'] ?? null);

            $app->store->write('settings', [
                'site_name'     => $post('site_name', 'A new site'),
                'tagline'       => $post('tagline'),
                'base_url'      => rtrim($post('base_url'), '/'),
                'contact_email' => $post('contact_email'),
                'accent'        => preg_match('/^#[0-9a-f]{6}$/i', $post('accent'))
                    ? $post('accent')
                    : '#2b6cb0',
            ]);

            $flash('Settings saved.');
            header('Location: /admin/settings');
            exit;
        }

        $render('admin/settings', ['settings' => $app->settings()]);
        break;

    case '/password':
        $error = null;

        if ($method === 'POST') {
            Csrf::require($_POST['csrf'] ?? null);

            $result = $app->auth->changePassword(
                (string) ($_POST['current'] ?? ''),
                (string) ($_POST['new'] ?? '')
            );

            if ($result === true) {
                $flash('Password changed.');
                header('Location: /admin/password');
                exit;
            }

            $error = $result;
        }

        $render('admin/password', ['error' => $error]);
        break;

    default:
        http_response_code(404);
        $render('admin/404');
}
