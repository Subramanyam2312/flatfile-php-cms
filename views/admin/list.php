<?php

/**
 * @var Cms\App $app
 * @var string $type
 * @var list<array<string,mixed>> $items
 */

use Cms\Csrf;
use Cms\Html;

$label = $type === 'page' ? 'Pages' : 'Posts';
$title = $label;
require $app->root . '/views/partials/admin-head.php';
?>

<div class="head-row">
    <h1><?= Html::escape($label) ?></h1>
    <a class="button" href="/admin/<?= Html::escape($type) ?>s/edit">New <?= Html::escape($type) ?></a>
</div>

<?php if ($items === []): ?>
    <p class="muted">
        Nothing here yet.
        <?php if ($type === 'page'): ?>
            A page with the slug <code>home</code> becomes the front page.
        <?php endif; ?>
    </p>
<?php else: ?>
    <table>
        <thead><tr><th>Title</th><th>Slug</th><th>Status</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td>
                    <a href="/admin/<?= Html::escape($type) ?>s/edit?slug=<?= urlencode((string) $item['slug']) ?>">
                        <?= Html::escape((string) $item['title']) ?>
                    </a>
                </td>
                <td><code><?= Html::escape((string) $item['slug']) ?></code></td>
                <td>
                    <span class="pill <?= ($item['status'] ?? '') === 'published' ? 'live' : 'draft' ?>">
                        <?= Html::escape((string) ($item['status'] ?? 'draft')) ?>
                    </span>
                </td>
                <td class="muted"><?= Html::escape(substr((string) ($item['updated_at'] ?? ''), 0, 10)) ?></td>
                <td class="right">
                    <form method="post" onsubmit="return confirm('Delete this <?= Html::escape($type) ?>? This cannot be undone from the admin.');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="slug" value="<?= Html::escape((string) $item['slug']) ?>">
                        <button type="submit" class="link danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
