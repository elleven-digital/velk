<?php

declare(strict_types=1);

use Velk\Services\FormCsv;

/**
 * `FormCsv::escape()` — o anti-fórmula da exportação em CSV.
 *
 * O conteúdo das células vem de visitante anônimo, e o destino é o Excel ou o
 * Sheets de quem administra o site. Os dois executam como fórmula o que começa
 * com `=`, `+`, `-`, `@`, TAB ou CR: sem este escape, `=HYPERLINK(...)` no
 * campo "nome" exfiltra a planilha inteira na máquina do cliente.
 */

test('escape: os seis prefixos de fórmula ganham um apóstrofo', function () {
    check_same("'=HYPERLINK(\"http://x\",\"clique\")", FormCsv::escape('=HYPERLINK("http://x","clique")'));
    check_same("'+5511999999999", FormCsv::escape('+5511999999999'));
    check_same("'-2+3", FormCsv::escape('-2+3'));
    check_same("'@SUM(A1:A9)", FormCsv::escape('@SUM(A1:A9)'));
    check_same("'\t=1+1", FormCsv::escape("\t=1+1"));
    check_same("'\r=1+1", FormCsv::escape("\r=1+1"));
});

test('escape: texto comum passa intacto', function () {
    // O `@` do e-mail e o `-` do meio não estão no primeiro caractere; o
    // espaço à frente já faz o Excel tratar como texto.
    $comuns = ['Gustavo', 'g@exemplo.com', 'R$ 4M – R$ 10M', '4-10M', '<4M', 'a;b', '"citado"', "linha 1\nlinha 2", '', ' =espaço antes'];
    foreach ($comuns as $v) {
        check_same($v, FormCsv::escape($v));
    }
});
