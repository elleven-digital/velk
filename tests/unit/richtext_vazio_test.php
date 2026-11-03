<?php

declare(strict_types=1);

use Velk\Richtext;

/**
 * Richtext::isEmptyMarkup — o HTML de um editor de texto sem nada escrito.
 *
 * O editor do painel nunca devolve '': vazio, ele grava `<p></p>`. Quem
 * precisa saber se um richtext foi preenchido (a gravação e a leitura de uma
 * tradução) pergunta aqui, e a resposta erra para o lado de manter: na
 * dúvida, o valor é conteúdo.
 */

test('isEmptyMarkup: o que o editor grava sem nada escrito', function () {
    foreach ([
        '<p></p>',
        '<p></p><p></p>',
        "<p></p>\n<p></p>",
        '<p><br></p>',
        '<p><br /></p>',
        '<p> </p>',
        '<h2></h2>',
        '<h3><strong></strong></h3>',
        '<ul><li><p></p></li></ul>',
        '<ol><li><p></p></li></ol>',
        '<blockquote><p></p></blockquote>',
        '<pre><code></code></pre>',
        '<p><a href="https://exemplo.com" rel="noopener noreferrer"></a></p>',
        '<P></P>',
    ] as $html) {
        check(Richtext::isEmptyMarkup($html), 'deveria ser vazio: ' . $html);
    }
});

test('isEmptyMarkup: com texto não é vazio', function () {
    foreach (['<p>a</p>', '<p></p><p>0</p>', '<h2>Título</h2>', '<p><em>x</em></p>', '<p>&nbsp;</p>'] as $html) {
        check(!Richtext::isEmptyMarkup($html), 'não deveria ser vazio: ' . $html);
    }
});

test('isEmptyMarkup: imagem, tabela, linha e bloco do tema são conteúdo', function () {
    foreach ([
        '<img src="/storage/uploads/a.jpg" alt="">',
        '<p><img src="/storage/uploads/a.jpg" alt="a > b"></p>',
        '<figure><img src="/storage/uploads/a.jpg" alt=""><figcaption></figcaption></figure>',
        '<table><tbody><tr><td><p></p></td></tr></tbody></table>',
        '<hr>',
        '<p></p><hr><p></p>',
        '<div data-velk-block="bignumbers" data-velk-data="{}"></div>',
        "<div data-velk-block='bignumbers' data-velk-data='{}'></div>",
        '<iframe src="https://exemplo.com"></iframe>',
        '<video src="/storage/uploads/a.mp4"></video>',
        '<p></p><!-- nota -->',
    ] as $html) {
        check(!Richtext::isEmptyMarkup($html), 'não deveria ser vazio: ' . $html);
    }
});

test('isEmptyMarkup: tag parecida com as de texto não passa por elas', function () {
    // <pre> não é <p>, <picture> não é <p>, <embed> não é <em>, <svg> não é <s>.
    foreach (['<picture></picture>', '<embed src="a.swf">', '<svg></svg>', '<script></script>', '<input>', '<button></button>'] as $html) {
        check(!Richtext::isEmptyMarkup($html), 'não deveria ser vazio: ' . $html);
    }
});

test('isEmptyMarkup: só responde por HTML — sem tag, não é com ela', function () {
    // '' e espaço são o "vazio" de um campo de texto, tratado por quem chama.
    foreach (['', ' ', "\n", 'texto', '0', '2 < 3'] as $text) {
        check(!Richtext::isEmptyMarkup($text), 'não é HTML vazio: ' . var_export($text, true));
    }
    foreach ([null, 0, 42, false, true, [], ['<p></p>']] as $value) {
        check(!Richtext::isEmptyMarkup($value), 'não é string: ' . var_export($value, true));
    }
});

test('isEmptyDocument: o vazio do editor começa por um bloco de texto', function () {
    // Na leitura das traduções o tipo do campo não está à mão: só conta como
    // "não traduzido" o que tem a cara de um editor em branco.
    foreach (['<p></p>', '<p><br></p>', '  <h3><strong></strong></h3>', '<ul><li><p></p></li></ul>', '<blockquote><p></p></blockquote>', '<pre><code></code></pre>', '<P></P>', '<p><a href="https://exemplo.com"></a></p>'] as $html) {
        check(Richtext::isEmptyDocument($html), 'deveria ser vazio: ' . $html);
    }
    foreach (['<i class="fi fi-us"></i>', '<span class="x"></span>', '<a href="/en"></a>', '<br>', '<strong></strong>', '<p>a</p>', '<hr>', 'texto', '', null, 0] as $value) {
        check(!Richtext::isEmptyDocument($value), 'não deveria ser vazio: ' . var_export($value, true));
    }
});
