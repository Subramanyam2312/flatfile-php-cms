<?php

/**
 * @var Cms\App $app
 * @var array<string,mixed> $settings
 */

use Cms\Csrf;
use Cms\Html;

$title = 'Settings';
require $app->root . '/views/partials/admin-head.php';

$value = static fn (string $key): string => Html::escape((string) ($settings[$key] ?? ''));
?>

<h1>Settings</h1>

<form method="post" class="stack">
    <?= Csrf::field() ?>

    <label>Site name
        <input name="site_name" required value="<?= $value('site_name') ?>">
    </label>

    <label>Tagline
        <input name="tagline" value="<?= $value('tagline') ?>">
    </label>

    <label>Site URL
        <input name="base_url" type="url" value="<?= $value('base_url') ?>" placeholder="https://example.com">
        <small>
            No trailing slash. Used for absolute URLs in the sitemap and
            <code>robots.txt</code>; without it both are emitted incomplete.
        </small>
    </label>

    <label>Contact email
        <input name="contact_email" type="email" value="<?= $value('contact_email') ?>">
        <small>Shown on the site. Form submissions go to Messages regardless.</small>
    </label>

    <label>Accent colour
        <input name="accent" type="color" value="<?= $value('accent') ?: '#2b6cb0' ?>">
    </label>

    <button type="submit">Save settings</button>
</form>

<h2>Account</h2>
<p><a href="/admin/password">Change your password</a></p>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
