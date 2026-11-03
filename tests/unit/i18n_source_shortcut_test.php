<?php

declare(strict_types=1);

use Velk\Config;
use Velk\I18n;
use Velk\Models\ThemeString;

/**
 * O idioma em que o tema foi escrito não é decisão do engine.
 *
 * `translate()` tinha um atalho que devolvia a string-fonte sem consultar o
 * catálogo quando o idioma do visitante era um valor FIXO no código
 * (`SOURCE_LANGUAGE = 'en'`). A premissa era que todo texto passado ao `t()`
 * está escrito em inglês — verdade para o core na v3.6.3, quando o atalho
 * nasceu, e falsa uma versão depois: a v3.7.1 tirou as frases do core
 * (passou a devolver códigos), e sobraram como fonte apenas as strings DO
 * TEMA, cujo idioma é escolha de quem escreve o tema.
 *
 * O efeito era um tema em português exibindo PORTUGUÊS para o visitante
 * inglês: `current === 'en'` batia no atalho e a tradução cadastrada no
 * painel nunca era consultada. Sem erro, e sem conserto pelo painel.
 *
 * Sem o atalho, o catálogo é consultado sempre e o fallback para a fonte —
 * que já existia — acerta as quatro combinações. O idioma PADRÃO do site
 * segue exibindo o texto do tema, agora por garantia e não por sorte: o
 * painel recusa cadastrar o padrão como idioma (LanguageController: 
 * `error_is_default`), então aquele slot do catálogo não tem como ser
 * preenchido.
 *
 * ─── Por que o catálogo é injetado por reflexão ──────────────────────────
 *
 * `loadCatalog()` monta a consulta com `JSON_UNQUOTE`, que é MySQL. O que
 * está sob teste aqui é a RAMIFICAÇÃO do translate() — consultar ou não —,
 * não o SQL que lê o catálogo. Injetar isola exatamente isso.
 *
 * A guarda contra reintroduzir o atalho é o caso "visitante en COM tradução
 * cadastrada": ele é o único que muda de resposta se alguém puser a
 * comparação de volta.
 */

if (!function_exists('iss_i18n')) {
    /**
     * @param array<string,string> $catalog já no formato de loadCatalog(): key_hash => tradução
     * @param ?string $current null = deixa current() resolver pelo site.json
     */
    function iss_i18n(array $site, array $catalog, ?string $current = null): I18n
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, []);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);

        $i18n = (new ReflectionClass(I18n::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(I18n::class, 'config'))->setValue($i18n, $config);
        // Pré-semeia o catálogo: com ele não-null, loadCatalog() devolve
        // sem tocar o banco, e `db`/`request` ficam sem inicializar.
        (new ReflectionProperty(I18n::class, 'catalog'))->setValue($i18n, $catalog);
        if ($current !== null) {
            (new ReflectionProperty(I18n::class, 'activeLanguage'))->setValue($i18n, $current);
        }
        return $i18n;
    }
}

if (!function_exists('iss_site')) {
    function iss_site(bool $enabled = true, string $default = 'pt-BR'): array
    {
        return ['i18n' => ['enabled' => $enabled, 'default' => $default]];
    }
}

if (!function_exists('iss_catalog')) {
    /** @param array<string,string> $pairs fonte => tradução */
    function iss_catalog(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $source => $translation) {
            $out[ThemeString::makeKey($source, null)] = $translation;
        }
        return $out;
    }
}

// ─── Tema escrito em PORTUGUÊS (site pt-BR) ──────────────────────────────

test('fonte pt · visitante no idioma padrão: exibe o texto do tema', function () {
    // Ninguém traduz pt→pt, e o painel nem oferece a coluna do padrão:
    // catálogo vazio, e o fallback devolve a fonte.
    $i18n = iss_i18n(iss_site(), iss_catalog([]), 'pt-BR');
    check_same('Leia mais', $i18n->translate('Leia mais'));
});

test('fonte pt · visitante en: exibe a TRADUÇÃO cadastrada, não a fonte', function () {
    // O bug. Com o atalho, `current === en` devolvia 'Leia mais'.
    $i18n = iss_i18n(iss_site(), iss_catalog(['Leia mais' => 'Read more']), 'en');
    check_same('Read more', $i18n->translate('Leia mais'), 'o visitante inglês não pode receber português');
});

test('fonte pt · visitante en sem tradução cadastrada: cai na fonte', function () {
    $i18n = iss_i18n(iss_site(), iss_catalog([]), 'en');
    check_same('Leia mais', $i18n->translate('Leia mais'), 'sem tradução, a fonte é a única resposta possível');
});

// ─── Tema escrito em INGLÊS (site pt-BR) — a convenção antiga segue válida ──

test('fonte en · visitante no idioma padrão: exibe a tradução cadastrada', function () {
    $i18n = iss_i18n(iss_site(), iss_catalog(['Read more' => 'Leia mais']), 'pt-BR');
    check_same('Leia mais', $i18n->translate('Read more'));
});

test('fonte en · visitante en: exibe o texto do tema', function () {
    $i18n = iss_i18n(iss_site(), iss_catalog([]), 'en');
    check_same('Read more', $i18n->translate('Read more'));
});

// ─── i18n desligado: site de um idioma só ────────────────────────────────

test('i18n desligado: current() cai no padrão e o texto do tema é exibido', function () {
    // Sem `current` explícito de propósito: exercita current() resolvendo
    // por isEnabled() === false → defaultLanguage().
    $i18n = iss_i18n(iss_site(enabled: false), iss_catalog([]));
    check_same('pt-BR', $i18n->current(), 'i18n desligado deve resolver para o padrão do site');
    check_same('Leia mais', $i18n->translate('Leia mais'));
});

// ─── Interpolação vale nos dois caminhos ─────────────────────────────────

test('params interpolam na TRADUÇÃO quando ela existe', function () {
    $i18n = iss_i18n(iss_site(), iss_catalog(['Olá, {name}!' => 'Hello, {name}!']), 'en');
    check_same('Hello, Ana!', $i18n->translate('Olá, {name}!', ['name' => 'Ana']));
});

test('params interpolam na FONTE quando não há tradução', function () {
    $i18n = iss_i18n(iss_site(), iss_catalog([]), 'en');
    check_same('Olá, Ana!', $i18n->translate('Olá, {name}!', ['name' => 'Ana']));
});
