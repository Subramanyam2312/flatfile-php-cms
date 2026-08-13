<?php

/**
 * @var Cms\App $app
 * @var string $type
 * @var array<string,mixed>|null $item
 * @var string|null $error
 * @var list<array<string,mixed>> $media
 */

use Cms\Csrf;
use Cms\Html;

$isNew = $item === null;
$title = ($isNew ? 'New ' : 'Edit ') . $type;
require $app->root . '/views/partials/admin-head.php';

$value = static fn (string $key, string $default = ''): string =>
    Html::escape((string) ($item[$key] ?? $default));
?>

<div class="head-row">
    <h1><?= Html::escape($title) ?></h1>
    <a href="/admin/<?= Html::escape($type) ?>s">Back to <?= Html::escape($type) ?>s</a>
</div>

<?php if ($error !== null): ?>
    <p class="flash error"><?= Html::escape($error) ?></p>
<?php endif; ?>

<form method="post" class="stack">
    <?= Csrf::field() ?>

    <label>Title
        <input name="title" required value="<?= $value('title') ?>" autofocus>
    </label>

    <label>Slug
        <input name="slug" value="<?= $value('slug') ?>" placeholder="generated from the title">
        <small>
            <?php if ($type === 'page'): ?>
                The URL after the domain. Use <code>home</code> for the front page.
            <?php else: ?>
                The URL after <code>/blog/</code>.
            <?php endif; ?>
        </small>
    </label>

    <label>Body
        <textarea name="body" rows="18"><?= $value('body') ?></textarea>
        <small>
            Allowed: <code>p, br, strong, em, u, s, ul, ol, li, h2, h3, h4,
            blockquote, a, code, pre, hr</code>. Everything else is stripped on
            save, including scripts and event handlers.
        </small>
    </label>

    <fieldset>
        <legend>Search appearance</legend>

        <label>Meta title
            <input name="meta_title" value="<?= $value('meta_title') ?>" maxlength="70">
            <small>Falls back to the title. Around 60 characters shows in full.</small>
        </label>

        <label>Meta description
            <textarea name="meta_desc" rows="2" maxlength="180"><?= $value('meta_desc') ?></textarea>
            <small>Falls back to an excerpt of the body. Around 155 characters.</small>
        </label>

        <label>Image
            <input name="image" value="<?= $value('image') ?>" list="media-files" placeholder="filename from Media">
            <datalist id="media-files">
                <?php foreach ($media as $file): ?>
                    <option value="<?= Html::escape((string) $file['file']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
        </label>
    </fieldset>

    <label class="inline">Status
        <select name="status">
            <option value="draft"<?= ($item['status'] ?? 'draft') === 'draft' ? ' selected' : '' ?>>Draft</option>
            <option value="published"<?= ($item['status'] ?? '') === 'published' ? ' selected' : '' ?>>Published</option>
        </select>
        <small>Only published items appear on the public site.</small>
    </label>

    <div class="actions">
        <button type="submit">Save</button>
        <?php if (!$isNew && ($item['status'] ?? '') === 'published'): ?>
            <a class="button ghost"
               href="<?= $type === 'page'
                   ? ('/' . ($item['slug'] === 'home' ? '' : Html::escape((string) $item['slug'])))
                   : ('/blog/' . Html::escape((string) $item['slug'])) ?>"
               target="_blank" rel="noopener">View</a>
        <?php endif; ?>
    </div>
</form>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
