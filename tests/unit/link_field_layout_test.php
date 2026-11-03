<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * Estrutura do campo `link`.
 *
 * Os três controles (URL, título, "abrir em nova aba") são UM campo composto.
 * Empilhados soltos eles se liam como três campos independentes, e o rótulo
 * do campo ("Botão de CTA") ficava órfão, longe do que descrevia.
 *
 * A correção é metade CSS (a caixa) e metade markup: o checkbox precisa estar
 * DENTRO do mesmo `.link-field__row` da URL, senão nenhuma regra de CSS o
 * coloca na mesma linha. Como a aparência não quebra teste nenhum, esta
 * guarda existe pra que uma refatoração de markup não desfaça isso em
 * silêncio.
 */

// Os rótulos do campo passam por __(), que resolve via App::instance()
// ->adminI18n. Mesmo idiom leve do field_file_test.php: Config real (só lê
// config/env.php + theme/site.json, não toca o banco) e os catálogos de
// verdade, pra exercitar o markup real em vez de um stub.
$app = new \Velk\App(new \Velk\Config(dirname(__DIR__, 2)));
$app->adminI18n = new \Velk\Admin\I18n(dirname(__DIR__, 2) . '/core/Admin/lang', 'en', 'en');

if (!function_exists('lf_render')) {
    function lf_render(mixed $value = null): string
    {
        return FieldRenderer::render(
            ['name' => 'cta', 'type' => 'link', 'label' => 'Botão de CTA'],
            $value
        );
    }
}

test('URL e "nova aba" moram na mesma linha', function () {
    $html = lf_render(['url' => '/contato', 'title' => 'Fale conosco']);

    // Recorta o conteúdo de .link-field__row e confere que os DOIS estão lá.
    $start = strpos($html, 'link-field__row');
    check($start !== false, 'o markup deveria ter uma .link-field__row');

    // A linha termina onde começa o sub do título, que é irmão da row.
    $rest = substr($html, $start);
    $end = strpos($rest, 'link-field__sub--aside');
    check($end !== false, 'o checkbox deveria estar dentro da row, marcado como --aside');

    // A URL vem antes do checkbox dentro da mesma row.
    $posUrl = strpos($rest, 'name="fields[cta][url]"');
    $posChk = strpos($rest, 'name="fields[cta][new_tab]"');
    check($posUrl !== false && $posChk !== false, 'url e new_tab deveriam estar no markup');
    check($posUrl < $posChk, 'a URL deveria vir antes do checkbox');

    // E o título fica FORA da row — é texto livre, usa a largura toda.
    $posTitulo = strpos($rest, 'name="fields[cta][title]"');
    check($posTitulo > $posChk, 'o título deveria vir depois da row do URL+checkbox');
});

test('os três controles continuam sob o mesmo name composto', function () {
    // O layout mudou; o contrato do POST não pode ter mudado junto, ou
    // collectLink() para de reconhecer o sub-array.
    $html = lf_render(['url' => '/x', 'title' => 'T', 'new_tab' => true]);

    foreach (['url', 'title', 'new_tab'] as $sub) {
        check(
            str_contains($html, 'name="fields[cta][' . $sub . ']"'),
            "faltou o sub-input {$sub} sob o name composto"
        );
    }
});

test('a caixa unificada envolve tudo', function () {
    $html = lf_render(['url' => '/x']);
    check(str_contains($html, 'class="link-field"'), 'faltou o wrapper .link-field (a caixa)');
    // A URL cresce, o checkbox não — é o que mantém os dois na mesma linha
    // sem espremer o input.
    check(str_contains($html, 'link-field__sub--grow'), 'a URL deveria ser o sub que cresce');
});

test('valor vazio ou malformado não quebra o render', function () {
    foreach ([null, [], 'string', 42, ['url' => null]] as $i => $v) {
        try {
            $html = lf_render($v);
            check(str_contains($html, 'link-field'), "caso #{$i} deveria renderizar a caixa");
        } catch (\Throwable $e) {
            check(false, "caso #{$i} lançou: " . $e->getMessage());
        }
    }
});
