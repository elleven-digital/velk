<?php
/**
 * @var string $formId
 * @var array $form
 * @var list<\Velk\Models\FormRecipient> $recipients
 * @var list<\Velk\Models\FormSubmission> $submissions
 * @var array $fields
 * @var string|null $previewField  campo que rotula o cartão fechado
 *      (`forms.<id>.preview_field`); null cai no primeiro texto não-vazio
 * @var string|null $previewSubtitleField  campo da linha de baixo, antes da
 *      data (`forms.<id>.preview_subtitle_field`); null = só a data
 * @var bool   $hasSteps      o formulário declara `steps`
 * @var bool   $showCompleteness  oferecer o recorte de completude: declara
 *      `steps` OU já tem lead em andamento guardado (ver o controller)
 * @var string $completeness  '' (só completos) | 'all' | 'incomplete'
 * @var int    $stepCount     quantas etapas
 */
$label = (string) ($form['label'] ?? $formId);
// Mesmo default defensivo do $filters mais abaixo: null é um valor válido
// aqui (significa "usa a regra histórica"), então a ausência da chave não
// precisa virar warning.
$previewField = $previewField ?? null;
$previewSubtitleField = $previewSubtitleField ?? null;
$hasSteps     = $hasSteps ?? false;
$showCompleteness = $showCompleteness ?? $hasSteps;
$completeness = $completeness ?? '';
$stepCount    = $stepCount ?? 0;
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= e($label) ?></h1>
        <p class="page-header__subtitle"><?= __('forms.show_subtitle', ['id' => $formId]) ?></p>
    </div>
    <div class="page-header__actions">
        <a class="button button--ghost" href="<?= e(admin_url('forms')) ?>"><?= __('forms.back') ?></a>
    </div>
</div>

<div class="section-header">
    <h2 class="section-header__title"><?= __('forms.col_recipients') ?></h2>
</div>

<div class="form" style="max-width:none">
    <?php if (empty($recipients)): ?>
        <div class="flash flash--error"><?= __('forms.no_recipients') ?></div>
    <?php else: ?>
        <div class="table-scroll" style="margin-bottom:var(--s-4)"><table class="table">
            <thead>
                <tr><th><?= __('forms.field_email') ?></th><th><?= __('forms.col_name') ?></th><th><?= __('forms.col_registered_at') ?></th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($recipients as $r): ?>
                    <tr>
                        <td><?= e($r->email) ?></td>
                        <td class="muted"><?= e($r->name ?? '—') ?></td>
                        <td class="muted"><?= e(date('d/m/Y H:i', strtotime($r->createdAt))) ?></td>
                        <td class="table__actions">
                            <form method="post" action="<?= e(admin_url('forms/' . $formId . '/recipients/' . $r->id . '/delete')) ?>" data-confirm="<?= __('forms.confirm_remove_recipient') ?>" style="display:inline">
                                <?= csrf_field() ?>
                                <button class="button button--small button--danger" type="submit"><?= __('forms.remove') ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url('forms/' . $formId . '/recipients')) ?>">
        <?= csrf_field() ?>
        <div class="form-row">
            <div class="field" style="flex:2">
                <label class="field__label" for="email"><?= __('forms.field_email') ?> <span class="field__required">*</span></label>
                <div class="field__control">
                    <input class="input" type="email" name="email" id="email" required placeholder="<?= __('forms.email_placeholder') ?>">
                </div>
            </div>
            <div class="field" style="flex:1">
                <label class="field__label" for="name"><?= __('forms.field_name_optional') ?></label>
                <div class="field__control">
                    <input class="input" type="text" name="name" id="name" placeholder="<?= __('forms.name_placeholder') ?>">
                </div>
            </div>
            <div class="field form-row__action">
                <button class="button" type="submit"><?= __('forms.add') ?></button>
            </div>
        </div>
    </form>
</div>

<?php
// Colapsado por padrão: a maioria dos formulários nunca precisa mexer aqui
// (com GTM instalado o evento já dispara sozinho), então abrir por default
// só empurraria destinatários e preenchimentos pra baixo da dobra.
//
// Reusa `.field-group`, o mesmo acordeão que o FieldRenderer::renderGroup()
// emite — nenhum CSS novo, o que também evita o cache longo do admin.css. De
// graça vem o listener de `invalid` em admin.js: se um campo obrigatório
// aparecer aqui um dia, o grupo abre sozinho em vez de bloquear o submit sem
// feedback visível.
?>
<details class="field-group" data-field-group>
    <summary class="field-group__summary">
        <?= \Velk\FieldRenderer::chevron() ?>
        <span class="field-group__label"><?= __('forms.conversion_title') ?></span>
        <span class="muted form-summary-note"><?= __('forms.conversion_subtitle') ?></span>
    </summary>

    <div class="field-group__body">
    <form method="post" action="<?= e(admin_url('forms/' . $formId . '/conversion')) ?>">
        <?= csrf_field() ?>

        <p class="muted" style="font-size:13px;margin-top:0">
            <?= __('forms.conversion_intro', ['event' => 'velk_form_success']) ?>
        </p>

<?php
// Labels resolvidos com chave LITERAL, nunca via variável: o
// `bin/velk i18n:scan-admin` só enxerga chamadas com string literal, então
// uma chave montada em variável pode sumir de um catálogo sem o lint acusar.
$mapLabels = [
    'email'        => __('forms.conversion_map_email'),
    'phone_number' => __('forms.conversion_map_phone'),
];
?>
        <div class="form-row">
            <?php foreach ($mapLabels as $key => $label): ?>
                <div class="field" style="flex:1">
                    <label class="field__label" for="map_<?= e($key) ?>"><?= e($label) ?></label>
                    <div class="field__control">
                        <select class="input" name="mapping[<?= e($key) ?>]" id="map_<?= e($key) ?>">
                            <option value=""><?= __('forms.conversion_map_none') ?></option>
                            <?php foreach ($fields as $f): ?>
                                <?php $fname = (string) ($f['name'] ?? ''); if ($fname === '') continue; ?>
                                <option value="<?= e($fname) ?>" <?= ($settings->userDataFields[$key] ?? '') === $fname ? 'selected' : '' ?>>
                                    <?= e((string) ($f['label'] ?? $fname)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <p class="muted" style="font-size:12px">
            <?= __('forms.conversion_map_help') ?>
        </p>

        <div class="field">
            <label class="field__label" for="conversion_snippet"><?= __('forms.conversion_snippet_label') ?></label>
            <div class="field__control">
                <textarea class="input" name="conversion_snippet" id="conversion_snippet" rows="8"
                          spellcheck="false"><?= e((string) ($settings->conversionSnippet ?? '')) ?></textarea>
            </div>
            <p class="muted" style="font-size:12px">
                <?= __('forms.conversion_snippet_help', ['var' => 'velkFormSuccess']) ?>
            </p>
        </div>

        <button class="button" type="submit"><?= __('forms.conversion_save') ?></button>
    </form>
    </div>
</details>

<?php
// Duas listas, um cabeçalho. `$isSpamView` decide qual título, qual
// contagem e para onde o link leva — em vez de duplicar a tela inteira só
// pra trocar três textos.
$isSpamView   = $isSpamView ?? false;
$spamCount    = $spamCount ?? 0;
$showSpamLink = $showSpamLink ?? false;
$listUrl      = admin_url('forms/' . $formId);
?>
<div class="section-header">
    <h2 class="section-header__title">
        <?= $isSpamView ? __('forms.spam_title') : __('forms.col_submissions') ?>
    </h2>
    <div style="display:flex;align-items:center;gap:var(--s-3)">
        <span class="muted" style="font-size:12px"><?= $submissionsTotal === 1 ? __('forms.submissions_count_one') : __('forms.submissions_count_other', ['count' => $submissionsTotal]) ?><?= ($hasActiveFilter ?? false) ? __('forms.filtered_suffix') : '' ?></span>
        <?php if ($isSpamView): ?>
            <a class="button button--small" href="<?= e($listUrl) ?>"><?= __('forms.spam_back') ?></a>
        <?php elseif ($showSpamLink): ?>
            <a class="form-spam-link" href="<?= e($listUrl . '?spam=1') ?>">
                <?= __('forms.spam_link') ?><?= $spamCount > 0 ? ' (' . $spamCount . ')' : '' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isSpamView): ?>
    <p class="muted" style="font-size:12.5px;margin:0 0 var(--s-4)"><?= __('forms.spam_intro') ?></p>
<?php endif; ?>

<?php
// Só filtro de data. Os filtros de UTM saíram na v4: UTM é insumo de
// ferramenta de análise — quem monta link com utm_campaign olha no GA4 ou no
// Ads, não numa lista de 20 preenchimentos. Os parâmetros continuam visíveis
// no detalhe de cada submissão; o que saiu foi a dimensão de consulta, e com
// ela a razão de as UTM serem colunas.
$filters = $filters ?? ['date_from' => '', 'date_to' => ''];
?>
<form method="get" action="<?= e(admin_url('forms/' . $formId)) ?>" class="form-filters">
    <?php if ($isSpamView): ?>
        <?php // Sem isto, filtrar por data dentro do spam jogava de volta na
              // lista de leads — o recorte se perdia no submit do GET. ?>
        <input type="hidden" name="spam" value="1">
    <?php endif; ?>
    <div class="form-filters__row">
        <div class="form-filters__field">
            <label for="filter_date_from"><?= __('forms.filter_from') ?></label>
            <input class="input" type="date" name="date_from" id="filter_date_from" value="<?= e($filters['date_from']) ?>">
        </div>
        <div class="form-filters__field">
            <label for="filter_date_to"><?= __('forms.filter_to') ?></label>
            <input class="input" type="date" name="date_to" id="filter_date_to" value="<?= e($filters['date_to']) ?>">
        </div>

        <?php if ($showCompleteness && !$isSpamView): ?>
            <?php // Só quando há o que recortar: num formulário que nunca teve
                  // etapas nenhuma linha é incompleta, e um filtro que nunca
                  // filtra nada é ruído. Note `$showCompleteness`, e não
                  // `$hasSteps`: um `steps` que parou de parsear não pode
                  // levar embora o acesso ao lead já coletado — ver o
                  // controller.
                  // E nunca no painel de spam, onde o recorte é 'all' fixo:
                  // as três opções devolveriam o mesmo conjunto, e um seletor
                  // que não seleciona nada é ruído, não filtro. ?>
            <label class="form-filters__field">
                <span><?= __('forms.completeness_label') ?></span>
                <select name="completeness" data-autosubmit>
                    <option value=""<?= $completeness === '' ? ' selected' : '' ?>><?= __('forms.completeness_complete') ?></option>
                    <option value="all"<?= $completeness === 'all' ? ' selected' : '' ?>><?= __('forms.completeness_all') ?></option>
                    <option value="incomplete"<?= $completeness === 'incomplete' ? ' selected' : '' ?>><?= __('forms.completeness_incomplete') ?></option>
                </select>
            </label>
        <?php endif; ?>

        <div class="form-filters__actions">
            <?php // `.button` cheio, não `--small`: a linha alinha por baixo com os
                  // campos de data, e um botão mais baixo que os inputs ao lado
                  // fica visivelmente desencontrado. Mesma altura do "Adicionar". ?>
            <button class="button" type="submit"><?= __('forms.filter_submit') ?></button>
            <?php if ($hasActiveFilter ?? false): ?>
                <?php // `completeness=all` explícito: a URL nua reaplica o default
                      // "só concluídos", e num formulário com etapas o "Limpar"
                      // recarregaria o mesmo "0 registros · filtrado" que o
                      // visitante clicou para sair. Limpar tem que mostrar tudo.
                      $clearUrl = $listUrl . ($isSpamView ? '?spam=1' : '');
                      if ($showCompleteness && !$isSpamView) {
                          $clearUrl .= '?completeness=all';
                      } ?>
                <a class="form-filters__clear" href="<?= e($clearUrl) ?>"><?= __('items.clear') ?></a>
            <?php endif; ?>
        </div>
    </div>
</form>

<style>
.form-filters { margin-bottom: var(--s-4); }
.form-filters__row {
    display: flex;
    flex-wrap: wrap;
    gap: var(--s-3);
    align-items: end;
}
.form-filters__field { display: flex; flex-direction: column; gap: 4px; min-width: 140px; flex: 1 1 140px; }
.form-filters__field label {
    font-size: 10px;
    font-weight: 500;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--ink-4);
}
.form-filters__actions { display: flex; gap: var(--s-2); align-items: center; }
/* O texto à direita do título dos blocos recolhíveis (Conversão, Exportar). */
.form-summary-note {
    margin-left: auto;
    font-size: 12px;
    text-transform: none;
    letter-spacing: 0;
    font-weight: 400;
}
/* No celular, um campo embaixo do outro: lado a lado, o "Até" dividia a
   linha com o botão. `flex: none` porque em coluna o 140px da base viraria
   altura. E o texto ao lado do título dos blocos some: não cabe e quebrava o
   título em duas linhas. */
@media (max-width: 699px) {
    .form-filters__row { flex-direction: column; align-items: stretch; }
    .form-filters__field { flex: none; min-width: 0; }
    .form-summary-note { display: none; }
}
.form-filters__clear {
    font-size: 12px;
    color: var(--ink-3);
    text-decoration: none;
}
.form-filters__clear:hover { color: var(--danger, #c4292b); }
.form-spam-link {
    font-size: 12px;
    color: var(--ink-3);
    text-decoration: none;
    border-bottom: 1px solid var(--line);
}
.form-spam-link:hover { color: var(--ink-1); border-bottom-color: currentColor; }
.form-spam-tag {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 3px;
    background: var(--surface-2, rgba(0,0,0,.06));
    font-size: 11px;
    letter-spacing: 0.02em;
}
.form-card__meta { font-size: 12px; margin-top: 2px; }
.form-step-tag {
    display: inline-block; padding: 1px 6px; border-radius: 3px;
    background: var(--surface-2, rgba(0,0,0,.06)); color: var(--ink-3);
    font-size: 11px; font-weight: 500; letter-spacing: .01em;
}
/* Colunas do Exportar: numa fileira de dez, a pílula preenchida do
   .boolean-field pesa — a marcada fica só com o check e a borda escura. */
.form-export-cols { display: flex; flex-wrap: wrap; gap: var(--s-2); }
.form-export-cols .boolean-field:has(input:checked) {
    background: var(--surface);
    color: var(--ink);
    font-weight: inherit;
}
.form-export-cols .boolean-field:has(input:checked) input { accent-color: var(--ink); }
</style>

<?php if (!$isSpamView): ?>
<?php
// Exportar em CSV. Só leads reais (aprovados e completos): o recorte da tela
// não vale aqui, e spam e lead em andamento ficam só na tela — por isso o
// bloco nem aparece no painel de spam.
//
// GET, como o download de anexo. O `p=1` escondido separa "desmarquei tudo"
// (erro) de "link nu" (tudo): os dois chegam sem `cols`, porque checkbox
// desmarcado não é enviado — ver FormController::export().
//
// Os checkboxes saem de FormCsv::columns(), a mesma lista que monta o
// cabeçalho do arquivo. A legenda fica FORA das pílulas: marcada, a pílula
// inverte as cores, e texto `muted` dentro dela sumiria.
$exportColumns = $exportColumns ?? [];
$exportFields = array_filter($exportColumns, fn(array $c): bool => $c['group'] === 'field');
$exportMetaLabels = array_column(array_filter($exportColumns, fn(array $c): bool => $c['group'] === 'meta'), 'label');
$exportParamsCount = count(array_filter($exportColumns, fn(array $c): bool => $c['group'] === 'params'));
?>
<details class="field-group" data-field-group>
    <summary class="field-group__summary">
        <?= \Velk\FieldRenderer::chevron() ?>
        <span class="field-group__label"><?= __('forms.export_title') ?></span>
        <span class="muted form-summary-note"><?= __('forms.export_subtitle') ?></span>
    </summary>

    <div class="field-group__body">
    <form method="get" action="<?= e(admin_url('forms/' . $formId . '/export')) ?>">
        <input type="hidden" name="p" value="1">

        <div class="field">
            <label class="field__label"><?= __('forms.export_columns') ?></label>
            <div class="form-export-cols">
                <?php foreach ($exportFields as $col): ?>
                    <label class="boolean-field">
                        <input type="checkbox" name="cols[]" value="<?= e($col['key']) ?>" checked>
                        <span><?= e($col['label']) ?></span>
                    </label>
                <?php endforeach; ?>
                <label class="boolean-field">
                    <input type="checkbox" name="cols[]" value="<?= e(\Velk\Services\FormCsv::GROUP_META) ?>">
                    <span><?= __('forms.export_group_meta') ?></span>
                </label>
                <label class="boolean-field">
                    <input type="checkbox" name="cols[]" value="<?= e(\Velk\Services\FormCsv::GROUP_PARAMS) ?>">
                    <span><?= __('forms.export_group_params') ?></span>
                </label>
            </div>
            <p class="field__help"><?= __('forms.export_columns_help', [
                'meta'  => implode(', ', $exportMetaLabels),
                'count' => $exportParamsCount,
            ]) ?></p>
        </div>

        <div class="form-filters__row">
            <div class="form-filters__field">
                <label for="export_date_from"><?= __('forms.export_from') ?></label>
                <input class="input" type="date" name="date_from" id="export_date_from">
            </div>
            <div class="form-filters__field">
                <label for="export_date_to"><?= __('forms.export_to') ?></label>
                <input class="input" type="date" name="date_to" id="export_date_to">
            </div>
            <div class="form-filters__field">
                <label for="export_sep"><?= __('forms.export_separator') ?></label>
                <select class="input input--select" name="sep" id="export_sep">
                    <option value=";" selected><?= __('forms.export_sep_semicolon') ?></option>
                    <option value=","><?= __('forms.export_sep_comma') ?></option>
                </select>
            </div>
            <div class="form-filters__actions">
                <button class="button" type="submit"><?= __('forms.export_submit') ?></button>
            </div>
        </div>
    </form>
    </div>
</details>
<?php endif; ?>

<?php if (empty($submissions)): ?>
    <div class="empty">
        <?php if ($hasActiveFilter ?? false): ?>
            <?= __('forms.empty_filtered') ?>
        <?php elseif ($isSpamView): ?>
            <?= __('forms.spam_empty') ?>
        <?php else: ?>
            <?= __('forms.empty_submissions') ?>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="display:flex;flex-direction:column;gap:var(--s-3)">
        <?php foreach ($submissions as $s): ?>
            <details class="card" style="display:block;padding:0">
                <summary style="display:flex;justify-content:space-between;align-items:center;padding:var(--s-3) var(--s-5);cursor:pointer;list-style:none">
                    <div>
                        <div style="font-weight:500;font-size:14px">
                            <?= e(\Velk\Models\FormSubmission::previewFrom($s->data, $previewField) ?: '#' . $s->id) ?>
                        </div>
                        <div class="muted form-card__meta">
                            <?php $subtitle = \Velk\Models\FormSubmission::subtitleFrom($s->data, $previewSubtitleField); ?>
                            <?php if ($subtitle !== ''): ?><?= e($subtitle) ?> · <?php endif; ?>
                            <?= e(date('d/m/Y H:i', strtotime($s->createdAt))) ?>
                            <?php if ($s->blockedBy !== null): ?>
                                · <span class="form-spam-tag"><?= e($s->blockedBy) ?></span>
                            <?php endif; ?>
                            <?php if ($s->pendingStep !== null): ?>
                                <?php // Lead em andamento: a posição diz quanto
                                      // dele existe, e é o que decide se vale
                                      // ligar para a pessoa. ?>
                                <?php // Sem e() por fora: __() já passa os params por
                                      // htmlspecialchars (ver o docblock de helpers.php) —
                                      // embrulhar de novo escaparia em dobro. Inofensivo hoje
                                      // (step/total são int), mas quebra a convenção do
                                      // resto deste arquivo. ?>
                                <?php // Sem total não há "de N": com o `steps` fora do ar
                                      // (removido de propósito ou com erro de digitação) o
                                      // `stepCount` é 0, e "etapa 2 de 0" não informa nada. A
                                      // linha continua marcada como incompleta pelo filtro e
                                      // pelo "Aguardando conclusão" do cartão aberto. ?>
                                <?php if ($stepCount > 0): ?>
                                    · <span class="form-step-tag"><?= __('forms.step_of', [
                                        'step' => (string) $s->pendingStep,
                                        'total' => (string) $stepCount,
                                    ]) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?= \Velk\FieldRenderer::chevron() ?>
                </summary>

                <div style="padding:0 var(--s-5) var(--s-5);border-top:1px solid var(--line)">
                    <div class="table-scroll"><table class="table" style="border:0;border-radius:0;margin-top:var(--s-3);background:transparent">
                        <tbody>
                            <?php foreach ($fields as $field):
                                $name = (string) ($field['name'] ?? '');
                                if ($name === '') continue;
                                $value = $s->data[$name] ?? null;
                                // Campo de arquivo: valor é um array de metadados
                                // (marcador `_file`), não uma string — precisa de um
                                // branch próprio pra não virar "Array to string
                                // conversion" no (string) cast do else abaixo.
                                $isFile = is_array($value) && !empty($value['_file']);
                            ?>
                                <tr>
                                    <td style="width:160px;color:var(--fg-muted);vertical-align:top"><?= e((string)($field['label'] ?? $name)) ?></td>
                                    <td style="white-space:pre-wrap;word-break:break-word">
                                        <?php if ($isFile): ?>
                                            <a href="<?= e(admin_url('forms/' . $formId . '/submissions/' . $s->id . '/file/' . $name)) ?>"><?= e((string) ($value['name'] ?? $name)) ?></a>
                                            <span class="muted" style="font-size:11px">· <?= e(human_filesize((int) ($value['size'] ?? 0))) ?></span>
                                        <?php elseif (is_array($value)): ?>
                                            <?php /* forma inesperada (sem marcador _file) — nunca deveria acontecer hoje; evita o warning sem quebrar a linha */ ?>
                                            <span class="muted">—</span>
                                        <?php else: ?>
                                            <?= e((string) ($value ?? '')) ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if ($s->referer): ?>
                                <tr><td style="color:var(--fg-subtle)"><?= __('forms.field_referer') ?></td><td style="font-family:var(--font-mono);font-size:11px;word-break:break-all"><?= e($s->referer) ?></td></tr>
                            <?php endif; ?>

                            <?php
                            // Parâmetros de rastreamento — UTM e click IDs, na
                            // ordem em que a whitelist de Velk\Params os define,
                            // e só as chaves capturadas. Uma seção só porque é um
                            // conceito só: de onde veio esta visita.
                            //
                            // Sem filtro no painel, de propósito. UTM é insumo de
                            // ferramenta de análise (GA4, Ads) e um click ID é
                            // único por clique, então agrupar por qualquer um
                            // deles aqui não significa nada.
                            $paramRows = $s->params;
                            ?>
                            <?php if ($paramRows !== []): ?>
                                <tr><td colspan="2" style="padding-top:var(--s-3);border-top:1px solid var(--line);color:var(--ink-3);font-size:11.5px;text-transform:uppercase;letter-spacing:0.06em;font-weight:500"><?= __('forms.params_section') ?></td></tr>
                                <?php foreach ($paramRows as $key => $val): ?>
                                    <tr>
                                        <td style="color:var(--fg-subtle)"><?= e($key) ?></td>
                                        <td style="font-family:var(--font-mono);font-size:11px;word-break:break-all"><?= e((string) $val) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if ($s->pendingStep !== null): ?>
                                <?php // `email_status` NULL aqui não é falha de
                                      // envio: o e-mail sai no envio final, e
                                      // este lead não chegou lá. ?>
                                <tr><td colspan="2"><span class="muted" style="font-size:12px"><?= __('forms.email_pending') ?></span></td></tr>
                            <?php else: ?>
                                <?php if ($s->sentTo): ?>
                                    <tr><td style="color:var(--fg-subtle)"><?= __('forms.field_sent_to') ?></td><td style="font-family:var(--font-mono);font-size:11px"><?= e($s->sentTo) ?></td></tr>
                                <?php endif; ?>
                                <?php if ($s->emailError): ?>
                                    <tr><td style="color:var(--fg-subtle)"><?= __('forms.field_send_error') ?></td><td style="font-family:var(--font-mono);font-size:11px;color:var(--danger)"><?= e($s->emailError) ?></td></tr>
                                <?php endif; ?>
                            <?php endif; ?>
                        </tbody>
                    </table></div>

                    <div style="display:flex;gap:var(--s-2);margin-top:var(--s-4);justify-content:flex-end">
                        <?php if ($s->blockedBy !== null): ?>
                            <?php // O caminho de volta do falso positivo. Só existe no que
                                  // foi bloqueado — num preenchimento normal não faria sentido. ?>
                            <form method="post" action="<?= e(admin_url('forms/' . $formId . '/submissions/' . $s->id . '/not-spam')) ?>" style="margin:0">
                                <?= csrf_field() ?>
                                <button class="button button--small" type="submit"><?= __('forms.not_spam') ?></button>
                            </form>
                        <?php elseif ($canMarkSpam ?? false): ?>
                            <?php // O caminho de ida. Sem data-confirm de propósito: é
                                  // desfazível em um clique pelo botão acima, e pedir
                                  // confirmação para algo reversível é atrito à toa. ?>
                            <form method="post" action="<?= e(admin_url('forms/' . $formId . '/submissions/' . $s->id . '/spam')) ?>" style="margin:0;display:flex;align-items:center">
                                <?= csrf_field() ?>
                                <button class="form-spam-link" type="submit"><?= __('forms.mark_spam') ?></button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="<?= e(admin_url('forms/' . $formId . '/submissions/' . $s->id . '/delete')) ?>" data-confirm="<?= __('forms.confirm_delete_submission') ?>" style="margin:0">
                            <?= csrf_field() ?>
                            <button class="button button--small button--danger" type="submit"><?= __('common.delete') ?></button>
                        </form>
                    </div>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
$page = $pg['page'];
$totalPages = $pg['totalPages'];
$query = $pgQuery;
include __DIR__ . '/../layout/pagination.php';
?>
