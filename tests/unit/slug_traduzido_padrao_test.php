<?php

declare(strict_types=1);

use Velk\App;
use Velk\Models\Item;
use Velk\Models\Page;
use Velk\Models\Term;
use Velk\Sitemap;

/**
 * Num idioma que não é o padrão, o endereço de um post, termo ou página usa o
 * slug traduzido — e, quando não há slug traduzido naquele idioma, o slug do
 * idioma padrão. Cada coisa tem um único endereço por idioma:
 *
 *   sem slug em inglês:  /post/meu-post (pt)  e  /en/post/meu-post (en)
 *   com slug em inglês:  /post/meu-post (pt)  e  /en/post/my-post  (en);
 *                        /en/post/meu-post dá 404.
 *
 * Num conflito — o slug traduzido de um post é o slug padrão (sem tradução)
 * de outro —, o slug traduzido ganha. É a mesma regra que o prefixo do tipo
 * (`post` → `news`) já seguia. Até a v3.8.x, sem slug traduzido o conteúdo
 * não tinha endereço nenhum naquele idioma.
 */

require_once dirname(__DIR__) . '/fixtures/slug_i18n_boot.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

// ── Posts (tipo de item sem hierarquia) ──────────────────────────────────

test('slug traduzido: post sem slug em inglês abre em /en pelo slug padrão', function () {
    sib_boot();
    $id = sib_item('post', 'meu-post');
    check_same($id, Item::findBySlugIn('post', 'meu-post', 'en', 'pt-BR')?->id);
});

test('slug traduzido: post com slug em inglês abre por ele, e o padrão dá 404 em inglês', function () {
    sib_boot();
    $id = sib_item('post', 'meu-post', ['en' => 'my-post']);
    check_same($id, Item::findBySlugIn('post', 'my-post', 'en', 'pt-BR')?->id);
    check_same(null, Item::findBySlugIn('post', 'meu-post', 'en', 'pt-BR'), 'um só endereço por idioma');
    // No idioma padrão nada muda.
    check_same($id, Item::findBySlugIn('post', 'meu-post', 'pt-BR', 'pt-BR')?->id);
    check_same(null, Item::findBySlugIn('post', 'my-post', 'pt-BR', 'pt-BR'));
});

test('slug traduzido: a tradução de um idioma não vale para outro', function () {
    sib_boot();
    $id = sib_item('post', 'meu-post', ['en' => 'my-post']);
    check_same($id, Item::findBySlugIn('post', 'meu-post', 'es', 'pt-BR')?->id);
    check_same(null, Item::findBySlugIn('post', 'my-post', 'es', 'pt-BR'));
});

test('slug traduzido: tradução em branco conta como sem tradução', function () {
    sib_boot();
    $id = sib_item('post', 'meu-post', ['en' => '']);
    check_same($id, Item::findBySlugIn('post', 'meu-post', 'en', 'pt-BR')?->id);
});

test('slug traduzido: tradução null conta como sem tradução (no MySQL, JSON null vira o texto "null")', function () {
    sib_boot('en');
    $id = sib_item('post', 'meu-post', ['en' => null]);
    check_same($id, Item::findBySlugIn('post', 'meu-post', 'en', 'pt-BR')?->id);
    check_same('/en/post/meu-post', Item::find($id)->url());
    $termo = sib_term('categoria', 'noticias', ['en' => null]);
    check_same($termo, Term::findBySlugIn('categoria', 'noticias', 'en', 'pt-BR')?->id);
    sib_page('promo', ['en' => null], 'promo', 'landing');
    check_same('promo', Page::resolveByUrl('/promo', App::instance()->config, 'en')['key'] ?? null);
});

test('slug traduzido: no conflito, o slug traduzido ganha do slug padrão de outro post', function () {
    sib_boot();
    $traduzido = sib_item('post', 'outro-post', ['en' => 'my-post']);
    sib_item('post', 'my-post');
    check_same($traduzido, Item::findBySlugIn('post', 'my-post', 'en', 'pt-BR')?->id);
    // Na ordem inversa de criação, também.
    sib_boot();
    sib_item('post', 'my-post');
    $traduzido = sib_item('post', 'outro-post', ['en' => 'my-post']);
    check_same($traduzido, Item::findBySlugIn('post', 'my-post', 'en', 'pt-BR')?->id);
});

test('slug traduzido: rascunho não toma o endereço de um post publicado', function () {
    sib_boot();
    // O rascunho tem o slug traduzido "my-post"; o publicado, o slug padrão.
    sib_item('post', 'rascunho', ['en' => 'my-post'], null, 'draft');
    $publicado = sib_item('post', 'my-post');
    check_same($publicado, Item::findBySlugIn('post', 'my-post', 'en', 'pt-BR')?->id);
    // Criados na ordem inversa, também.
    sib_boot();
    $publicado = sib_item('post', 'my-post');
    sib_item('post', 'rascunho', ['en' => 'my-post'], null, 'draft');
    check_same($publicado, Item::findBySlugIn('post', 'my-post', 'en', 'pt-BR')?->id);
    // Sem disputa, o rascunho continua achável pelo endereço dele (prévia no painel).
    sib_boot();
    $rascunho = sib_item('post', 'rascunho', ['en' => 'draft-post'], null, 'draft');
    check_same($rascunho, Item::findBySlugIn('post', 'draft-post', 'en', 'pt-BR')?->id);
    // Entre dois rascunhos, o traduzido ganha, como entre dois publicados.
    sib_boot();
    $traduzido = sib_item('post', 'a', ['en' => 'my-post'], null, 'draft');
    sib_item('post', 'my-post', [], null, 'draft');
    check_same($traduzido, Item::findBySlugIn('post', 'my-post', 'en', 'pt-BR')?->id);
});

test('slug traduzido: em hierarquia, rascunho não toma o nível de um item publicado', function () {
    sib_boot();
    sib_item('docs', 'outro', ['en' => 'guia'], null, 'draft');
    $publicado = sib_item('docs', 'guia');
    check_same($publicado, Item::findByPath('docs', ['guia'], 'en', 'pt-BR')?->id);
    $intro = sib_item('docs', 'intro', [], $publicado);
    check_same($intro, Item::findByPath('docs', ['guia', 'intro'], 'en', 'pt-BR')?->id);
});

test('slug traduzido: o link do post em inglês usa o slug padrão quando falta a tradução', function () {
    sib_boot('en');
    $sem = Item::find(sib_item('post', 'meu-post'));
    $com = Item::find(sib_item('post', 'outro', ['en' => 'other']));
    check_same('/en/post/meu-post', $sem->url());
    check_same('/en/post/other', $com->url());
});

// ── Tipo de item com hierarquia ──────────────────────────────────────────

test('slug traduzido: em hierarquia, cada nível usa o seu slug traduzido ou o padrão', function () {
    sib_boot('en');
    $guia = sib_item('docs', 'guia');
    $intro = sib_item('docs', 'intro', ['en' => 'introduction'], $guia);

    check_same($guia, Item::findChild('docs', null, 'guia', 'en', 'pt-BR')?->id);
    check_same($intro, Item::findChild('docs', $guia, 'introduction', 'en', 'pt-BR')?->id);
    check_same(null, Item::findChild('docs', $guia, 'intro', 'en', 'pt-BR'));

    check_same('/docs/guia/introduction', Item::fullPath(Item::find($intro), App::instance()->config, 'en'));
    check_same('/docs/guia/intro', Item::fullPath(Item::find($intro), App::instance()->config, 'es'));
    check_same('/en/docs/guia/introduction', Item::find($intro)->url());
});

test('slug traduzido: em hierarquia, o conflito decide nível a nível, sem perder a filha do outro ramo', function () {
    sib_boot('en');
    // Dois itens-raiz disputam /en/docs/guia: B, traduzido, ganha.
    $a = sib_item('docs', 'guia');
    $b = sib_item('docs', 'outro', ['en' => 'guia']);
    check_same($b, Item::findByPath('docs', ['guia'], 'en', 'pt-BR')?->id);
    // A filha de A só existe no ramo de A: o endereço dela abre nela.
    $intro = sib_item('docs', 'intro', [], $a);
    check_same('/en/docs/guia/intro', Item::find($intro)->url());
    check_same($intro, Item::findByPath('docs', ['guia', 'intro'], 'en', 'pt-BR')?->id);
    // Se B também tem uma filha intro, a de B ganha: B venceu o nível de cima.
    $introB = sib_item('docs', 'intro', [], $b);
    check_same($introB, Item::findByPath('docs', ['guia', 'intro'], 'en', 'pt-BR')?->id);
    check_same(null, Item::findByPath('docs', ['guia', 'nada'], 'en', 'pt-BR'));
    check_same($a, Item::findByPath('docs', ['guia'], 'pt-BR', 'pt-BR')?->id);
});

// ── Termos ───────────────────────────────────────────────────────────────

test('slug traduzido: termo segue a mesma regra, com prioridade para o traduzido', function () {
    sib_boot('en');
    $sem = sib_term('categoria', 'noticias');
    $com = sib_term('categoria', 'eventos', ['en' => 'events']);
    $conflito = sib_term('categoria', 'events');

    check_same($sem, Term::findBySlugIn('categoria', 'noticias', 'en', 'pt-BR')?->id);
    check_same($com, Term::findBySlugIn('categoria', 'events', 'en', 'pt-BR')?->id, 'o traduzido ganha do padrão do outro');
    check_same(null, Term::findBySlugIn('categoria', 'eventos', 'en', 'pt-BR'));
    check_same($conflito, Term::findBySlugIn('categoria', 'events', 'pt-BR', 'pt-BR')?->id);

    check_same('/en/categoria/noticias', Term::find($sem)->url());
    check_same('/en/categoria/events', Term::find($com)->url());
});

test('slug traduzido: termo em hierarquia usa o slug de cada nível', function () {
    sib_boot('en');
    $pai = sib_term('secao', 'produtos', ['en' => 'products']);
    $filho = sib_term('secao', 'cadeiras', [], $pai);
    check_same($filho, Term::findChild('secao', $pai, 'cadeiras', 'en', 'pt-BR')?->id);
    check_same('/secao/products/cadeiras', Term::fullPath(Term::find($filho), App::instance()->config, 'en'));
    check_same('/en/secao/products/cadeiras', Term::find($filho)->url());
});

test('slug traduzido: termo em hierarquia decide o conflito nível a nível', function () {
    sib_boot('en');
    $a = sib_term('secao', 'moveis');
    $b = sib_term('secao', 'outra', ['en' => 'moveis']);
    $filha = sib_term('secao', 'cadeiras', [], $a);
    check_same($b, Term::findByPath('secao', ['moveis'], 'en', 'pt-BR')?->id);
    check_same($filha, Term::findByPath('secao', ['moveis', 'cadeiras'], 'en', 'pt-BR')?->id);
    $filhaB = sib_term('secao', 'cadeiras', [], $b);
    check_same($filhaB, Term::findByPath('secao', ['moveis', 'cadeiras'], 'en', 'pt-BR')?->id);
});

// ── Páginas ──────────────────────────────────────────────────────────────

test('slug traduzido: página do site.json sem tradução abre em /en pelo endereço padrão', function () {
    $app = sib_boot('en');
    $r = Page::resolveByUrl('/sobre', $app->config, 'en');
    check_same('sobre', $r['key'] ?? null);
    check_same('/sobre', Page::fullPath('sobre', $app->config, 'en'));
});

test('slug traduzido: página do site.json traduzida abre pelo slug traduzido e não pelo padrão', function () {
    $app = sib_boot('en');
    sib_page('sobre', ['en' => 'about']);
    check_same('sobre', Page::resolveByUrl('/about', $app->config, 'en')['key'] ?? null);
    check_same(null, Page::resolveByUrl('/sobre', $app->config, 'en'));
    check_same('/about', Page::fullPath('sobre', $app->config, 'en'));
});

test('slug traduzido: subpágina do site.json mistura o slug traduzido do pai com o padrão dela', function () {
    $app = sib_boot('en');
    sib_page('servicos', ['en' => 'services']);
    check_same('/services/consultoria', Page::fullPath('consultoria', $app->config, 'en'));
    check_same('consultoria', Page::resolveByUrl('/services/consultoria', $app->config, 'en')['key'] ?? null);
    check_same(null, Page::resolveByUrl('/servicos/consultoria', $app->config, 'en'));
});

test('slug traduzido: página criada pelo painel segue a mesma regra', function () {
    $app = sib_boot('en');
    sib_page('promo', [], 'promo', 'landing');
    sib_page('oferta', ['en' => 'offer'], 'oferta', 'landing');
    check_same('promo', Page::resolveByUrl('/promo', $app->config, 'en')['key'] ?? null);
    check_same('oferta', Page::resolveByUrl('/offer', $app->config, 'en')['key'] ?? null);
    check_same(null, Page::resolveByUrl('/oferta', $app->config, 'en'));
    check_same('/en/promo', Page::findByKey('promo')->url());
});

test('slug traduzido: página — no conflito, o slug traduzido ganha', function () {
    $app = sib_boot('en');
    sib_page('a', ['en' => 'promo'], 'pagina-a', 'landing');
    sib_page('b', [], 'promo', 'landing');
    check_same('a', Page::resolveByUrl('/promo', $app->config, 'en')['key'] ?? null);
    // Uma página do site.json sem tradução cujo endereço padrão é o slug
    // traduzido de uma página do painel também perde para ela.
    sib_page('c', ['en' => 'sobre'], 'pagina-c', 'landing');
    check_same('c', Page::resolveByUrl('/sobre', $app->config, 'en')['key'] ?? null);
});

test('slug traduzido: página — o conflito decide nível a nível, não só pela última página', function () {
    $app = sib_boot('en');
    // servicos (site.json) é /en/services; uma página do painel com slug
    // padrão "services", sem tradução, perde esse endereço para ela.
    sib_page('servicos', ['en' => 'services']);
    sib_page('svc', [], 'services', 'landing');
    // A filha da página do painel tem o slug traduzido "consultoria" — o
    // mesmo endereço da subpágina consultoria do site.json, sem tradução.
    sib_page('c1', ['en' => 'consultoria'], 'c1', 'landing', 'svc');
    check_same('servicos', Page::resolveByUrl('/services', $app->config, 'en')['key'] ?? null);
    check_same('consultoria', Page::resolveByUrl('/services/consultoria', $app->config, 'en')['key'] ?? null);
    // Uma filha que só existe no ramo que perdeu continua abrindo.
    sib_page('extra', [], 'extra', 'landing', 'svc');
    check_same('extra', Page::resolveByUrl('/services/extra', $app->config, 'en')['key'] ?? null);
});

// ── Seletor de idioma e sitemap ──────────────────────────────────────────

test('slug traduzido: o seletor de idioma leva ao endereço do conteúdo no outro idioma', function () {
    $app = sib_boot('pt-BR');
    sib_item('post', 'meu-post');
    sib_item('post', 'outro', ['en' => 'other']);
    check_same('/post/meu-post', _VelkPathTranslator::translate('/post/meu-post', 'en', $app));
    check_same('/post/other', _VelkPathTranslator::translate('/post/outro', 'en', $app));
    sib_term('categoria', 'noticias');
    check_same('/categoria/noticias', _VelkPathTranslator::translate('/categoria/noticias', 'en', $app));
    check_same('/sobre', _VelkPathTranslator::translate('/sobre', 'en', $app));
});

test('slug traduzido: o seletor de idioma remonta o endereço de item e termo em hierarquia', function () {
    $app = sib_boot('pt-BR');
    $guia = sib_item('docs', 'guia');
    sib_item('docs', 'intro', ['en' => 'introduction'], $guia);
    check_same('/docs/guia/introduction', _VelkPathTranslator::translate('/docs/guia/intro', 'en', $app));
    $pai = sib_term('secao', 'produtos', ['en' => 'products']);
    sib_term('secao', 'cadeiras', [], $pai);
    check_same('/secao/products/cadeiras', _VelkPathTranslator::translate('/secao/produtos/cadeiras', 'en', $app));
    // De volta para o idioma padrão, a partir do inglês.
    sib_i18n_current('en');
    check_same('/docs/guia/intro', _VelkPathTranslator::translate('/docs/guia/introduction', 'pt-BR', $app));
});

test('slug traduzido: no idioma padrão, o path usa a coluna slug mesmo com tradução sobrando no idioma padrão', function () {
    $app = sib_boot('pt-BR');
    $guia = sib_item('docs', 'guia', ['pt-BR' => 'velho']);
    check_same('/docs/guia', Item::fullPath(Item::find($guia), $app->config));
    $pai = sib_term('secao', 'produtos', ['pt-BR' => 'velho']);
    check_same('/secao/produtos', Term::fullPath(Term::find($pai), $app->config));
});

test('slug traduzido: no idioma padrão, o link de post e termo sem hierarquia usa a coluna slug', function () {
    sib_boot('pt-BR');
    $post = Item::find(sib_item('post', 'meu-post', ['pt-BR' => 'velho']));
    check_same('/post/meu-post', $post->url());
    $termo = Term::find(sib_term('categoria', 'noticias', ['pt-BR' => 'velho']));
    check_same('/categoria/noticias', $termo->url());
});

test('slug traduzido: o sitemap lista o endereço em inglês também do que não tem slug traduzido', function () {
    $app = sib_boot('pt-BR');
    sib_item('post', 'meu-post');
    sib_item('post', 'outro', ['en' => 'other']);
    $xml = Sitemap::generate($app->config);
    check(str_contains($xml, 'hreflang="en" href="https://site.test/en/post/meu-post"'), 'post sem tradução');
    check(str_contains($xml, 'hreflang="en" href="https://site.test/en/post/other"'), 'post traduzido');
    check(str_contains($xml, 'hreflang="en" href="https://site.test/en/sobre"'), 'página do site.json sem tradução');
});

test('slug traduzido: no sitemap, quem perde a disputa de slug fica sem a versão naquele idioma', function () {
    $app = sib_boot('pt-BR');
    sib_item('post', 'my-post');
    sib_item('post', 'outro', ['en' => 'my-post']);
    $xml = Sitemap::generate($app->config);
    preg_match_all('~<url>.*?</url>~s', $xml, $m);
    $porLoc = [];
    foreach ($m[0] as $url) {
        preg_match('~<loc>([^<]+)</loc>~', $url, $loc);
        $porLoc[$loc[1]] = $url;
    }
    $a = $porLoc['https://site.test/post/my-post'] ?? '';
    $b = $porLoc['https://site.test/post/outro'] ?? '';
    check(!str_contains($a, 'hreflang="en"'), 'A perde em inglês: sem alternate en');
    check(str_contains($a, 'hreflang="es" href="https://site.test/es/post/my-post"'), 'A segue em espanhol');
    check(str_contains($b, 'hreflang="en" href="https://site.test/en/post/my-post"'), 'B tem o en');
});

test('slug traduzido: o sitemap não faz mais consultas conforme cresce o número de posts', function () {
    $conta = function (int $posts): int {
        $app = sib_boot('pt-BR');
        $guia = sib_item('docs', 'guia', ['en' => 'guide']);
        for ($i = 0; $i < $posts; $i++) {
            sib_item('post', "post-{$i}", $i % 2 ? ['en' => "post-en-{$i}"] : []);
            sib_item('docs', "doc-{$i}", [], $guia);
            sib_term('categoria', "termo-{$i}");
        }
        $antes = sib_queries();
        Sitemap::generate($app->config);
        return sib_queries() - $antes;
    };
    $poucos = $conta(5);
    $muitos = $conta(40);
    check_same($poucos, $muitos, "consultas com 5 posts: {$poucos}; com 40: {$muitos}");
});
