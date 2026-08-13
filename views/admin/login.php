<?php

/**
 * @var Cms\App $app
 * @var string|null $error
 * @var list<array{message:string,type:string}>|null $flashes
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
<title>Sign in</title>
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="centred">
<form method="post" class="card narrow">
    <h1>Sign in</h1>

    <?php foreach (($flashes ?? []) as $f): ?>
        <p class="flash <?= Html::escape($f['type'] ?? 'ok') ?>"><?= Html::escape($f['message'] ?? '') ?></p>
    <?php endforeach; ?>

    <?php if ($error !== null): ?>
        <p class="flash error"><?= Html::escape($error) ?></p>
    <?php endif; ?>

    <?= Csrf::field() ?>

    <label>Username
        <input name="username" required autocomplete="username" autofocus>
    </label>

    <label>Password
        <input name="password" type="password" required autocomplete="current-password">
    </label>

    <button type="submit">Sign in</button>
</form>
</body>
</html>
