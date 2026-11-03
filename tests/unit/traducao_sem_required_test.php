<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * v3.7.4: `required` não sai nos painéis de tradução.
 *
 * O sintoma reportado por um admin de site real: o botão Salvar não fazia
 * nada. Nenhum erro na tela, nenhuma mensagem — só não salvava. No console
 * do navegador:
 *
 *   An invalid form control with name='fields_translations[en][cliente]'
 *   is not focusable.
 *
 * O painel do inglês fica escondido. Um `required` ali faz o navegador
 * recusar o submit e tentar focar um campo que a pessoa não vê. Ele desiste,
 * e a página fica parada — sem nada que explique o quê.
 *
 * É a mesma armadilha que `show_if` já resolvia. Faltava resolver para as
 * abas de idioma.
 *
 * E é contradição com o próprio modelo: `fieldIn()` cai no idioma padrão
 * quando não há tradução, e o controller só guarda o que o tradutor de fato
 * preencheu. Exigir tradução contraria o servidor, que a trata como opcional.
 */

if (!function_exists('tsr_defs')) {
    /** @return list<array<string,mixed>> */
    function tsr_defs(): array
    {
        return [
            ['name' => 'cliente', 'type' => 'text', 'label' => 'Cliente', 'required' => true],
            ['name' => 'resumo',  'type' => 'textarea', 'label' => 'Resumo'],
        ];
    }
}

test('idioma padrão mantém o required', function () {
    // A obrigatoriedade é real no idioma em que o conteúdo é escrito.
    $html = FieldRenderer::renderFields(tsr_defs(), [], 'fields');
    check(preg_match('/name="fields\[cliente\]"[^>]*required/', $html) === 1,
        'o campo obrigatório perdeu o required no idioma padrão');
});

test('painel de tradução não emite required', function () {
    $html = FieldRenderer::renderFields(tsr_defs(), [], 'fields_translations[en]');
    check(preg_match('/name="fields_translations\[en\]\[cliente\]"[^>]*required/', $html) === 0,
        'required vazou para o painel de tradução — o submit trava em silêncio');
});

test('vale para campo aninhado em aba', function () {
    // O caso que quebrou em produção: o campo estava dentro de um container
    // `tabs`, não solto. Os containers recursam por renderFields com o mesmo
    // prefixo — se um dia deixarem de recursar, este teste cai.
    $defs = [[
        'type' => 'tabs', 'name' => 'principal',
        'tabs' => [['label' => 'Topo', 'fields' => tsr_defs()]],
    ]];
    $html = FieldRenderer::renderFields($defs, [], 'fields_translations[es]');
    check(str_contains($html, 'fields_translations[es][cliente]'), 'o campo nem foi renderizado');
    check(preg_match('/name="fields_translations\[es\]\[cliente\]"[^>]*required/', $html) === 0,
        'required vazou num campo dentro de aba');
});

test('vale para campo aninhado em grupo', function () {
    $defs = [[
        'type' => 'group', 'name' => 'bloco', 'label' => 'Bloco',
        'fields' => tsr_defs(),
    ]];
    $html = FieldRenderer::renderFields($defs, [], 'fields_translations[en]');
    check(preg_match('/name="fields_translations\[en\]\[cliente\]"[^>]*required/', $html) === 0,
        'required vazou num campo dentro de grupo');
});

test('a detecção é pelo prefixo, que é o contrato do POST', function () {
    // Se alguém renomear o prefixo sem atualizar aqui, o bug volta calado.
    // Este teste existe para que a renomeação quebre em vermelho.
    $src = file_get_contents(dirname(__DIR__, 2) . '/core/FieldRenderer.php');
    check(str_contains($src, "str_starts_with(\$namePrefix, 'fields_translations[')"),
        'a checagem de prefixo sumiu ou mudou de forma');

    foreach (['items', 'pages', 'terms', 'options'] as $tela) {
        $glob = glob(dirname(__DIR__, 2) . "/core/Admin/views/{$tela}/edit.php");
        if ($glob === []) continue;
        $v = file_get_contents($glob[0]);
        if (!str_contains($v, 'fields_translations[')) continue;
        check(str_contains($v, "fields_translations[' . \$lang->code . ']")
              || str_contains($v, 'fields_translations[{$lang->code}]'),
            "{$tela}/edit.php usa um prefixo de tradução fora do formato esperado");
    }
});
