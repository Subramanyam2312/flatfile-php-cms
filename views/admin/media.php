<?php

/**
 * @var Cms\App $app
 * @var list<array<string,mixed>> $files
 */

use Cms\Csrf;
use Cms\Html;

$title = 'Media';
require $app->root . '/views/partials/admin-head.php';
?>

<h1>Media</h1>

<form method="post" enctype="multipart/form-data" class="stack card">
    <?= Csrf::field() ?>
    <label>Upload an image
        <input type="file" name="image" accept="image/jpeg,image/png,image/gif,image/webp" required>
        <small>
            JPEG, PNG, GIF or WebP, up to 8 MB. The file is renamed on upload and
            re-encoded to strip EXIF — including the GPS coordinates phone photos
            carry.
        </small>
    </label>
    <button type="submit">Upload</button>
</form>

<?php if ($files === []): ?>
    <p class="muted">No images yet.</p>
<?php else: ?>
    <div class="grid">
        <?php foreach ($files as $file): ?>
            <figure class="thumb">
                <img src="/uploads/<?= Html::escape((string) $file['file']) ?>"
                     alt=""
                     loading="lazy"
                     width="<?= (int) ($file['width'] ?? 0) ?>"
                     height="<?= (int) ($file['height'] ?? 0) ?>">
                <figcaption>
                    <code><?= Html::escape((string) $file['file']) ?></code>
                    <span class="muted">
                        <?= (int) ($file['width'] ?? 0) ?>×<?= (int) ($file['height'] ?? 0) ?>
                        · <?= number_format(((int) ($file['bytes'] ?? 0)) / 1024) ?> KB
                    </span>
                    <form method="post" onsubmit="return confirm('Delete this image? Pages using it will show a broken image.');">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="file" value="<?= Html::escape((string) $file['file']) ?>">
                        <button type="submit" class="link danger">Delete</button>
                    </form>
                </figcaption>
            </figure>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
