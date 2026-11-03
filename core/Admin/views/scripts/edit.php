<?php
/**
 * @var \Velk\Models\TrackingScript|null $script
 * @var array<string,mixed> $values
 * @var array<string,string> $errors
 * @var array<string, array{label: string, mode: string}> $categories
 * @var bool $consentEnabled  o tema declara ter mecanismo de consentimento
 */
$isNew = $script === null;

// Checkbox primeiro, select só depois de marcar.
//
// O caso comum é script necessário — GTM num site sem banner, verificação de
// domínio, monitor de erro. Esse caso não deve exigir ler nada: a pessoa cola
// o código, dá um nome, salva. Um select sempre visível obrigaria toda
// criação a passar por uma decisão de consentimento, inclusive quando não há
// decisão nenhuma a tomar.
//
// Desmarcado = categoria vazia = sempre carrega. É o padrão porque script
// recém-criado precisa FUNCIONAR; o inverso faria alguém colar o GTM, salvar,
// e não ver nada acontecer, sem erro em lugar nenhum.
$requiresConsent = (string) $values['category'] !== '';

$consentOptions = [];
if ($categories === []) {
    // Sem categorias no site.json o consentimento existe, mas é geral: o
    // banner aprova ou recusa tudo de uma vez. Estas duas chaves implícitas
    // guardam só o padrão de cada script antes de alguém responder.
    $consentOptions['_optional_on'] = __('scripts.category_optional_on');
    $consentOptions['_optional_off'] = __('scripts.category_optional_off');
} else {
    foreach ($categories as $key => $def) {
        $suffix = match ($def['mode']) {
            'required' => __('scripts.mode_required'),
            'opt_out' => __('scripts.mode_opt_out'),
            default => __('scripts.mode_opt_in'),
        };
        $consentOptions[$key] = $def['label'] . ' — ' . $suffix;
    }
}
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= $isNew ? __('scripts.new') : e($script->label) ?></h1>
        <p class="page-header__subtitle"><?= $isNew ? __('scripts.new_subtitle') : __('scripts.edit_subtitle') ?></p>
    </div>
    <div class="page-header__actions">
        <a class="button button--ghost" href="<?= e(admin_url('scripts')) ?>"><?= __('scripts.back') ?></a>
    </div>
</div>

<div class="form">
    <form method="post">
        <?= csrf_field() ?>

        <div class="form-row">
            <div class="field" style="flex:2">
                <label class="field__label" for="label"><?= __('scripts.field_label') ?> <span class="field__required">*</span></label>
                <div class="field__control">
                    <input class="input" type="text" name="label" id="label" value="<?= e((string) $values['label']) ?>" placeholder="<?= __('scripts.field_label_placeholder') ?>">
                </div>
                <?php if (isset($errors['label'])): ?><p class="field__error"><?= e($errors['label']) ?></p><?php endif; ?>
                <p class="field__help"><?= __('scripts.field_label_help') ?></p>
            </div>
            <div class="field" style="flex:1">
                <label class="field__label" for="location"><?= __('scripts.field_location') ?></label>
                <div class="field__control">
                    <?php
                    // Chaves LITERAIS: o i18n:scan-admin só enxerga __() com
                    // string literal, e uma chave montada por concatenação
                    // pode sumir de um catálogo sem o lint acusar.
                    $locationLabels = [
                        'head' => __('scripts.location_head'),
                        'body_start' => __('scripts.location_body_start'),
                        'body_end' => __('scripts.location_body_end'),
                    ];
                    ?>
                    <select class="input input--select" name="location" id="location">
                        <?php foreach ($locationLabels as $loc => $locLabel): ?>
                            <option value="<?= e($loc) ?>" <?= $values['location'] === $loc ? 'selected' : '' ?>>
                                <?= e($locLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if (isset($errors['location'])): ?><p class="field__error"><?= e($errors['location']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="field">
            <div class="field__control">
                <label class="boolean-field">
                    <input type="checkbox" name="enabled" value="1" <?= !empty($values['enabled']) ? 'checked' : '' ?>>
                    <span><?= __('scripts.field_enabled') ?></span>
                </label>
            </div>
            <p class="field__help"><?= __('scripts.field_enabled_help') ?></p>
        </div>

        <?php
        // A área inteira de consentimento só existe se o TEMA disser que tem
        // mecanismo. Num tema sem banner, oferecer "exige consentimento"
        // convida a marcar um script que nunca vai carregar — nada poderia
        // aceitá-lo, e nada na tela explicaria o silêncio.
        ?>
        <?php if ($consentEnabled): ?>
        <div class="field">
            <div class="field__control">
                <label class="boolean-field">
                    <input type="checkbox" name="requires_consent" value="1"
                           data-reveal-target="#consent-category"
                           <?= $requiresConsent ? 'checked' : '' ?>>
                    <span><?= __('scripts.requires_consent') ?></span>
                </label>
            </div>
            <p class="field__help"><?= __('scripts.requires_consent_help') ?></p>
        </div>

        <div class="field" id="consent-category" <?= $requiresConsent ? '' : 'hidden' ?>>
            <label class="field__label" for="category"><?= __('scripts.field_category') ?></label>
            <div class="field__control">
                <select class="input input--select" name="category" id="category">
                    <?php foreach ($consentOptions as $key => $label): ?>
                        <option value="<?= e((string) $key) ?>" <?= (string) $values['category'] === (string) $key ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (isset($errors['category'])): ?><p class="field__error"><?= e($errors['category']) ?></p><?php endif; ?>
            <p class="field__help"><?= __('scripts.field_category_help') ?></p>
        </div>

        <?php endif; ?>

        <div class="field">
            <label class="field__label" for="code"><?= __('scripts.field_code') ?> <span class="field__required">*</span></label>
            <div class="field__control">
                <textarea class="input" name="code" id="code" rows="12" spellcheck="false"><?= e((string) $values['code']) ?></textarea>
            </div>
            <?php if (isset($errors['code'])): ?><p class="field__error"><?= e($errors['code']) ?></p><?php endif; ?>
            <p class="field__help"><?= __('scripts.field_code_help') ?></p>
        </div>

        <div class="form-actions">
            <button class="button" type="submit"><?= $isNew ? __('scripts.create') : __('scripts.save') ?></button>
            <a class="button button--ghost" href="<?= e(admin_url('scripts')) ?>"><?= __('scripts.cancel') ?></a>
        </div>
    </form>
</div>
