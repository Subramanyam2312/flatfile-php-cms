<?php

/**
 * @var Cms\App $app
 * @var array<string,mixed> $settings
 */

$metaTitle = 'Not found · ' . (string) ($settings['site_name'] ?? '');
$metaDesc  = '';

require $app->root . '/views/partials/site-head.php';
?>

<article class="prose">
    <h1>Not found</h1>
    <p>That page does not exist. <a href="/">Back to the front page</a>.</p>
</article>

<?php require $app->root . '/views/partials/site-foot.php'; ?>
