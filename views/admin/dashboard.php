<?php

/**
 * @var Cms\App $app
 * @var list<array<string,mixed>> $pages
 * @var list<array<string,mixed>> $posts
 * @var list<array<string,mixed>> $messages
 * @var list<array<string,mixed>> $media
 */

use Cms\Html;

$title = 'Dashboard';
require $app->root . '/views/partials/admin-head.php';

$drafts = static fn (array $items): int => count(array_filter(
    $items,
    static fn (array $i): bool => ($i['status'] ?? '') !== 'published'
));
?>

<h1>Dashboard</h1>

<div class="tiles">
    <a class="tile" href="/admin/pages">
        <strong><?= count($pages) ?></strong>
        <span>Pages</span>
        <?php if ($drafts($pages) > 0): ?><em><?= $drafts($pages) ?> draft</em><?php endif; ?>
    </a>
    <a class="tile" href="/admin/posts">
        <strong><?= count($posts) ?></strong>
        <span>Posts</span>
        <?php if ($drafts($posts) > 0): ?><em><?= $drafts($posts) ?> draft</em><?php endif; ?>
    </a>
    <a class="tile" href="/admin/media">
        <strong><?= count($media) ?></strong>
        <span>Images</span>
    </a>
    <a class="tile" href="/admin/messages">
        <strong><?= count($messages) ?></strong>
        <span>Messages</span>
    </a>
</div>

<?php if ($app->setting('base_url') === ''): ?>
    <p class="flash warn">
        No site URL set. <a href="/admin/settings">Add one</a> — the sitemap and
        <code>robots.txt</code> need it to emit absolute URLs.
    </p>
<?php endif; ?>

<?php if (count($pages) === 0): ?>
    <p class="muted">
        No pages yet. Create one with the slug <code>home</code> and it becomes
        the site's front page. <a href="/admin/pages/edit">Start there.</a>
    </p>
<?php endif; ?>

<h2>Recently updated</h2>
<?php
$recent = array_merge(
    array_map(static fn (array $p): array => $p + ['_type' => 'page'], $pages),
    array_map(static fn (array $p): array => $p + ['_type' => 'post'], $posts),
);
usort($recent, static fn (array $a, array $b): int =>
    strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
$recent = array_slice($recent, 0, 8);
?>

<?php if ($recent === []): ?>
    <p class="muted">Nothing yet.</p>
<?php else: ?>
    <table>
        <thead><tr><th>Title</th><th>Type</th><th>Status</th><th>Updated</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $item): ?>
            <tr>
                <td>
                    <a href="/admin/<?= Html::escape($item['_type']) ?>s/edit?slug=<?= urlencode((string) $item['slug']) ?>">
                        <?= Html::escape((string) $item['title']) ?>
                    </a>
                </td>
                <td><?= Html::escape($item['_type']) ?></td>
                <td>
                    <span class="pill <?= ($item['status'] ?? '') === 'published' ? 'live' : 'draft' ?>">
                        <?= Html::escape((string) ($item['status'] ?? 'draft')) ?>
                    </span>
                </td>
                <td class="muted"><?= Html::escape(substr((string) ($item['updated_at'] ?? ''), 0, 10)) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
