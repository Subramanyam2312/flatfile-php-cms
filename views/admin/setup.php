<?php

/**
 * @var Cms\App $app
 * @var string|null $error
 */

use Cms\Csrf;
use Cms\Html;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Set up</title>
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="centred">
<form method="post" class="card narrow">
    <h1>Create your account</h1>
    <p class="muted">
        One account, no public sign-up. Once this is created, this page closes
        permanently — it cannot be used to reset the password later.
    </p>

    <?php if ($error !== null): ?>
        <p class="flash error"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <?= Csrf::field() ?>

    <label>Username
        <input name="username" required minlength="3" autocomplete="username" autofocus>
    </label>

    <label>Password
        <input name="password" type="password" required minlength="12" autocomplete="new-password">
        <small>At least 12 characters. Length matters more than symbols.</small>
    </label>

    <button type="submit">Create account</button>
</form>
</body>
</html>
