<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * FieldRenderer::translationOverrides — reduz o bundle coletado de um
 * idioma às traduções que o editor de fato mexeu, para o fallback de
 * fieldIn() ficar limpo. Regra conservadora:
 *   - descarta valores "vazios" (type-aware: image/file "0" ≠ text "0");
 *   - para boolean (que nunca é "vazio") descarta quando IGUAL ao default;
 *   - demais tipos preenchidos são mantidos mesmo se iguais ao default.
 */
if (!function_exists('tov')) {
    function tov(array $defs, array $translated, array $default): array
    {
        return FieldRenderer::translationOverrides($defs, $translated, $default);
    }
}

$bool  = [['name' => 'featured', 'type' => 'boolean']];
$text  = [['name' => 'subtitle', 'type' => 'text']];
$image = [['name' => 'hero', 'type' => 'image']];
$num   = [['name' => 'qty', 'type' => 'number']];
$gal   = [['name' => 'shots', 'type' => 'gallery']];
$itm   = [['name' => 'ref', 'type' => 'item', 'item_type' => 'post']];
$grpBool = [['type' => 'group', 'label' => 'G', 'fields' => [['name' => 'featured', 'type' => 'boolean']]]];
$tabBool = [['type' => 'tabs', 'tabs' => [['label' => 'A', 'fields' => [['name' => 'featured', 'type' => 'boolean']]]]]];

test('boolean: false igual ao default é descartado (fim da poluição)', function () use ($bool) {
    check_same([], tov($bool, ['featured' => false], ['featured' => false]));
});

test('boolean: false quando default é true é mantido (override real)', function () use ($bool) {
    check_same(['featured' => false], tov($bool, ['featured' => false], ['featured' => true]));
});

test('boolean: true quando default é false é mantido (override real)', function () use ($bool) {
    check_same(['featured' => true], tov($bool, ['featured' => true], ['featured' => false]));
});

test('boolean: true igual ao default é descartado (redundante)', function () use ($bool) {
    check_same([], tov($bool, ['featured' => true], ['featured' => true]));
});

test('text: vazio é descartado (cai no fallback)', function () use ($text) {
    check_same([], tov($text, ['subtitle' => ''], ['subtitle' => 'Olá']));
});

test('text: preenchido e diferente é mantido', function () use ($text) {
    check_same(['subtitle' => 'Hello'], tov($text, ['subtitle' => 'Hello'], ['subtitle' => 'Olá']));
});

test('text (conservador): igual ao default é MANTIDO', function () use ($text) {
    check_same(['subtitle' => 'Olá'], tov($text, ['subtitle' => 'Olá'], ['subtitle' => 'Olá']));
});

test('text: string "0" é conteúdo válido, não é "vazio de image"', function () use ($text) {
    check_same(['subtitle' => '0'], tov($text, ['subtitle' => '0'], ['subtitle' => '']));
});

test('image: "0" (sem mídia) é descartado mesmo com default preenchido', function () use ($image) {
    check_same([], tov($image, ['hero' => '0'], ['hero' => '42']));
});

test('image: id válido diferente é mantido', function () use ($image) {
    check_same(['hero' => '42'], tov($image, ['hero' => '42'], ['hero' => '0']));
});

test('number: null (vazio) é descartado', function () use ($num) {
    check_same([], tov($num, ['qty' => null], ['qty' => 5]));
});

test('number: 0 é valor real e é mantido', function () use ($num) {
    check_same(['qty' => 0], tov($num, ['qty' => 0], ['qty' => 5]));
});

test('gallery: [] é descartado', function () use ($gal) {
    check_same([], tov($gal, ['shots' => []], ['shots' => [1]]));
});

test('gallery: lista não-vazia é mantida', function () use ($gal) {
    check_same(['shots' => [2]], tov($gal, ['shots' => [2]], ['shots' => [1]]));
});

test('item: null é descartado', function () use ($itm) {
    check_same([], tov($itm, ['ref' => null], ['ref' => 3]));
});

test('boolean em group: tipo é resolvido, false==default descartado', function () use ($grpBool) {
    check_same([], tov($grpBool, ['featured' => false], ['featured' => false]));
});

test('boolean em tabs: tipo é resolvido, false != default é mantido', function () use ($tabBool) {
    check_same(['featured' => false], tov($tabBool, ['featured' => false], ['featured' => true]));
});

test('bundle sem overrides significativos vira []', function () {
    $defs = [['name' => 'featured', 'type' => 'boolean'], ['name' => 'subtitle', 'type' => 'text']];
    check_same([], tov($defs, ['featured' => false, 'subtitle' => ''], ['featured' => false, 'subtitle' => 'Olá']));
});

test('e2e collect+overrides: boolean não-marcado não polui, texto preenchido é mantido', function () {
    $defs = [['name' => 'featured', 'type' => 'boolean'], ['name' => 'subtitle', 'type' => 'text']];
    // Idioma padrão: featured não marcado (false), subtitle "Olá".
    $default = FieldRenderer::collect($defs, ['subtitle' => 'Olá']);
    // Tradução: só o subtitle preenchido; o checkbox boolean não vem no POST.
    $translated = FieldRenderer::collect($defs, ['subtitle' => 'Hi']);
    // featured false==false some; subtitle real fica.
    check_same(['subtitle' => 'Hi'], FieldRenderer::translationOverrides($defs, $translated, $default));
});

// O editor de texto do painel nunca envia '': sem nada escrito, ele envia
// `<p></p>`. Esse é o "vazio" do richtext, e não pode virar tradução.
$tovRich = [['name' => 'content', 'type' => 'richtext']];
$tovCorpo = ['content' => '<p>Corpo no idioma padrão.</p>'];

test('richtext: <p></p> (editor sem nada escrito) é descartado', function () use ($tovRich, $tovCorpo) {
    check_same([], tov($tovRich, ['content' => '<p></p>'], $tovCorpo));
});

test('richtext: só tags de texto vazias também é descartado', function () use ($tovRich, $tovCorpo) {
    foreach (['<p></p><p></p>', '<p><br></p>', '<h2></h2>', '<p> </p>', '<ul><li><p></p></li></ul>'] as $html) {
        check_same([], tov($tovRich, ['content' => $html], $tovCorpo), $html);
    }
});

test('richtext: com texto é mantido', function () use ($tovRich, $tovCorpo) {
    check_same(['content' => '<p>Body.</p>'], tov($tovRich, ['content' => '<p>Body.</p>'], $tovCorpo));
});

test('richtext: só imagem, tabela, linha ou bloco do tema é mantido', function () use ($tovRich, $tovCorpo) {
    $soMidia = [
        '<img src="/storage/uploads/a.jpg" alt="">',
        '<figure><img src="/storage/uploads/a.jpg" alt=""><figcaption>Legenda</figcaption></figure>',
        '<table><tbody><tr><th><p></p></th></tr><tr><td><p></p></td></tr></tbody></table>',
        '<hr>',
        '<div data-velk-block="bignumbers" data-velk-data="{&quot;items&quot;:[]}"></div>',
        '<p></p><div data-velk-block="bignumbers" data-velk-data="{}"></div>',
    ];
    foreach ($soMidia as $html) {
        check_same(['content' => $html], tov($tovRich, ['content' => $html], $tovCorpo), $html);
    }
});

test('text: o literal <p></p> é conteúdo, a regra do richtext não vale para ele', function () use ($text) {
    check_same(['subtitle' => '<p></p>'], tov($text, ['subtitle' => '<p></p>'], ['subtitle' => 'Olá']));
});

test('e2e collect+overrides: aba de tradução que ninguém tocou não grava nada', function () {
    $defs = [
        ['name' => 'dek', 'type' => 'textarea'],
        ['name' => 'thumbnail', 'type' => 'image'],
        ['name' => 'content', 'type' => 'richtext'],
    ];
    $default = FieldRenderer::collect($defs, ['dek' => 'Resumo', 'thumbnail' => '42', 'content' => '<p>Corpo.</p>']);
    // O que o formulário envia de uma aba em branco: o editor manda <p></p>.
    $translated = FieldRenderer::collect($defs, ['dek' => '', 'thumbnail' => '0', 'content' => '<p></p>']);
    check_same([], FieldRenderer::translationOverrides($defs, $translated, $default));
});
