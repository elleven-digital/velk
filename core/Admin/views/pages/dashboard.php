<?php
/** @var array<string, array{label:string,count:int}> $stats */
/** @var array $pages */
/** @var array $taxonomies */
$siteName = (string) ($config->site('site.name') ?? 'Velk');
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('dashboard.title') ?></h1>
        <p class="page-header__subtitle"><?= __('dashboard.subtitle', ['site' => $siteName]) ?></p>
    </div>
    <div class="page-header__actions">
        <a class="button button--ghost" href="<?= e(url('/')) ?>" target="_blank" rel="noopener"><?= __('dashboard.view_site') ?></a>
    </div>
</div>

<?php if (!empty($stats)): ?>
    <div class="section-header">
        <h2 class="section-header__title"><?= __('dashboard.section_content') ?></h2>
    </div>
    <div class="cards">
        <?php foreach ($stats as $type => $s): ?>
            <a class="card" href="<?= e(admin_url('items/' . $type)) ?>">
                <div class="card__label"><?= e($s['label']) ?></div>
                <div class="card__value"><?= e((string) $s['count']) ?></div>
                <div class="card__meta">
                    <span><?= __('dashboard.manage') ?></span>
                    <span class="card__meta-arrow">→</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($pages)): ?>
    <div class="section-header">
        <h2 class="section-header__title"><?= __('dashboard.section_pages') ?></h2>
        <a class="muted" style="font-size:12px" href="<?= e(admin_url('pages')) ?>"><?= __('dashboard.view_all') ?></a>
    </div>
    <div class="cards">
        <?php foreach ($pages as $key => $page): ?>
            <a class="card" href="<?= e(admin_url('pages/' . $key)) ?>">
                <div class="card__label"><?= __('dashboard.card_label_page') ?></div>
                <h3 class="card__title"><?= e((string) ($page['label'] ?? $key)) ?></h3>
                <div class="card__meta">
                    <span><?= __('common.edit') ?></span>
                    <span class="card__meta-arrow">→</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if (!empty($taxonomies)): ?>
    <div class="section-header">
        <h2 class="section-header__title"><?= __('dashboard.section_taxonomies') ?></h2>
    </div>
    <div class="cards">
        <?php foreach ($taxonomies as $tax => $def): ?>
            <a class="card" href="<?= e(admin_url('taxonomies/' . $tax)) ?>">
                <div class="card__label"><?= e((string) ($def['label'] ?? $tax)) ?></div>
                <h3 class="card__title"><?= __('dashboard.card_title_terms') ?></h3>
                <div class="card__meta">
                    <span><?= __('dashboard.manage') ?></span>
                    <span class="card__meta-arrow">→</span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
