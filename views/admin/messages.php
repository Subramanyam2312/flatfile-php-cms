<?php

/**
 * @var Cms\App $app
 * @var list<array<string,mixed>> $messages
 */

use Cms\Csrf;
use Cms\Html;

$title = 'Messages';
require $app->root . '/views/partials/admin-head.php';
?>

<h1>Messages</h1>

<p class="muted">
    Contact form submissions, newest first. They are stored, not emailed —
    this CMS has no mailer. The last 500 are kept.
</p>

<?php if ($messages === []): ?>
    <p class="muted">No messages yet.</p>
<?php else: ?>
    <?php foreach ($messages as $message): ?>
        <article class="card message">
            <header>
                <strong><?= Html::escape((string) ($message['name'] ?? '')) ?></strong>
                <a href="mailto:<?= Html::escape((string) ($message['email'] ?? '')) ?>">
                    <?= Html::escape((string) ($message['email'] ?? '')) ?>
                </a>
                <span class="muted"><?= Html::escape(str_replace('T', ' ', substr((string) ($message['created_at'] ?? ''), 0, 16))) ?></span>
            </header>

            <p><?= nl2br(Html::escape((string) ($message['message'] ?? ''))) ?></p>

            <form method="post" onsubmit="return confirm('Delete this message?');">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= Html::escape((string) ($message['id'] ?? '')) ?>">
                <button type="submit" class="link danger">Delete</button>
            </form>
        </article>
    <?php endforeach; ?>
<?php endif; ?>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
