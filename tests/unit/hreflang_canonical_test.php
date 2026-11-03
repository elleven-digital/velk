<?php

declare(strict_types=1);

/**
 * the_canonical(), the_hreflangs() e lang_url() montam a URL da página atual
 * em cada idioma — o mesmo endereço em que o roteador responde.
 *
 * Até a v3.8.x o canonical e os hreflang passavam por url() duas vezes: o
 * prefixo do idioma (e o base path, numa instalação em subpasta) saía
 * repetido — `/en/en/post/my-post`. E o conteúdo que perde uma disputa de
 * slug (o slug padrão dele é o slug traduzido de outro) não tem endereço
 * naquele idioma: o hreflang dele fica de fora, e o seletor leva à home.
 */

require_once dirname(__DIR__) . '/fixtures/slug_i18n_boot.php';
require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('hc_links')) {
    /** @return array<string, string> hreflang => href */
    function hc_links(): array
    {
        preg_match_all('~hreflang="([^"]+)" href="([^"]+)"~', the_hreflangs(), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $lang, $href]) {
            $out[$lang] = $href;
        }
        return $out;
    }
}

test('canonical: o idioma atual entra uma vez só', function () {
    sib_boot('en');
    sib_item('post', 'meu-post', ['en' => 'my-post']);
    $_SERVER['REQUEST_URI'] = '/en/post/my-post';
    check_same('https://site.test/en/post/my-post', the_canonical());
    sib_i18n_current('pt-BR');
    $_SERVER['REQUEST_URI'] = '/post/meu-post';
    check_same('https://site.test/post/meu-post', the_canonical());
});

test('hreflang: cada idioma com o endereço dele, a partir de qualquer idioma', function () {
    sib_boot('en');
    sib_item('post', 'meu-post', ['en' => 'my-post']);
    $_SERVER['REQUEST_URI'] = '/en/post/my-post';
    check_same([
        'pt-BR' => 'https://site.test/post/meu-post',
        'en' => 'https://site.test/en/post/my-post',
        'es' => 'https://site.test/es/post/meu-post',
        'x-default' => 'https://site.test/post/meu-post',
    ], hc_links());
});

test('canonical e hreflang: instalação em subpasta leva o base path uma vez só', function () {
    $app = sib_boot('pt-BR');
    $app->basePath = '/velk';
    sib_item('post', 'meu-post', ['en' => 'my-post']);
    $_SERVER['REQUEST_URI'] = '/velk/post/meu-post';
    check_same('https://site.test/velk/post/meu-post', the_canonical());
    check_same('https://site.test/velk/en/post/my-post', hc_links()['en'] ?? null);
    check_same('/velk/en/post/my-post', lang_url('en'));
});

test('hreflang: o conteúdo que perde a disputa de slug fica sem o idioma em que perdeu', function () {
    sib_boot('pt-BR');
    // A tem o slug padrão "my-post" e nenhum em inglês; B tem "my-post" em
    // inglês. Em /en/post/my-post abre B, então A não tem endereço em inglês.
    sib_item('post', 'my-post');
    sib_item('post', 'outro', ['en' => 'my-post']);
    $_SERVER['REQUEST_URI'] = '/post/my-post';
    $links = hc_links();
    check(!isset($links['en']), 'sem hreflang en para quem perdeu');
    check_same('https://site.test/es/post/my-post', $links['es'] ?? null);
    check_same('/en/', lang_url('en'), 'o seletor leva à home do idioma');
    // B, o que ganhou, continua com o endereço em inglês.
    $_SERVER['REQUEST_URI'] = '/post/outro';
    check_same('https://site.test/en/post/my-post', hc_links()['en'] ?? null);
});

test('ownsUrlIn: quem responde pelo próprio endereço no idioma', function () {
    $app = sib_boot('pt-BR');
    $a = \Velk\Models\Item::find(sib_item('post', 'my-post'));
    $b = \Velk\Models\Item::find(sib_item('post', 'outro', ['en' => 'my-post']));
    check(!$a->ownsUrlIn('en'), 'A perde em inglês');
    check($a->ownsUrlIn('pt-BR') && $a->ownsUrlIn('es'), 'A ganha nos outros');
    check($b->ownsUrlIn('en'));
    $guia = sib_item('docs', 'guia');
    $intro = \Velk\Models\Item::find(sib_item('docs', 'intro', ['en' => 'introduction'], $guia));
    check($intro->ownsUrlIn('en') && $intro->ownsUrlIn('pt-BR'), 'hierarquia');
    $t1 = \Velk\Models\Term::find(sib_term('categoria', 'events'));
    $t2 = \Velk\Models\Term::find(sib_term('categoria', 'eventos', ['en' => 'events']));
    check(!$t1->ownsUrlIn('en') && $t2->ownsUrlIn('en'), 'termos');
    sib_page('promo', [], 'promo', 'landing');
    sib_page('oferta', ['en' => 'promo'], 'oferta', 'landing');
    check(!\Velk\Models\Page::ownsUrlIn('promo', $app->config, 'en'), 'página que perdeu');
    check(\Velk\Models\Page::ownsUrlIn('oferta', $app->config, 'en'));
    check(\Velk\Models\Page::ownsUrlIn('sobre', $app->config, 'en'), 'página do site.json');
});

test('canonical e hreflang: sem APP_URL, a origem vem da requisição — sempre absolutos', function () {
    $app = sib_boot('pt-BR');
    (new ReflectionProperty(\Velk\Config::class, 'env'))->setValue($app->config, []);
    sib_item('post', 'meu-post');
    $_SERVER['REQUEST_URI'] = '/post/meu-post';
    $_SERVER['HTTP_HOST'] = 'exemplo.test';
    unset($_SERVER['HTTPS']);
    check_same('http://exemplo.test/post/meu-post', the_canonical());
    check_same('http://exemplo.test/en/post/meu-post', hc_links()['en'] ?? null);
});

test('hreflang: a página de 404 não anuncia versões', function () {
    sib_boot('pt-BR');
    $_SERVER['REQUEST_URI'] = '/nao-existe';
    $flag = new ReflectionProperty(\Velk\FrontRouter::class, 'notFound');
    $flag->setValue(null, true);
    try {
        check_same('', the_hreflangs());
    } finally {
        $flag->setValue(null, false);
    }
    check(the_hreflangs() !== '', 'fora da 404, os hreflang voltam');
});

test('aviso de slug disputado: o controller monta a mensagem sem escapar (a view escapa)', function () {
    $src = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/Controllers/Controller.php');
    $start = strpos($src, 'protected function flashSlugConflicts');
    $body = substr($src, (int) $start, 900);
    check(str_contains($body, "__raw(\$c['lost'] ? 'slugs.conflict_lost' : 'slugs.conflict_taken'"), 'usa __raw');
    $layout = (string) file_get_contents(dirname(__DIR__, 2) . '/core/Admin/views/layout/main.php');
    check(str_contains($layout, '<div class="flash flash--warning"><?= e((string) $warning) ?></div>'), 'a view escapa uma vez');
});
