<?php

/**
 * @var Cms\App $app
 * @var array<string,mixed> $settings
 * @var string|null $metaTitle
 * @var string|null $metaDesc
 */

use Cms\Html;

$siteName = (string) ($settings['site_name'] ?? 'Site');
$accent   = (string) ($settings['accent'] ?? '#2b6cb0');
$accent   = preg_match('/^#[0-9a-f]{6}$/i', $accent) ? $accent : '#2b6cb0';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= Html::escape($metaTitle ?? $siteName) ?></title>
<?php if (($metaDesc ?? '') !== ''): ?>
<meta name="description" content="<?= Html::escape($metaDesc) ?>">
<?php endif; ?>
<link rel="stylesheet" href="/assets/site.css">
<style>:root{--accent:<?= Html::escape($accent) ?>}</style>
</head>
<body>
<header class="site-head">
    <a class="brand" href="/"><?= Html::escape($siteName) ?></a>
    <?php if (($settings['tagline'] ?? '') !== ''): ?>
        <p class="tagline"><?= Html::escape((string) $settings['tagline']) ?></p>
    <?php endif; ?>
    <nav>
        <a href="/">Home</a>
        <a href="/blog">Blog</a>
    </nav>
</header>

<main>
<?php if (!empty($_SESSION['flash'])): ?>
    <p class="flash"><?= Html::escape((string) $_SESSION['flash']) ?></p>
    <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
