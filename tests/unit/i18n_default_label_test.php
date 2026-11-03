<?php

declare(strict_types=1);

use Velk\Config;

/**
 * `i18n.default_label` — o nome de exibição do idioma padrão.
 *
 * O padrão é o único idioma sem nome editável: os adicionais são linhas em
 * `languages`, com campo "Nome" obrigatório, e o `LanguageController` proíbe
 * criar uma linha para o código padrão. Antes o rótulo dele vinha de um mapa
 * fixo de treze idiomas dentro do `I18n`, que ninguém conseguia sobrescrever
 * — não dava pra trocar "Português (Brasil)" por "Brasil" ou "PT".
 *
 * Com a chave, cada idioma tem UM dono do seu nome: o padrão é do tema, os
 * demais do painel. O mapa saiu junto.
 *
 * Estes testes cobrem a leitura no Config e a validação. A ordem de
 * resolução dentro de `I18n::labelFor()` toca o banco (Language::find) e
 * fica fora daqui — o que dá pra fixar sem DB é o contrato da chave.
 */

if (!function_exists('idl_config')) {
    /** Config real sobre um root descartável (mesmo idiom dos outros testes). */
    function idl_config(array $site): Config
    {
        $dir = sys_get_temp_dir() . '/velk_default_label_' . bin2hex(random_bytes(6));
        mkdir($dir . '/config', 0777, true);
        mkdir($dir . '/theme', 0777, true);
        file_put_contents($dir . '/config/env.php', "<?php\nreturn [];\n");
        file_put_contents($dir . '/theme/site.json', json_encode($site));

        $config = new Config($dir);
        idl_rrmdir($dir);
        return $config;
    }
}

if (!function_exists('idl_rrmdir')) {
    function idl_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . '/' . $f;
            is_dir($p) ? idl_rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}

test('lê o rótulo declarado no site.json', function () {
    $c = idl_config(['i18n' => ['enabled' => true, 'default' => 'pt-BR', 'default_label' => 'Português (Brasil)']]);
    check_same('Português (Brasil)', $c->defaultLanguageLabel());
});

test('o tema pode escolher qualquer nome, não só o "oficial"', function () {
    // O ponto da chave: o mapa fixo de antes impunha "Português (Brasil)".
    foreach (['Brasil', 'PT', 'pt-br 🇧🇷', 'Português do Brasil'] as $nome) {
        $c = idl_config(['i18n' => ['enabled' => true, 'default' => 'pt-BR', 'default_label' => $nome]]);
        check_same($nome, $c->defaultLanguageLabel());
    }
});

test('ausente, vazio ou não-string devolve null', function () {
    // Null e não '' porque quem chama precisa distinguir "não declarado" de
    // "declarado em branco" — os dois caem no mesmo fallback, mas a validação
    // trata os dois como erro e a mensagem precisa fazer sentido.
    check_same(null, idl_config(['i18n' => ['enabled' => true, 'default' => 'pt-BR']])->defaultLanguageLabel());
    check_same(null, idl_config(['i18n' => ['default_label' => '']])->defaultLanguageLabel());
    check_same(null, idl_config(['i18n' => ['default_label' => '   ']])->defaultLanguageLabel());
    check_same(null, idl_config(['i18n' => ['default_label' => 42]])->defaultLanguageLabel());
    check_same(null, idl_config(['i18n' => ['default_label' => ['a']]])->defaultLanguageLabel());
    check_same(null, idl_config([])->defaultLanguageLabel());
});

test('espaços nas pontas são aparados', function () {
    $c = idl_config(['i18n' => ['default_label' => '  Português  ']]);
    check_same('Português', $c->defaultLanguageLabel());
});

test('a chave é independente de i18n.enabled na LEITURA', function () {
    // Config só lê; quem decide que é obrigatório é o schema:validate. Um
    // site de idioma único que declare a chave não deve ser penalizado.
    $c = idl_config(['i18n' => ['enabled' => false, 'default' => 'pt-BR', 'default_label' => 'Português']]);
    check_same('Português', $c->defaultLanguageLabel());
});
