<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * M1: collectRepeater() must skip the SAME sentinel key the repeater
 * actually emits for its fresh-row <template> (renderRepeater() stamps
 * it with index '__INDEX__' — see renderRepeaterRow($name, '__INDEX__', ...)).
 * The guard previously checked '__template__', which the markup never
 * emits, so a stray '__INDEX__' row (e.g. JS bug, or a crafted POST) was
 * collected as real data instead of being dropped.
 */
$repeaterDef = ['name' => 'items', 'type' => 'repeater', 'fields' => [['name' => 'label', 'type' => 'text']]];

if (!function_exists('repeater_collect')) {
    function repeater_collect(mixed $raw): array
    {
        $result = FieldRenderer::collect([
            ['name' => 'items', 'type' => 'repeater', 'fields' => [['name' => 'label', 'type' => 'text']]],
        ], ['items' => $raw]);
        return $result['items'];
    }
}

test('repeater/collect: descarta a linha-sentinela __INDEX__ (template da linha nova)', function () {
    $rows = repeater_collect([
        '__INDEX__' => ['label' => 'não deveria entrar'],
        0 => ['label' => 'Primeira'],
        1 => ['label' => 'Segunda'],
    ]);
    check_same(2, count($rows), 'a linha __INDEX__ não pode virar dado real');
    check_same('Primeira', $rows[0]['label']);
    check_same('Segunda', $rows[1]['label']);
});

test('repeater/collect: a sentinela antiga (__template__) NÃO é mágica — é só uma chave normal', function () {
    // Regression guard against reverting to the wrong sentinel name: a row
    // literally keyed '__template__' is ordinary data and must be kept.
    $rows = repeater_collect([
        '__template__' => ['label' => 'Uma linha normal chamada __template__'],
    ]);
    check_same(1, count($rows), '__template__ não é o sentinel real — deve ser coletada como linha comum');
    check_same('Uma linha normal chamada __template__', $rows[0]['label']);
});

test('repeater/collect: linhas normais (índices numéricos) continuam OK', function () {
    $rows = repeater_collect([
        0 => ['label' => 'A'],
        1 => ['label' => 'B'],
    ]);
    check_same(['A', 'B'], array_column($rows, 'label'));
});

test('repeater/collect: raw não-array vira lista vazia', function () {
    check_same([], repeater_collect(null));
    check_same([], repeater_collect('x'));
});
