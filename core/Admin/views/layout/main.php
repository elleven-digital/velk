<?php
/**
 * @var string $_content
 * @var \Velk\Models\User|null $authUser  quem está logado — NUNCA o registro
 *      que a página edita. Chama-se `authUser` e não `user` porque o layout
 *      renderiza dentro da página, e `$user` é o nome natural para "o usuário
 *      desta tela": `users/edit.php` usa exatamente isso. Como `renderFile()`
 *      faz `array_merge($shared, $data)`, o dado da página vencia o
 *      compartilhado e a barra lateral passava a mostrar o usuário editado —
 *      ou desaparecia em /admin/users/new, onde ele é null.
 * @var \Velk\Config $config
 * @var string|null $flash_success
 * @var string|null $flash_error
 * @var list<string> $flash_warnings
 */
$siteName = (string) ($config->site('site.name') ?? 'Velk');
$itemTypes = $config->itemTypes();
$pages = $config->pages();
$taxonomies = $config->taxonomies();
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
$currentPathOnly = parse_url($currentPath, PHP_URL_PATH) ?: '';
$isActive = function (string $needle) use ($currentPathOnly): bool {
    return $needle !== '' && str_starts_with($currentPathOnly, $needle);
};
$isExact = function (string $needle) use ($currentPathOnly): bool {
    return $currentPathOnly === $needle || $currentPathOnly === $needle . '/';
};
$initial = $authUser !== null ? mb_strtoupper(mb_substr($authUser->name, 0, 1, 'UTF-8'), 'UTF-8') : '?';

$itemCounts = [];
foreach ($itemTypes as $type => $def) {
    $itemCounts[(string) $type] = (int) \Velk\App::instance()->db->fetchColumn(
        'SELECT COUNT(*) FROM items WHERE type = ?',
        [$type]
    );
}

$formsList = (array) ($config->site('forms') ?? []);
$formCounts = [];
foreach ($formsList as $fid => $fdef) {
    $formCounts[(string) $fid] = (int) \Velk\App::instance()->db->fetchColumn(
        // `blocked_by IS NULL`: o badge conta LEAD, não spam. Sem isso um
        // formulário sob ataque mostraria 4.000 no menu e o cliente abriria
        // esperando 4.000 contatos.
        //
        // `pending_step IS NULL`: pelo mesmo motivo, com etapas. Quem parou na
        // etapa 2 ainda não é contato — não tem o que a última etapa pedia, e
        // a lista abre no default "só concluídos". Sem esta cláusula o menu
        // diria 7 e a página diria 3, que é a incoerência que o `countSpam()`
        // já custou caro. Espelha o `FormSubmission::count()`.
        'SELECT COUNT(*) FROM form_submissions WHERE form_id = ? AND blocked_by IS NULL AND pending_step IS NULL',
        [(string) $fid]
    );
}

// Theme-defined option groups go in the "Options" sidebar block. Built-in
// groups (e.g. Scripts & tracking) belong to "Settings" alongside
// Users — they're cross-site infrastructure, not editorial content.
$optionsList = (array) ($config->site('options') ?? []);
$builtinOptionsList = $config->builtinOptions();

$icon = function (string $name): string {
    $svg = match ($name) {
        'home'      => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/>',
        'file-text' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'folder'    => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'tag'       => '<path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
        'page'      => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'image'     => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'inbox'     => '<polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'sliders'   => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
        'code'       => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
        'user-check' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/>',
        'globe'      => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        default     => '',
    };
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . $svg . '</svg>';
};
?>
<!DOCTYPE html>
<html lang="<?= e(\Velk\App::instance()->adminI18n->current()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __('layout.title', ['site' => $siteName]) ?></title>
    <link rel="stylesheet" href="<?= e(admin_asset('css/admin.css')) ?>">
</head>
<body data-admin-base="<?= e(rtrim(admin_url(''), '/')) ?>">
    <div class="app" data-app>
        <button
            type="button"
            class="app__menu-toggle"
            aria-label="<?= __('layout.open_menu') ?>"
            aria-expanded="false"
            aria-controls="app-sidebar"
            data-menu-toggle>
            <svg class="app__menu-icon app__menu-icon--open" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="3" y1="6" x2="21" y2="6"/>
                <line x1="3" y1="12" x2="21" y2="12"/>
                <line x1="3" y1="18" x2="21" y2="18"/>
            </svg>
            <svg class="app__menu-icon app__menu-icon--close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <line x1="18" y1="6" x2="6" y2="18"/>
                <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
        </button>
        <div class="app__backdrop" data-menu-backdrop aria-hidden="true"></div>
        <aside class="app__sidebar" id="app-sidebar">
            <div class="brand">
                <span class="brand__mark"><?= e(mb_strtoupper(mb_substr($siteName, 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
                <div class="brand__lockup">
                    <span class="brand__name"><?= e($siteName) ?></span>
                    <a class="brand__view" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= __('layout.view_site') ?></a>
                </div>
            </div>

            <nav class="nav">
                <div class="nav__group">
                    <a class="nav__link <?= $isExact(admin_url('')) || $isExact(rtrim(admin_url(''), '/')) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('')) ?>">
                        <span class="nav__link__icon"><?= $icon('home') ?></span>
                        <span class="nav__link__label"><?= __('nav.dashboard') ?></span>
                    </a>
                </div>

                <?php
                // Track which taxonomies are referenced by item types so we
                // don't render them twice. Orphans (not referenced anywhere)
                // get their own group below.
                $assignedTaxonomies = [];
                foreach ($itemTypes as $type => $def) {
                    foreach ((array) ($def['taxonomies'] ?? []) as $tax) {
                        $assignedTaxonomies[(string) $tax] = true;
                    }
                }
                ?>

                <div class="nav__group">
                    <div class="nav__group-title"><?= __('nav.structure') ?></div>
                    <?php if (!empty($pages)): ?>
                        <a class="nav__link <?= $isActive(admin_url('pages')) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('pages')) ?>">
                            <span class="nav__link__icon"><?= $icon('page') ?></span>
                            <span class="nav__link__label"><?= __('nav.pages') ?></span>
                            <span class="nav__link-count"><?= e((string) count($pages)) ?></span>
                        </a>
                    <?php endif; ?>
                    <a class="nav__link <?= $isActive(admin_url('media')) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('media')) ?>">
                        <span class="nav__link__icon"><?= $icon('image') ?></span>
                        <span class="nav__link__label"><?= __('nav.media') ?></span>
                    </a>
                </div>

                <?php if (!empty($itemTypes)): ?>
                    <div class="nav__group">
                        <div class="nav__group-title"><?= __('nav.content') ?></div>
                        <?php foreach ($itemTypes as $type => $def): ?>
                            <a class="nav__link <?= $isActive(admin_url('items/' . $type)) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('items/' . $type)) ?>">
                                <span class="nav__link__icon"><?= $icon('file-text') ?></span>
                                <span class="nav__link__label"><?= e((string)($def['label'] ?? $type)) ?></span>
                                <?php if (($itemCounts[$type] ?? 0) > 0): ?>
                                    <span class="nav__link-count"><?= e((string) $itemCounts[$type]) ?></span>
                                <?php endif; ?>
                            </a>

                            <?php
                            $childTaxonomies = (array) ($def['taxonomies'] ?? []);
                            foreach ($childTaxonomies as $taxKey):
                                $taxKey = (string) $taxKey;
                                if (!isset($taxonomies[$taxKey])) continue;
                                $taxDef = $taxonomies[$taxKey];
                            ?>
                                <a class="nav__link nav__link--child <?= $isActive(admin_url('taxonomies/' . $taxKey)) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('taxonomies/' . $taxKey)) ?>">
                                    <span class="nav__link__label"><?= e((string)($taxDef['label'] ?? $taxKey)) ?></span>
                                </a>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php
                $orphanTaxonomies = array_diff_key($taxonomies, $assignedTaxonomies);
                ?>
                <?php if (!empty($orphanTaxonomies)): ?>
                    <div class="nav__group">
                        <div class="nav__group-title"><?= __('nav.taxonomies') ?></div>
                        <?php foreach ($orphanTaxonomies as $tax => $def): ?>
                            <a class="nav__link <?= $isActive(admin_url('taxonomies/' . $tax)) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('taxonomies/' . $tax)) ?>">
                                <span class="nav__link__icon"><?= $icon('tag') ?></span>
                                <span class="nav__link__label"><?= e((string)($def['label'] ?? $tax)) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($formsList)): ?>
                    <div class="nav__group">
                        <div class="nav__group-title"><?= __('nav.forms') ?></div>
                        <a class="nav__link <?= ($currentPathOnly === admin_url('forms') || $currentPathOnly === admin_url('forms') . '/') ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('forms')) ?>">
                            <span class="nav__link__icon"><?= $icon('inbox') ?></span>
                            <span class="nav__link__label"><?= __('nav.forms_all') ?></span>
                            <span class="nav__link-count"><?= e((string) array_sum($formCounts)) ?></span>
                        </a>
                        <?php foreach ($formsList as $fid => $fdef): ?>
                            <a class="nav__link nav__link--child <?= $isActive(admin_url('forms/' . $fid)) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('forms/' . $fid)) ?>">
                                <span class="nav__link__label"><?= e((string)($fdef['label'] ?? $fid)) ?></span>
                                <?php if (($formCounts[$fid] ?? 0) > 0): ?>
                                    <span class="nav__link-count"><?= e((string) $formCounts[$fid]) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($config->isStoreEnabled()): ?>
                    <div class="nav__group">
                        <div class="nav__group-title"><?= __('nav.store') ?></div>
                        <a class="nav__link <?= $isActive(admin_url('orders')) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('orders')) ?>">
                            <span class="nav__link__icon"><?= $icon('inbox') ?></span>
                            <span class="nav__link__label"><?= __('nav.orders') ?></span>
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (!empty($optionsList)): ?>
                    <div class="nav__group">
                        <div class="nav__group-title"><?= __('nav.options') ?></div>
                        <?php foreach ($optionsList as $optKey => $optDef): ?>
                            <a class="nav__link <?= $isActive(admin_url('options/' . $optKey)) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('options/' . $optKey)) ?>">
                                <span class="nav__link__icon"><?= $icon('sliders') ?></span>
                                <span class="nav__link__label"><?= e((string)($optDef['label'] ?? $optKey)) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="nav__group">
                    <div class="nav__group-title"><?= __('nav.settings') ?></div>
                    <?php foreach ($builtinOptionsList as $optKey => $optDef): ?>
                        <a class="nav__link <?= $isActive(admin_url('options/' . $optKey)) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('options/' . $optKey)) ?>">
                            <span class="nav__link__icon"><?= $icon($optKey === 'tracking' ? 'code' : 'sliders') ?></span>
                            <span class="nav__link__label"><?= e((string)($optDef['label'] ?? $optKey)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($authUser?->isAdmin()): ?>
                        <a class="nav__link <?= $isActive(admin_url('users')) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('users')) ?>">
                            <span class="nav__link__icon"><?= $icon('users') ?></span>
                            <span class="nav__link__label"><?= __('nav.users') ?></span>
                        </a>
                    <?php elseif ($authUser !== null): ?>
                        <?php $selfUrl = admin_url('users/' . $authUser->id); ?>
                        <a class="nav__link <?= ($currentPathOnly === $selfUrl || $currentPathOnly === $selfUrl . '/') ? 'nav__link--active' : '' ?>" href="<?= e($selfUrl) ?>">
                            <span class="nav__link__icon"><?= $icon('users') ?></span>
                            <span class="nav__link__label"><?= __('nav.my_account') ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($config->isMembersEnabled()): ?>
                        <a class="nav__link <?= $isActive(admin_url('members')) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('members')) ?>">
                            <span class="nav__link__icon"><?= $icon('user-check') ?></span>
                            <span class="nav__link__label"><?= __('nav.members') ?></span>
                        </a>
                    <?php endif; ?>
                    <a class="nav__link <?= $isActive(admin_url('scripts')) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('scripts')) ?>">
                        <span class="nav__link__icon"><?= $icon('code') ?></span>
                        <span class="nav__link__label"><?= __('nav.scripts') ?></span>
                    </a>
                    <?php if ($config->isI18nEnabled()): ?>
                        <a class="nav__link <?= ($isActive(admin_url('languages')) || $isActive(admin_url('translations'))) ? 'nav__link--active' : '' ?>" href="<?= e(admin_url('languages')) ?>">
                            <span class="nav__link__icon"><?= $icon('globe') ?></span>
                            <span class="nav__link__label"><?= __('nav.languages') ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>

            <?php if ($authUser !== null): ?>
                <div class="app__sidebar-footer">
                    <div class="user-card">
                        <span class="user-card__avatar"><?= e($initial) ?></span>
                        <div class="user-card__info">
                            <span class="user-card__name"><?= e($authUser->name) ?></span>
                            <form class="user-card__form" method="post" action="<?= e(admin_url('logout')) ?>">
                                <?= csrf_field() ?>
                                <button class="user-card__signout" type="submit"><?= __('layout.logout') ?></button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </aside>

        <main class="app__main">
            <?php if (!empty($flash_success)): ?>
                <div class="flash flash--success"><?= e($flash_success) ?></div>
            <?php endif; ?>
            <?php if (!empty($flash_error)): ?>
                <div class="flash flash--error"><?= e($flash_error) ?></div>
            <?php endif; ?>
            <?php foreach ($flash_warnings ?? [] as $warning): ?>
                <div class="flash flash--warning"><?= e((string) $warning) ?></div>
            <?php endforeach; ?>
            <?= $_content ?>
        </main>
    </div>
    <?php include __DIR__ . '/_js_i18n.php'; ?>
    <script type="module" src="<?= e(admin_asset('js/admin.js')) ?>"></script>
</body>
</html>
