<?php

/**
 * @var Cms\App $app
 * @var list<array<string,mixed>> $posts
 * @var array<string,mixed> $settings
 */

use Cms\Html;

$metaTitle = 'Blog · ' . (string) ($settings['site_name'] ?? '');
$metaDesc  = 'Latest posts from ' . (string) ($settings['site_name'] ?? '');

require $app->root . '/views/partials/site-head.php';
?>

<h1>Blog</h1>

<?php if ($posts === []): ?>
    <p class="muted">No posts yet.</p>
<?php else: ?>
    <ul class="post-list">
        <?php foreach ($posts as $post): ?>
            <li>
                <article>
                    <h2>
                        <a href="/blog/<?= Html::escape((string) $post['slug']) ?>">
                            <?= Html::escape((string) $post['title']) ?>
                        </a>
                    </h2>
                    <time datetime="<?= Html::escape((string) ($post['created_at'] ?? '')) ?>">
                        <?= Html::escape(substr((string) ($post['created_at'] ?? ''), 0, 10)) ?>
                    </time>
                    <p><?= Html::escape(
                        ($post['meta_desc'] ?? '') !== ''
                            ? (string) $post['meta_desc']
                            : Html::excerpt((string) $post['body'])
                    ) ?></p>
                </article>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php require $app->root . '/views/partials/site-foot.php'; ?>
