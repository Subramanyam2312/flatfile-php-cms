<?php

/**
 * @var Cms\App $app
 * @var array<string,mixed> $post
 * @var array<string,mixed> $settings
 */

use Cms\Html;

$metaTitle = ($post['meta_title'] ?? '') !== ''
    ? (string) $post['meta_title']
    : (string) $post['title'] . ' · ' . (string) ($settings['site_name'] ?? '');

$metaDesc = ($post['meta_desc'] ?? '') !== ''
    ? (string) $post['meta_desc']
    : Html::excerpt((string) $post['body']);

require $app->root . '/views/partials/site-head.php';
?>

<article class="prose">
    <h1><?= Html::escape((string) $post['title']) ?></h1>

    <time datetime="<?= Html::escape((string) ($post['created_at'] ?? '')) ?>">
        <?= Html::escape(substr((string) ($post['created_at'] ?? ''), 0, 10)) ?>
    </time>

    <?php if (($post['image'] ?? '') !== ''): ?>
        <img class="lead-image" src="/uploads/<?= Html::escape((string) $post['image']) ?>" alt="">
    <?php endif; ?>

    <?php
    // Already sanitised against an allow-list on save — see Html::sanitise().
    echo $post['body'];
    ?>
</article>

<p><a href="/blog">← All posts</a></p>

<?php require $app->root . '/views/partials/site-foot.php'; ?>
