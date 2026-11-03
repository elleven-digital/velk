<?php
/**
 * @var array<string, list<\Velk\Models\TrackingScript>> $grouped
 * @var array<string, array{label: string, mode: string}> $categories  EFETIVAS
 * @var bool $consentEnabled  o tema declara ter mecanismo de consentimento
 * @var bool $signupSendEmail
 * @var string $signupSnippet
 * @var bool $membersEnabled
 */
$locationLabels = [
    'head' => __('scripts.location_head'),
    'body_start' => __('scripts.location_body_start'),
    'body_end' => __('scripts.location_body_end'),
];
$total = array_sum(array_map('count', $grouped));

/**
 * Como a categoria de um script aparece na coluna Consentimento.
 *
 * Necessário não mostra NADA: é o padrão de todo script, e repetir "sempre
 * carrega" em metade das linhas gasta atenção sem informar. A coluna passa a
 * ter conteúdo só onde há decisão de consentimento — que é onde o olho deve
 * parar.
 *
 * As categorias implícitas mostram só o modo. Elas dividem o rótulo
 * "Opcionais", que não diz nada ao operador; o que ele precisa saber é se
 * carrega por padrão ou não.
 */
$categoryCell = function (string $key) use ($categories): string {
    if ($key === '') {
        return '';
    }
    if (!isset($categories[$key])) {
        // Categoria removida do site.json: o script NÃO sai em lugar nenhum.
        // Precisa gritar, porque nada mais na tela denuncia.
        return '<span class="badge badge--danger">' . e($key) . ' — ' . __('scripts.category_missing') . '</span>';
    }

    $mode = $categories[$key]['mode'];
    $label = (string) $categories[$key]['label'];
    $implicita = $key === \Velk\Consent::IMPLICIT_ON || $key === \Velk\Consent::IMPLICIT_OFF;

    // `required` já significa "sempre carrega", igual a não ter categoria —
    // então só o nome, sem repetir o modo.
    if ($mode === 'required') {
        return e($label);
    }

    $padrao = $mode === 'opt_out'
        ? __('scripts.category_optional_on')
        : __('scripts.category_optional_off');

    return $implicita ? $padrao : e($label) . ' <span class="muted">— ' . $padrao . '</span>';
};
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= __('scripts.title') ?></h1>
        <p class="page-header__subtitle"><?= __('scripts.subtitle') ?></p>
    </div>
    <div class="page-header__actions">
        <a class="button" href="<?= e(admin_url('scripts/new')) ?>"><?= __('scripts.new') ?></a>
    </div>
</div>

<?php include __DIR__ . '/_csp_notice.php'; ?>

<?php if ($total === 0): ?>
    <div class="empty-state"><?= __('scripts.empty') ?></div>
<?php endif; ?>

<?php foreach ($grouped as $location => $scripts): ?>
    <?php if ($scripts === []) continue; ?>
    <div class="section-header">
        <h2 class="section-header__title"><?= e($locationLabels[$location] ?? $location) ?></h2>
        <span class="muted" style="font-size:12px"><?= __('scripts.drag_hint') ?></span>
    </div>

    <div class="table-scroll" style="margin-bottom:var(--s-5)"><table class="table">
        <thead>
            <tr>
                <th style="width:28px"></th>
                <th><?= __('scripts.col_name') ?></th>
                <?php if ($consentEnabled): ?><th><?= __('scripts.col_category') ?></th><?php endif; ?>
                <th><?= __('scripts.col_status') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody data-sortable-table
               data-sortable-endpoint="<?= e(admin_url('scripts/reorder')) ?>"
               data-csrf="<?= e(csrf_token()) ?>">
            <?php foreach ($scripts as $script): ?>
                <tr draggable="true" data-sortable-id="<?= (int) $script->id ?>">
                    <td>
                        <span class="sortable-handle" aria-hidden="true">
                            <svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor"><circle cx="2" cy="3" r="1"/><circle cx="2" cy="7" r="1"/><circle cx="2" cy="11" r="1"/><circle cx="8" cy="3" r="1"/><circle cx="8" cy="7" r="1"/><circle cx="8" cy="11" r="1"/></svg>
                        </span>
                    </td>
                    <td><a href="<?= e(admin_url('scripts/' . $script->id)) ?>"><?= e($script->label) ?></a></td>
                    <?php if ($consentEnabled): ?><td><?= $categoryCell($script->category) ?></td><?php endif; ?>
                    <td>
                        <?php if ($script->enabled): ?>
                            <span class="muted"><?= __('scripts.status_enabled') ?></span>
                        <?php else: ?>
                            <span class="badge"><?= __('scripts.status_disabled') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="table__actions">
                        <a class="button button--small button--ghost" href="<?= e(admin_url('scripts/' . $script->id)) ?>"><?= __('scripts.edit') ?></a>
                        <form method="post" action="<?= e(admin_url('scripts/' . $script->id . '/delete')) ?>" data-confirm="<?= __('scripts.confirm_delete') ?>" style="display:inline">
                            <?= csrf_field() ?>
                            <button class="button button--small button--danger" type="submit"><?= __('scripts.remove') ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endforeach; ?>

<?php if ($membersEnabled): ?>
    <?php
    // Mesmo acordeão que morava na página de opções, movido junto com os
    // scripts: o gestor de tráfego abre UMA tela e acha tudo de rastreamento.
    ?>
    <details class="field-group" data-field-group>
        <summary class="field-group__summary">
            <?= \Velk\FieldRenderer::chevron() ?>
            <span class="field-group__label"><?= __('scripts.conversions_title') ?></span>
        </summary>
        <div class="field-group__body">
            <form method="post" action="<?= e(admin_url('scripts/conversions')) ?>">
                <?= csrf_field() ?>
                <div class="field">
                    <div class="field__control">
                        <label class="boolean-field">
                            <input type="checkbox" name="signup_send_email" value="1" <?= $signupSendEmail ? 'checked' : '' ?>>
                            <span><?= __('scripts.signup_send_email') ?></span>
                        </label>
                    </div>
                    <p class="field__help"><?= __('scripts.signup_send_email_help') ?></p>
                </div>
                <div class="field">
                    <label class="field__label" for="signup_snippet"><?= __('scripts.signup_snippet') ?></label>
                    <div class="field__control">
                        <textarea class="input" name="signup_snippet" id="signup_snippet" rows="8" spellcheck="false"><?= e($signupSnippet) ?></textarea>
                    </div>
                    <p class="field__help"><?= __('scripts.signup_snippet_help', ['var' => 'velkSignupSuccess']) ?></p>
                </div>
                <button class="button" type="submit"><?= __('scripts.save') ?></button>
            </form>
        </div>
    </details>
<?php endif; ?>
