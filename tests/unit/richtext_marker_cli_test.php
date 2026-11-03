<?php

declare(strict_types=1);

use Velk\Commands\Content;
use Velk\RichtextBlocks;

/**
 * Quem escreve o HTML de um texto por fora do painel (o CLI, um agente) pode
 * gravar um marcador de bloco que o editor não consegue mostrar: dados que
 * não são um objeto JSON (uma aspa crua, uma vírgula sobrando) ou conteúdo
 * dentro da tag, que deveria ser vazia. No site o bloco não sai. O
 * `item:create`, o `item:update` e o `page:update` recusam esse marcador no
 * que chega, dizendo o que está errado.
 */

if (!function_exists('rmc_errors')) {
    /** @return list<string> */
    function rmc_errors(array $incoming): array
    {
        return (new ReflectionMethod(Content::class, 'blockMarkerErrors'))->invoke(null, $incoming);
    }
}

test('marcador: o que o editor grava não tem problema', function () {
    check_same([], RichtextBlocks::markerProblems('<p>a</p><div data-velk-block="cta" data-velk-data="{&quot;t&quot;:&quot;x&quot;}"></div>'));
    check_same([], RichtextBlocks::markerProblems("<div data-velk-block='cta'></div>"), 'sem dados é um bloco vazio');
    check_same([], RichtextBlocks::markerProblems('<p>sem bloco</p>'));
    check_same([], RichtextBlocks::markerProblems('<div class="x"><p>div comum</p></div>'));
});

test('marcador: dados que não são um objeto JSON são apontados', function () {
    $p = RichtextBlocks::markerProblems('<div data-velk-block="cta" data-velk-data="{t:1}"></div>');
    check_same(1, count($p), implode(' | ', $p));
    check(str_contains($p[0], 'block "cta"') && str_contains($p[0], 'not a JSON object'), $p[0]);
    // Uma aspa crua dentro de um valor entre aspas simples fecha o atributo:
    // a tag inteira fica ilegível.
    $p = RichtextBlocks::markerProblems('<div data-velk-block="cta" data-velk-data=\'{"t":"it\'s"}\'></div>');
    check_same(1, count($p), implode(' | ', $p));
    check(str_contains($p[0], 'check the quotes'), $p[0]);
    check_same(1, count(RichtextBlocks::markerProblems('<div data-velk-block="cta" data-velk-data="[1,2]"></div>')), 'lista não é objeto');
    check_same(1, count(RichtextBlocks::markerProblems('<div data-velk-block="cta" data-velk-data="{&quot;a&quot;:1,}"></div>')), 'vírgula sobrando');
});

test('marcador: conteúdo dentro da tag é apontado', function () {
    $p = RichtextBlocks::markerProblems('<div data-velk-block="cta" data-velk-data="{}"><p>x</p></div>');
    check_same(1, count($p), implode(' | ', $p));
    check(str_contains($p[0], 'empty tag'), $p[0]);
});

test('CLI: o marcador com problema é recusado no que chega, com o nome do campo', function () {
    $ruim = '<div data-velk-block="cta" data-velk-data="{x}"></div>';
    $errors = rmc_errors([
        'titulo' => 'ok',
        'content' => $ruim,
        'secoes' => [['texto' => '<div data-velk-block="cta"><p>dentro</p></div>']],
        'numero' => 3,
    ]);
    check_same(2, count($errors), implode(' | ', $errors));
    check(str_starts_with($errors[0], "Field 'content': "), $errors[0]);
    check(str_starts_with($errors[1], "Field 'secoes': "), $errors[1]);
    check_same([], rmc_errors(['content' => '<p>bom</p><div data-velk-block="cta" data-velk-data="{}"></div>']));
});
