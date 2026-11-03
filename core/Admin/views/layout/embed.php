<?php
/**
 * Minimal layout for embedded admin pages (picker iframe, etc).
 * No sidebar, no chrome, just the content.
 *
 * @var string $_content
 * @var \Velk\Config $config
 */
?>
<!DOCTYPE html>
<html lang="<?= e(\Velk\App::instance()->adminI18n->current()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __('layout.media_picker_title') ?></title>
    <link rel="stylesheet" href="<?= e(admin_asset('css/admin.css')) ?>">
</head>
<body class="embed" data-admin-base="<?= e(rtrim(admin_url(''), '/')) ?>">
    <main class="embed__main">
        <?= $_content ?>
    </main>
    <?php include __DIR__ . '/_js_i18n.php'; ?>
    <script type="module" src="<?= e(admin_asset('js/admin.js')) ?>"></script>
</body>
</html>
