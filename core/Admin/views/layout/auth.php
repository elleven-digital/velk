<?php
/**
 * @var string $_content
 * @var \Velk\Config $config
 */
$siteName = (string) ($config->site('site.name') ?? 'Velk');
?>
<!DOCTYPE html>
<html lang="<?= e(\Velk\App::instance()->adminI18n->current()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __('auth.title', ['site' => $siteName]) ?></title>
    <link rel="stylesheet" href="<?= e(admin_asset('css/admin.css')) ?>">
</head>
<body class="auth-shell">
    <main class="auth">
        <?= $_content ?>
    </main>
    <footer class="auth__signature"><?= __('auth.made_with_velk') ?></footer>
</body>
</html>
