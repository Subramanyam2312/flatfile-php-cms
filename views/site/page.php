<?php

/**
 * @var Cms\App $app
 * @var array<string,mixed> $page
 * @var array<string,mixed> $settings
 */

use Cms\Csrf;
use Cms\Html;

$metaTitle = ($page['meta_title'] ?? '') !== ''
    ? (string) $page['meta_title']
    : (string) $page['title'] . ' · ' . (string) ($settings['site_name'] ?? '');

$metaDesc = ($page['meta_desc'] ?? '') !== ''
    ? (string) $page['meta_desc']
    : Html::excerpt((string) $page['body']);

require $app->root . '/views/partials/site-head.php';
?>

<article class="prose">
    <h1><?= Html::escape((string) $page['title']) ?></h1>

    <?php if (($page['image'] ?? '') !== ''): ?>
        <img class="lead-image" src="/uploads/<?= Html::escape((string) $page['image']) ?>" alt="">
    <?php endif; ?>

    <?php
    // Already sanitised against an allow-list on save — see Html::sanitise().
    echo $page['body'];
    ?>
</article>

<?php if (($page['slug'] ?? '') === 'contact'): ?>
    <form method="post" action="/contact" class="contact">
        <h2>Get in touch</h2>
        <?= Csrf::field() ?>

        <label>Name <input name="name" required autocomplete="name"></label>
        <label>Email <input name="email" type="email" required autocomplete="email"></label>
        <label>Message <textarea name="message" rows="6" required></textarea></label>

        <?php /* Honeypot. Hidden in CSS, never filled by a human. */ ?>
        <div class="trap" aria-hidden="true">
            <label>Website <input name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <button type="submit">Send</button>
    </form>
<?php endif; ?>

<?php require $app->root . '/views/partials/site-foot.php'; ?>
