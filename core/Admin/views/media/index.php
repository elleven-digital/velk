<?php
/**
 * @var list<\Velk\Models\Media> $media
 * @var int $total          o que a listagem mostra (já com a busca)
 * @var int $libraryTotal   o mesmo sem a busca
 * @var string $search      o termo da busca (`q`), '' sem busca
 * @var array{page:int, perPage:int, offset:int, totalPages:int, total:int} $pg
 * @var array $pgQuery
 * @var bool $picker
 * @var list<string> $accept
 * @var array{server:int, categories:array<string,int>} $uploadLimits
 */
$uploadUrl = admin_url('media/upload');

$acceptAttrMap = [
    'image' => 'image/*',
    'video' => 'video/*',
    'audio' => 'audio/*',
    'document' => '.pdf,.docx,.xlsx,.pptx',
    'archive' => '.zip',
];
$acceptCats = ($accept ?? []) === [] ? array_keys($acceptAttrMap) : $accept;
$acceptAttr = implode(',', array_map(fn($c) => $acceptAttrMap[$c], $acceptCats));
$serverLimitMb = ($uploadLimits['server'] ?? 0) > 0 ? (int) round($uploadLimits['server'] / 1048576) : 0;
$maxCategoryMb = (int) round(max($uploadLimits['categories'] ?: [0]) / 1048576);
$search = $search ?? '';
$libraryTotal = $libraryTotal ?? $total;
?>
<?php if (!$picker): ?>
    <div class="page-header">
        <div>
            <h1 class="page-header__title"><?= __('media.title') ?></h1>
            <p class="page-header__subtitle"><span data-media-count><?php
                if ($search !== '') {
                    echo __('media.search_count', ['found' => $total, 'total' => $libraryTotal]);
                } else {
                    echo $total === 1 ? __('media.count_one') : __('media.count_other', ['count' => $total]);
                }
            ?></span> · <?= __('media.subtitle_types') ?></p>
        </div>
    </div>
<?php else: ?>
    <div class="picker-header">
        <h2 class="picker-header__title"><?= __('media.picker_title') ?></h2>
        <p class="picker-header__subtitle"><?= __('media.picker_subtitle') ?></p>
    </div>
<?php endif; ?>

<div class="media-uploader" data-media-uploader data-upload-url="<?= e($uploadUrl) ?>" data-csrf="<?= e(csrf_token()) ?>"
     data-server-limit="<?= e((string) ($uploadLimits['server'] ?? 0)) ?>"
     data-category-limits="<?= e(json_encode($uploadLimits['categories'] ?? [])) ?>"
     data-accept="<?= e(implode(',', $accept ?? [])) ?>">
    <input type="file" multiple accept="<?= e($acceptAttr) ?>" data-media-uploader-input hidden>
    <div class="media-uploader__drop" data-media-uploader-drop>
        <div class="media-uploader__line">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <path d="M17 8l-5-5-5 5"/>
                <path d="M12 3v12"/>
            </svg>
            <strong><?= __('media.drop_here') ?></strong>
            <span><?= __('media.or') ?></span>
            <button type="button" class="button button--small" data-media-uploader-trigger><?= __('media.select_files') ?></button>
        </div>
        <p class="media-uploader__limits">
            <?= __('media.upload_limits') ?><?= $serverLimitMb > 0 && $serverLimitMb < $maxCategoryMb ? __('media.upload_limits_server_suffix', ['mb' => $serverLimitMb]) : '' ?>
        </p>
    </div>
    <ul class="media-uploader__queue" data-media-uploader-queue></ul>
</div>

<?php // Busca: um GET de verdade (Enter e sem JS funcionam); o admin.js refaz a
      // consulta enquanto se digita e troca só [data-media-results]. Os campos
      // ocultos mantêm o modo do seletor — sem eles a busca abriria a
      // biblioteca inteira dentro do iframe, sem o filtro de categoria. ?>
<?php if ($libraryTotal > 0 || $search !== ''): ?>
    <form class="tbl-toolbar media-search" method="get" action="<?= e(admin_url('media')) ?>" role="search" data-media-search-form>
        <?php if ($picker): ?>
            <input type="hidden" name="picker" value="1">
        <?php endif; ?>
        <?php if (!empty($accept)): ?>
            <input type="hidden" name="accept" value="<?= e(implode(',', $accept)) ?>">
        <?php endif; ?>
        <label class="tbl-search">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/>
                <path d="M21 21l-4.35-4.35"/>
            </svg>
            <input type="search" name="q" value="<?= e($search) ?>"
                   placeholder="<?= __('media.search_placeholder') ?>"
                   aria-label="<?= __('media.search_label') ?>"
                   autocomplete="off" spellcheck="false" data-media-search>
        </label>
    </form>
<?php endif; ?>

<div class="media-results" data-media-results>
<?php if (empty($media) && $search !== ''): ?>
    <div class="empty" data-media-no-results><?= __('media.no_results', ['q' => $search]) ?></div>
<?php elseif (empty($media)): ?>
    <div class="empty" data-media-empty><?= __('media.empty') ?></div>
<?php endif; ?>

<ul class="media-grid" data-media-grid data-picker="<?= $picker ? '1' : '0' ?>">
    <?php foreach ($media as $m): ?>
        <?php $cat = $m->category(); ?>
        <li class="media-grid__item"
            data-media-category="<?= e($cat) ?>"
            data-media-id="<?= e((string) $m->id) ?>"
            data-media-url="<?= e($m->url('full')) ?>"
            data-media-thumb="<?= e($m->isImage() ? $m->url('thumb') : '') ?>"
            data-media-name="<?= e($m->originalName) ?>"
            data-media-mime="<?= e($m->mime) ?>"
            data-media-size="<?= e($m->humanSize()) ?>"
            data-media-dimensions="<?= e($m->width && $m->height ? $m->width . '×' . $m->height : '') ?>"
            data-media-alt="<?= e($m->alt ?? '') ?>"
            data-media-alt-translations="<?= e(json_encode($m->altTranslations, JSON_UNESCAPED_UNICODE | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"
            data-media-is-image="<?= $m->isImage() ? '1' : '0' ?>"
            data-media-created-at="<?= e($m->createdAt) ?>">
            <button type="button" class="media-grid__tile" data-media-open>
                <?php if ($cat === 'image'): ?>
                    <img src="<?= e($m->url('thumb')) ?>" alt="" loading="lazy">
                <?php elseif ($cat === 'video'): ?>
                    <video class="media-grid__video" src="<?= e($m->url()) ?>" preload="metadata" muted playsinline></video>
                <?php else: ?>
                    <div class="media-grid__doc">
                        <span><?= e(strtoupper(pathinfo($m->filename, PATHINFO_EXTENSION))) ?></span>
                    </div>
                <?php endif; ?>
            </button>
            <span class="media-grid__name" title="<?= e($m->originalName) ?>"><?= e($m->originalName) ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<?php
$page = $pg['page'];
$totalPages = $pg['totalPages'];
$query = $pgQuery;
include __DIR__ . '/../layout/pagination.php';
?>
</div>

<!-- Detail panel (full library only) -->
<?php if (!$picker): ?>
    <div class="media-panel" data-media-panel hidden>
        <div class="media-panel__overlay" data-media-panel-close></div>
        <aside class="media-panel__body">
            <header class="media-panel__header">
                <h2 class="media-panel__title" data-media-panel-name></h2>
                <button type="button" class="media-panel__close" data-media-panel-close aria-label="<?= __('media.close') ?>">×</button>
            </header>

            <div class="media-panel__preview" data-media-panel-preview></div>

            <dl class="media-panel__meta">
                <dt><?= __('media.panel_type') ?></dt><dd data-media-panel-mime></dd>
                <dt><?= __('media.panel_size') ?></dt><dd data-media-panel-size></dd>
                <dt><?= __('media.panel_dimensions') ?></dt><dd data-media-panel-dimensions></dd>
                <dt><?= __('media.panel_added') ?></dt><dd data-media-panel-created></dd>
                <dt><?= __('media.panel_url') ?></dt>
                <dd><input type="text" class="input" data-media-panel-url readonly></dd>
            </dl>

            <form class="media-panel__form" data-media-panel-alt-form>
                <?= csrf_field() ?>
                <div class="field">
                    <label class="field__label" for="media-alt">
                        <?= __('media.alt_label') ?>
                        <?php if (!empty($languages)): ?>
                            <span class="muted" style="font-weight:400;font-size:11px"><?= __('media.alt_default_lang_suffix') ?></span>
                        <?php endif; ?>
                    </label>
                    <div class="field__control">
                        <input class="input" type="text" id="media-alt" name="alt" data-media-panel-alt>
                    </div>
                </div>

                <?php if (!empty($languages)): ?>
                    <?php foreach ($languages as $lang): ?>
                        <div class="field">
                            <label class="field__label" for="media-alt-<?= e($lang->code) ?>">
                                <?= __('media.alt_translation_label', ['lang' => $lang->label]) ?>
                                <span class="muted" style="font-weight:400;font-size:11px"> (<?= e($lang->code) ?>)</span>
                            </label>
                            <div class="field__control">
                                <input
                                    class="input"
                                    type="text"
                                    id="media-alt-<?= e($lang->code) ?>"
                                    name="alt_translations[<?= e($lang->code) ?>]"
                                    data-media-panel-alt-translation="<?= e($lang->code) ?>"
                                    placeholder="<?= __('media.alt_translation_placeholder') ?>">
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <button type="submit" class="button button--small"><?= __('media.save_alt') ?></button>
                <span class="muted" data-media-panel-alt-status></span>
            </form>

            <footer class="media-panel__actions">
                <form data-media-panel-delete-form data-confirm="<?= __('media.confirm_delete') ?>">
                    <?= csrf_field() ?>
                    <button type="submit" class="button button--small button--danger"><?= __('media.delete_file') ?></button>
                </form>
            </footer>
        </aside>
    </div>
<?php endif; ?>
