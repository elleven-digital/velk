<?php

declare(strict_types=1);

use Velk\Admin\Controllers\MediaController;
use Velk\App;
use Velk\Models\Media;
use Velk\Request;

/**
 * Busca na biblioteca de mídia (/admin/media e o seletor dos campos de imagem
 * e arquivo, `?picker=1`): `q` filtra pelo nome do arquivo — o original, que
 * é o que a grade mostra, e o gravado em disco — e pelo texto alternativo do
 * idioma padrão. A busca é do servidor, para alcançar a biblioteca inteira e
 * não só a página aberta; o admin.js refaz a consulta enquanto se digita.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/block_boot.php';

if (!function_exists('msx_boot')) {
    /** Admin logado, SQLite com a tabela `media` e uma pasta de uploads vazia. */
    function msx_boot(): App
    {
        $uploads = sys_get_temp_dir() . '/velk-media-search-' . getmypid();
        @mkdir($uploads, 0775, true);
        return blkb_boot([], true, ['uploads' => $uploads]);
    }

    function msx_add(string $original, string $mime = 'image/jpeg', ?string $alt = null, ?string $filename = null): void
    {
        static $n = 0;
        $n++;
        App::instance()->db->insert('media', [
            'filename' => $filename ?? strtolower(str_replace(' ', '-', $original)),
            'original_name' => $original,
            'mime' => $mime,
            'size' => 1000,
            'alt' => $alt,
            'created_at' => sprintf('2026-10-01 10:00:%02d', $n % 60),
        ]);
    }

    function msx_names(array $media): array
    {
        $out = array_map(fn(Media $m) => $m->originalName, $media);
        sort($out);
        return $out;
    }

    function msx_get(App $app, array $query): string
    {
        $request = new Request('GET', '/admin/media', $query, [], [], [], []);
        return (new MediaController())->index($request)->getBody();
    }
}

test('busca de mídia: termo vazio não filtra', function () {
    check_same(['', []], Media::searchWhereSql(''));
    check_same(['', []], Media::searchWhereSql('   '));
});

test('busca de mídia: procura no nome original, no nome em disco e no alt', function () {
    [$sql, $params] = Media::searchWhereSql('logo');
    check_same("(original_name LIKE ? ESCAPE '!' OR filename LIKE ? ESCAPE '!' OR alt LIKE ? ESCAPE '!')", $sql);
    check_same(['%logo%', '%logo%', '%logo%'], $params);
});

test('busca de mídia: o termo da URL é aparado e limitado a 100 caracteres', function () {
    check_same('', MediaController::parseSearch(null));
    check_same('foto praia', MediaController::parseSearch("  foto \n\t praia  "));
    // Por caractere, não por byte: cortar um "ç" ao meio deixaria o LIKE inválido.
    $longo = MediaController::parseSearch(str_repeat('ç', 150));
    check_same(100, mb_strlen($longo));
    check(mb_check_encoding($longo, 'UTF-8'), 'UTF-8 válido depois do corte');
});

test('busca de mídia: cada palavra precisa aparecer, em qualquer ordem e campo', function () {
    [$sql, $params] = Media::searchWhereSql('camiseta  roxa');
    $grupo = "(original_name LIKE ? ESCAPE '!' OR filename LIKE ? ESCAPE '!' OR alt LIKE ? ESCAPE '!')";
    check_same($grupo . ' AND ' . $grupo, $sql);
    check_same(['%camiseta%', '%camiseta%', '%camiseta%', '%roxa%', '%roxa%', '%roxa%'], $params);

    msx_boot();
    msx_add('produto-camiseta.jpg', 'image/jpeg', 'Modelo na cor roxa');
    msx_add('camiseta-azul.jpg', 'image/jpeg', 'Camiseta azul');
    msx_add('foto.jpg', 'image/jpeg', 'Camiseta Velk roxa');
    // "roxa camiseta": as duas palavras, fora de ordem, uma no nome e outra no alt.
    check_same(['foto.jpg', 'produto-camiseta.jpg'], msx_names(Media::listFiltered([], 'roxa camiseta', 60, 0)));
});

test('busca de mídia: no máximo 5 palavras entram na consulta', function () {
    [, $params] = Media::searchWhereSql('a b c d e f g');
    check_same(15, count($params));
});

test('busca de mídia: % e _ do termo são literais, não curingas', function () {
    [, $params] = Media::searchWhereSql('50%_off!');
    check_same('%50!%!_off!!%', $params[0]);
});

test('busca de mídia: filtra por nome e por alt, sem diferenciar maiúsculas', function () {
    msx_boot();
    msx_add('Logo Velk.png', 'image/png');
    msx_add('foto-praia.jpg', 'image/jpeg', 'Pôr do sol na praia');
    msx_add('contrato.pdf', 'application/pdf');
    msx_add('capa.jpg', 'image/jpeg', 'Logotipo antigo');

    check_same(['Logo Velk.png', 'capa.jpg'], msx_names(Media::listFiltered([], 'LOGO', 60, 0)));
    check_same(['foto-praia.jpg'], msx_names(Media::listFiltered([], 'sol na', 60, 0)));
    check_same(2, Media::countFiltered([], 'logo'));
    check_same(4, Media::countFiltered([], ''));
});

test('busca de mídia: o nome gravado em disco também conta', function () {
    msx_boot();
    msx_add('IMG 2041.JPG', 'image/jpeg', null, 'evento-lancamento.jpg');
    check_same(['IMG 2041.JPG'], msx_names(Media::listFiltered([], 'lancamento', 60, 0)));
});

test('busca de mídia: combina com o filtro de categoria do seletor (accept)', function () {
    msx_boot();
    msx_add('manual-logo.pdf', 'application/pdf');
    msx_add('logo.png', 'image/png');
    check_same(['logo.png'], msx_names(Media::listFiltered(['image'], 'logo', 60, 0)));
    check_same(1, Media::countFiltered(['image'], 'logo'));
    check_same(['manual-logo.pdf'], msx_names(Media::listFiltered(['document'], 'logo', 60, 0)));
});

test('busca de mídia: o seletor mostra só o que bate, com o campo preenchido', function () {
    $app = msx_boot();
    msx_add('logo.png', 'image/png');
    msx_add('banner.jpg', 'image/jpeg');
    $html = msx_get($app, ['picker' => '1', 'accept' => 'image', 'q' => 'logo']);

    check(str_contains($html, 'data-media-name="logo.png"'), 'o item que bate');
    check(!str_contains($html, 'data-media-name="banner.jpg"'), 'o que não bate fica de fora');
    check(str_contains($html, 'data-media-search'), 'o campo que o admin.js escuta');
    check(str_contains($html, 'name="q" value="logo"'), 'o termo continua no campo');
    // O formulário reenvia o modo do seletor: sem isto, Enter (ou sem JS)
    // abriria a biblioteca inteira dentro do iframe, sem o filtro de categoria.
    check(str_contains($html, '<input type="hidden" name="picker" value="1">'), $html);
    check(str_contains($html, '<input type="hidden" name="accept" value="image">'), $html);
});

test('busca de mídia: sem resultado, a mensagem diz o termo (escapado)', function () {
    $app = msx_boot();
    msx_add('logo.png', 'image/png');
    $html = msx_get($app, ['picker' => '1', 'q' => '<b>nada</b>']);

    check(str_contains($html, 'data-media-no-results'), 'mensagem de busca vazia');
    check(str_contains($html, '&lt;b&gt;nada&lt;/b&gt;'), 'termo escapado na mensagem');
    check(!str_contains($html, '<b>nada</b>'), 'nunca cru');
    check(!str_contains($html, 'data-media-empty'), 'não é a mensagem de biblioteca vazia');
});

test('busca de mídia: a paginação leva o termo junto', function () {
    $app = msx_boot();
    for ($i = 1; $i <= 61; $i++) {
        msx_add("foto-{$i}.jpg");
    }
    msx_add('outro.png', 'image/png');
    $html = msx_get($app, ['picker' => '1', 'q' => 'foto']);

    check(str_contains($html, 'href="?picker=1&amp;q=foto&amp;page=2"'), 'link da página 2 com q');
    check(substr_count($html, 'class="media-grid__item') === 60, '60 por página');
});
