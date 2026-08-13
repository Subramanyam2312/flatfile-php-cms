<?php

/**
 * @var Cms\App $app
 * @var string|null $title
 * @var list<array{message:string,type:string}>|null $flashes
 */

use Cms\Csrf;
use Cms\Html;

$nav = [
    '/admin'          => 'Dashboard',
    '/admin/pages'    => 'Pages',
    '/admin/posts'    => 'Posts',
    '/admin/media'    => 'Media',
    '/admin/messages' => 'Messages',
    '/admin/settings' => 'Settings',
];

$current = strtok($_SERVER['REQUEST_URI'] ?? '/admin', '?') ?: '/admin';
$current = '/' . trim($current, '/');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= Html::escape($title ?? 'Admin') ?> · <?= Html::escape($app->setting('site_name', 'CMS')) ?></title>
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body>
<header class="bar">
    <a class="brand" href="/admin"><?= Html::escape($app->setting('site_name', 'CMS')) ?></a>
    <nav>
        <?php foreach ($nav as $href => $label): ?>
            <a href="<?= Html::escape($href) ?>"<?= $current === $href ? ' class="on"' : '' ?>><?= Html::escape($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="bar-end">
        <a href="/" target="_blank" rel="noopener">View site</a>
        <form method="post" action="/admin/logout">
            <?= Csrf::field() ?>
            <button type="submit" class="link">Sign out</button>
        </form>
    </div>
</header>

<main>
<?php foreach (($flashes ?? []) as $f): ?>
    <p class="flash <?= Html::escape($f['type'] ?? 'ok') ?>"><?= Html::escape($f['message'] ?? '') ?></p>
<?php endforeach; ?>
