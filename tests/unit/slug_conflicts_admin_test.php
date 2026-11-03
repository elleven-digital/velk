<?php

declare(strict_types=1);

use Velk\Admin\SlugConflicts;
use Velk\App;
use Velk\Models\Item;
use Velk\Models\Term;

/**
 * Ao salvar, o painel avisa quando o endereço de um conteúdo num idioma
 * colide com o de outro: o slug traduzido de um é o slug (padrão ou
 * traduzido) do outro naquele idioma. Só um abre ali — o traduzido, ou o
 * publicado contra um rascunho —; o outro fica sem endereço no idioma.
 * SlugConflicts devolve as colisões; o controller monta a mensagem.
 */

require_once dirname(__DIR__) . '/fixtures/slug_i18n_boot.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

test('conflitos: post sem colisão não gera aviso', function () {
    sib_boot();
    $a = Item::find(sib_item('post', 'meu-post', ['en' => 'my-post']));
    check_same([], SlugConflicts::forItem($a));
});

test('conflitos: o post que tomou o endereço avisa de quem ficou sem ele', function () {
    sib_boot();
    sib_item('post', 'my-post');
    $b = Item::find(sib_item('post', 'outro', ['en' => 'my-post']));
    $c = SlugConflicts::forItem($b);
    check_same(1, count($c));
    check_same('en', $c[0]['language']);
    check_same('/en/post/my-post', $c[0]['url']);
    check_same(false, $c[0]['lost']);
    check_same(['My-post'], $c[0]['others']);
});

test('conflitos: o post que ficou sem endereço avisa de quem o tem', function () {
    sib_boot();
    $a = Item::find(sib_item('post', 'my-post'));
    sib_item('post', 'outro', ['en' => 'my-post']);
    $c = SlugConflicts::forItem($a);
    check_same(1, count($c));
    check_same('en', $c[0]['language']);
    check_same(true, $c[0]['lost']);
    check_same(['Outro'], $c[0]['others']);
});

test('conflitos: em hierarquia, só entre irmãos', function () {
    sib_boot();
    $pai1 = sib_item('docs', 'guia');
    $pai2 = sib_item('docs', 'manual');
    sib_item('docs', 'intro', [], $pai1);
    $b = Item::find(sib_item('docs', 'outro', ['en' => 'intro'], $pai2));
    check_same([], SlugConflicts::forItem($b), 'pais diferentes: endereços diferentes');
    $c = Item::find(sib_item('docs', 'mais', ['en' => 'intro'], $pai1));
    check_same(1, count(SlugConflicts::forItem($c)));
});

test('conflitos: termos e páginas também', function () {
    $app = sib_boot();
    sib_term('categoria', 'events');
    $t = Term::find(sib_term('categoria', 'eventos', ['en' => 'events']));
    $c = SlugConflicts::forTerm($t);
    check_same(1, count($c));
    check_same('/en/categoria/events', $c[0]['url']);
    check_same(['Events'], $c[0]['others']);

    sib_page('promo', [], 'promo', 'landing');
    sib_page('oferta', ['en' => 'promo'], 'oferta', 'landing');
    $p = SlugConflicts::forPage('oferta', $app->config);
    check_same(1, count($p));
    check_same('/en/promo', $p[0]['url']);
    check_same(false, $p[0]['lost']);
    check_same(['Promo'], $p[0]['others']);
    $perdeu = SlugConflicts::forPage('promo', $app->config);
    check_same(true, $perdeu[0]['lost'] ?? null);
    check_same([], SlugConflicts::forPage('sobre', $app->config));
});

test('Page::pageUrl: subpágina leva a cadeia de pais (o link "Ver" do painel)', function () {
    $app = sib_boot();
    check_same('/servicos/consultoria', \Velk\Models\Page::pageUrl('consultoria', $app->config->page('consultoria')));
    sib_page('filha', [], 'filha', 'landing', 'servicos');
    check_same('/servicos/filha', \Velk\Models\Page::pageUrl('filha', ['url' => '/filha']));
    $app->basePath = '/velk';
    check_same('/velk/servicos/consultoria', \Velk\Models\Page::pageUrl('consultoria', $app->config->page('consultoria')));
    check_same('/velk/', \Velk\Models\Page::pageUrl('home', $app->config->page('home')));
});

test('conflitos: em hierarquia, também entre pais diferentes que caem no mesmo endereço', function () {
    sib_boot();
    // Dois itens-raiz no mesmo /en/docs/guia: B (traduzido) ganha.
    $a = sib_item('docs', 'guia');
    $b = sib_item('docs', 'outro', ['en' => 'guia']);
    $filhaA = sib_item('docs', 'intro', [], $a);
    // A filha de B com o mesmo slug cai em /en/docs/guia/intro e ganha da de A.
    $filhaB = Item::find(sib_item('docs', 'intro', [], $b));
    $c = SlugConflicts::forItem($filhaB);
    check_same(1, count($c), json_encode($c));
    check_same(false, $c[0]['lost']);
    check_same(['Intro'], $c[0]['others']);
    check_same(true, SlugConflicts::forItem(Item::find($filhaA))[0]['lost'] ?? null);
});

test('conflitos: tipo sem página e página de 404 não têm endereço a disputar', function () {
    $app = sib_boot();
    $site = (new ReflectionProperty(\Velk\Config::class, 'site'))->getValue($app->config);
    $site['item_types']['bloco'] = ['label' => 'Blocos', 'slug' => 'bloco', 'has_page' => false];
    $site['not_found_page'] = 'promo';
    (new ReflectionProperty(\Velk\Config::class, 'site'))->setValue($app->config, $site);
    sib_item('bloco', 'x');
    check_same([], SlugConflicts::forItem(Item::find(sib_item('bloco', 'y', ['en' => 'x']))));
    sib_page('oferta', ['en' => 'promo'], 'oferta', 'landing');
    check_same([], SlugConflicts::forPage('promo', $app->config));
});

test('conflitos e sitemap: o custo com páginas não cresce com o número delas', function () {
    $conta = function (int $paginas, callable $fn): int {
        $app = sib_boot();
        for ($i = 0; $i < $paginas; $i++) {
            sib_page("p{$i}", $i % 2 ? ['en' => "page-{$i}"] : [], "pagina-{$i}", 'landing', $i > 0 ? 'p0' : null);
        }
        $antes = sib_queries();
        $fn($app);
        return sib_queries() - $antes;
    };
    $aviso = fn($app) => SlugConflicts::forPage('p1', $app->config);
    check_same($conta(5, $aviso), $conta(30, $aviso), 'aviso ao salvar');
    $sitemap = fn($app) => \Velk\Sitemap::generate($app->config);
    check_same($conta(5, $sitemap), $conta(30, $sitemap), 'sitemap');
});
