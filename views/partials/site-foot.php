<?php

/**
 * @var Cms\App $app
 * @var array<string,mixed> $settings
 */

use Cms\Html;
?>
</main>

<footer class="site-foot">
    <p>
        © <?= date('Y') ?> <?= Html::escape((string) ($settings['site_name'] ?? '')) ?>
        <?php if (($settings['contact_email'] ?? '') !== ''): ?>
            · <a href="mailto:<?= Html::escape((string) $settings['contact_email']) ?>">
                <?= Html::escape((string) $settings['contact_email']) ?>
            </a>
        <?php endif; ?>
    </p>
</footer>
</body>
</html>
