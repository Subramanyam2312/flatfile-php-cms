<?php

/**
 * @var Cms\App $app
 * @var string|null $error
 */

use Cms\Csrf;
use Cms\Html;

$title = 'Change password';
require $app->root . '/views/partials/admin-head.php';
?>

<h1>Change password</h1>

<?php if ($error !== null): ?>
    <p class="flash error"><?= Html::escape($error) ?></p>
<?php endif; ?>

<form method="post" class="stack narrow">
    <?= Csrf::field() ?>

    <label>Current password
        <input name="current" type="password" required autocomplete="current-password">
    </label>

    <label>New password
        <input name="new" type="password" required minlength="12" autocomplete="new-password">
        <small>At least 12 characters.</small>
    </label>

    <button type="submit">Change password</button>
</form>

<p class="muted">
    There is no password reset by email — this CMS sends no mail. If you lose
    this password, delete <code>data/admin.php</code> on the server and the setup
    page reopens so you can create the account again. Your content is untouched.
</p>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
