<?php

/** @var Cms\App $app */

$title = 'Not found';
require $app->root . '/views/partials/admin-head.php';
?>

<h1>Not found</h1>
<p class="muted">No admin page at this address. <a href="/admin">Back to the dashboard</a>.</p>

<?php require $app->root . '/views/partials/admin-foot.php'; ?>
