<?php

declare(strict_types=1);

/**
 * O admin.js liga cada parte do painel isolada.
 *
 * O arquivo é um módulo: uma exceção no topo interrompe todo o resto dele.
 * Com as partes chamadas em sequência, um erro numa delas — um campo com um
 * formato inesperado numa tela — levaria junto todas as seguintes: as abas
 * de idioma, a tabela de traduções, o menu do celular, as ações em massa.
 * Cada parte passa pelo boot(), que manda o erro para o console e segue.
 *
 * O comportamento foi provado no navegador: com um erro forçado no editor
 * de texto, as abas de idioma da mesma tela seguem funcionando. Estes testes
 * guardam a forma: nenhuma parte volta a ser chamada solta no topo do
 * arquivo, nenhuma fica sem ser ligada, e os dois auxiliares seguem isolando
 * o erro.
 */

if (!function_exists('ajb_source')) {
    function ajb_source(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/assets/js/admin.js');
    }
}

test('admin.js: nenhuma parte do painel é ligada solta no topo do arquivo', function () {
    preg_match_all(
        '/^(?:document\.querySelectorAll\([^\n]*\)\.forEach\((?:init|bind)[A-Z]\w*\)|(?:init|bind)[A-Z]\w*\(\));?[ \t]*(?:\/\/[^\n]*)?$/m',
        ajb_source(),
        $m
    );
    check_same([], $m[0], 'ligue pelo boot()/bootEach(), para um erro não levar as partes seguintes junto');
});

test('admin.js: toda parte definida no topo do arquivo é ligada pelo boot', function () {
    $src = ajb_source();
    preg_match_all('/^const ((?:init|bind)[A-Z]\w*) = /m', $src, $defs);
    check(count($defs[1]) >= 19, 'esperava as 19 partes de hoje; achou ' . count($defs[1]));
    $unwired = [];
    foreach ($defs[1] as $name) {
        $wired = str_contains($src, "boot({$name});")
            || preg_match('/^bootEach\([^\n]*, ' . $name . '\);$/m', $src) === 1;
        if (!$wired) {
            $unwired[] = $name;
        }
    }
    check_same([], $unwired, 'parte definida e nunca ligada: o recurso dela não funciona em tela nenhuma');
});

test('admin.js: o boot() segura o erro, e o bootEach() passa cada elemento por ele', function () {
    // Sem isto, tirar o try/catch do boot() — ou fazer o bootEach() chamar a
    // parte direto — devolveria a cascata sem que nenhum teste percebesse.
    $src = ajb_source();
    check(
        preg_match('/const boot = \(init, label = init\.name\) => \{\s*try \{\s*init\(\);\s*\} catch \(e\) \{\s*console\.error\(/', $src) === 1,
        'o boot() precisa chamar a parte dentro de try/catch e mandar o erro para o console'
    );
    check(
        preg_match('/const bootEach = \(selector, init\) => \{\s*document\.querySelectorAll\(selector\)\.forEach\(\(el\) => boot\(\(\) => init\(el\), init\.name\)\);/', $src) === 1,
        'o bootEach() precisa passar cada elemento pelo boot()'
    );
});
