<?php

declare(strict_types=1);

use Velk\Config;
use Velk\Models\Page;

/**
 * Subpáginas — hierarquia. A montagem de URL (buildPath) é lógica pura,
 * testada isoladamente via closure de resolução. A resolução via site.json
 * (fullPath/resolveByUrl no caminho JSON, idioma padrão) é testável sem DB
 * com config_with_site(). O caminho custom (DB) e i18n com tradução são
 * validados manualmente no browser.
 */

if (!function_exists('config_with_site')) {
    function config_with_site(array $site): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(Config::class, 'site');
        $prop->setValue($config, $site);
        return $config;
    }
}

/** Closure de resolução a partir de um mapa key => ['segment','parent']. */
if (!function_exists('mk_resolve')) {
    function mk_resolve(array $map): callable
    {
        return fn(string $key): ?array => $map[$key] ?? null;
    }
}

// ── buildPath (pura) ─────────────────────────────────────────────────────
test('buildPath: home (segmento vazio, sem pai) → /', function () {
    $r = mk_resolve(['home' => ['segment' => '', 'parent' => null]]);
    check_same('/', Page::buildPath('home', $r));
});

test('buildPath: página-raiz → /sobre', function () {
    $r = mk_resolve(['sobre' => ['segment' => 'sobre', 'parent' => null]]);
    check_same('/sobre', Page::buildPath('sobre', $r));
});

test('buildPath: dois níveis → /servicos/consultoria', function () {
    $r = mk_resolve([
        'consultoria' => ['segment' => 'consultoria', 'parent' => 'servicos'],
        'servicos'    => ['segment' => 'servicos', 'parent' => null],
    ]);
    check_same('/servicos/consultoria', Page::buildPath('consultoria', $r));
});

test('buildPath: três níveis → /a/b/c', function () {
    $r = mk_resolve([
        'c' => ['segment' => 'c', 'parent' => 'b'],
        'b' => ['segment' => 'b', 'parent' => 'a'],
        'a' => ['segment' => 'a', 'parent' => null],
    ]);
    check_same('/a/b/c', Page::buildPath('c', $r));
});

test('buildPath: ciclo não trava (degrada em path finito)', function () {
    $r = mk_resolve([
        'a' => ['segment' => 'a', 'parent' => 'b'],
        'b' => ['segment' => 'b', 'parent' => 'a'],
    ]);
    $path = Page::buildPath('a', $r);
    check(is_string($path) && str_contains($path, 'a') && str_contains($path, 'b'), 'monta finito, sem loop infinito');
});

test('buildPath: key inexistente → /', function () {
    check_same('/', Page::buildPath('nada', mk_resolve([])));
});

// ── fullPath via site.json (idioma padrão, sem DB) ───────────────────────
test('fullPath: home → /', function () {
    $c = config_with_site(['pages' => ['home' => ['label' => 'Home']]]);
    check_same('/', Page::fullPath('home', $c));
});

test('fullPath: página-raiz JSON → /sobre', function () {
    $c = config_with_site(['pages' => ['sobre' => ['label' => 'Sobre']]]);
    check_same('/sobre', Page::fullPath('sobre', $c));
});

test('fullPath: JSON filha de JSON (2 níveis) → /servicos/consultoria', function () {
    $c = config_with_site(['pages' => [
        'servicos'    => ['label' => 'Serviços'],
        'consultoria' => ['label' => 'Consultoria', 'parent' => 'servicos'],
    ]]);
    check_same('/servicos/consultoria', Page::fullPath('consultoria', $c));
});

test('fullPath: 3 níveis JSON → /a/b/c', function () {
    $c = config_with_site(['pages' => [
        'a' => ['label' => 'A'],
        'b' => ['label' => 'B', 'parent' => 'a'],
        'c' => ['label' => 'C', 'parent' => 'b'],
    ]]);
    check_same('/a/b/c', Page::fullPath('c', $c));
});

test('fullPath: campo url vira o segmento local sob o pai', function () {
    $c = config_with_site(['pages' => [
        'servicos'    => ['label' => 'Serviços'],
        'consultoria' => ['label' => 'Consultoria', 'parent' => 'servicos', 'url' => 'consult'],
    ]]);
    check_same('/servicos/consult', Page::fullPath('consultoria', $c));
});

// ── resolveByUrl hierárquico (JSON, idioma padrão) ───────────────────────
if (!function_exists('json_pages_miss')) {
    function json_pages_miss(string $path, Velk\Config $c): bool
    {
        try {
            return \Velk\Models\Page::resolveByUrl($path, $c) === null;
        } catch (\Throwable $e) {
            return true; // seção 2 (DB) alcançada = seção 1 (JSON) não casou
        }
    }
}

test('resolveByUrl: subpágina JSON /servicos/consultoria resolve pela cadeia', function () {
    $c = config_with_site(['pages' => [
        'servicos'    => ['label' => 'Serviços', 'template' => 's.php'],
        'consultoria' => ['label' => 'Consultoria', 'template' => 'c.php', 'parent' => 'servicos'],
    ]]);
    $hit = Page::resolveByUrl('/servicos/consultoria', $c);
    check($hit !== null && $hit['key'] === 'consultoria', 'subpágina resolve');
    $pai = Page::resolveByUrl('/servicos', $c);
    check($pai !== null && $pai['key'] === 'servicos', 'pai continua resolvendo sozinho');
});

test('resolveByUrl: segmento sem a cadeia do pai → não casa (JSON)', function () {
    $c = config_with_site(['pages' => [
        'servicos'    => ['label' => 'S', 'template' => 's.php'],
        'consultoria' => ['label' => 'C', 'template' => 'c.php', 'parent' => 'servicos'],
    ]]);
    // /consultoria (sem /servicos) não é a URL canônica → seção JSON não casa
    check(json_pages_miss('/consultoria', $c), 'só o slug-folha não casa');
    check(json_pages_miss('/errado/consultoria', $c), 'pai errado não casa');
});

// ── collectDescendants (pura, anti-ciclo) ────────────────────────────────
test('collectDescendants: subárvore completa, ciclo ignorado', function () {
    // a → b,c ; b → d ; d → a (ciclo, deve ser ignorado)
    $children = ['a' => ['b', 'c'], 'b' => ['d'], 'c' => [], 'd' => ['a']];
    $childrenOf = fn(string $k): array => $children[$k] ?? [];
    $desc = Page::collectDescendants('a', $childrenOf);
    sort($desc);
    check_same(['b', 'c', 'd'], $desc);
});

test('collectDescendants: folha sem filhos → vazio', function () {
    check_same([], Page::collectDescendants('x', fn(string $k): array => []));
});
