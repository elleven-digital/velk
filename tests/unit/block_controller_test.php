<?php

declare(strict_types=1);

use Velk\Admin\AdminRouter;
use Velk\Admin\Controllers\BlockController;
use Velk\Router;
use Velk\Services\ImageVariantService;

/**
 * A janela de um bloco do tema, aberta pelo editor de texto do painel.
 *
 * Duas rotas, as duas por POST, com login e CSRF: `blocks/form` desenha a
 * escolha ou o formulário do bloco, e `blocks/save` devolve os valores
 * normalizados à página de fora. Nenhuma grava nada: os dados do bloco vivem
 * no texto do campo.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/block_boot.php';

if (!function_exists('bct_blocks')) {
    function bct_blocks(): array
    {
        return [
            'bignumbers' => ['label' => 'Big numbers', 'fields' => [
                ['name' => 'titulo', 'type' => 'text', 'label' => 'Título'],
                ['name' => 'destaque', 'type' => 'boolean', 'label' => 'Destaque'],
                ['name' => 'itens', 'type' => 'repeater', 'label' => 'Números', 'fields' => [
                    ['name' => 'valor', 'type' => 'text', 'label' => 'Número'],
                    ['name' => 'rotulo', 'type' => 'text', 'label' => 'Rótulo'],
                ]],
            ]],
            'video' => ['label' => 'Vídeo', 'fields' => [
                ['name' => 'url', 'type' => 'url', 'label' => 'Endereço'],
                ['name' => 'legenda', 'type' => 'richtext', 'label' => 'Legenda', 'blocks' => ['bignumbers']],
            ]],
        ];
    }

    /** O `{type, data}` que a tela de resposta entrega à página de fora. */
    function bct_result(string $html): mixed
    {
        check(preg_match('#<script type="application/json" id="velk-block-result">(.*?)</script>#s', $html, $m) === 1, 'resposta sem o bloco de dados');
        return json_decode($m[1], true);
    }
}

test('janela do bloco: sem o tipo, não há o que abrir', function () {
    // A escolha do bloco é na barra do editor (a faixa dos blocos); a janela
    // sempre abre já sabendo qual é.
    $app = blkb_boot(bct_blocks());
    $response = (new BlockController())->form(blkb_post($app, '/admin/blocks/form', []));
    check_same(404, $response->getStatus());
    check(!str_contains($response->getBody(), 'block-choose'), 'sem a antiga tela de escolha');
});

test('janela do bloco: com o tipo escolhido, abre o formulário dele', function () {
    $app = blkb_boot(bct_blocks());
    $response = (new BlockController())->form(blkb_post($app, '/admin/blocks/form', ['type' => 'bignumbers']));
    $html = $response->getBody();

    check_same(200, $response->getStatus());
    check(str_contains($html, '<body class="embed"'), 'a janela usa o layout embutido');
    check(str_contains($html, 'name="_csrf" value="' . $app->session->csrfToken() . '"'), 'sem o token de CSRF');
    // O nome do bloco fica no cabeçalho da janela; a página não o repete.
    check(!str_contains($html, 'picker-header'), 'título duplicado dentro da janela');
    check(str_contains($html, 'action="/admin/blocks/save"'), $html);
    check(str_contains($html, '<input type="hidden" name="type" value="bignumbers">'), $html);
    check(str_contains($html, 'name="block[titulo]"'), 'os campos do bloco, com o prefixo block');
    check(str_contains($html, 'data-repeater'), 'o repetidor do bloco');
    check(str_contains($html, '>Save block</button>'), $html);
});

test('janela do bloco: ao editar, o formulário vem preenchido com os dados recebidos', function () {
    $app = blkb_boot(bct_blocks());
    $data = ['titulo' => 'Em "números"', 'destaque' => true, 'itens' => [['valor' => '120+', 'rotulo' => 'Clientes ativos']]];
    $html = (new BlockController())->form(blkb_post($app, '/admin/blocks/form', [
        'type' => 'bignumbers',
        'data' => json_encode($data),
    ]))->getBody();

    check(str_contains($html, 'name="block[titulo]"') && str_contains($html, 'value="Em &quot;números&quot;"'), $html);
    check(str_contains($html, 'value="120+"') && str_contains($html, 'value="Clientes ativos"'), 'a linha do repetidor');
    check(preg_match('/name="block\[destaque\]"[^>]*\bchecked\b|\bchecked\b[^>]*name="block\[destaque\]"/', $html) === 1, 'o booleano marcado');
});

test('janela do bloco: dados que não são um objeto JSON abrem o formulário vazio', function () {
    $app = blkb_boot(bct_blocks());
    // `['x']` é um `data[]=x` forjado: também abre o formulário vazio, e sem
    // deixar aviso de PHP no log.
    $avisos = [];
    set_error_handler(function (int $numero, string $mensagem) use (&$avisos): bool {
        $avisos[] = $mensagem;
        return true;
    });
    try {
        foreach (['{quebrado', '"texto"', '', ['x']] as $data) {
            $caso = is_string($data) ? $data : 'lista';
            $response = (new BlockController())->form(blkb_post($app, '/admin/blocks/form', ['type' => 'bignumbers', 'data' => $data]));
            check_same(200, $response->getStatus(), $caso);
            check(str_contains($response->getBody(), 'name="block[titulo]"'), $caso);
        }
    } finally {
        restore_error_handler();
    }
    check_same([], $avisos);
});

test('janela do bloco: bloco que não existe no site.json é 404', function () {
    $app = blkb_boot(bct_blocks());
    $controller = new BlockController();
    check_same(404, $controller->form(blkb_post($app, '/admin/blocks/form', ['type' => 'nao_existe']))->getStatus());
    check_same(404, $controller->form(blkb_post($app, '/admin/blocks/form', ['type' => ['bignumbers']]))->getStatus(), 'tipo que não é texto');
    check_same(404, $controller->form(blkb_post($app, '/admin/blocks/form', ['type' => '../x']))->getStatus(), 'tipo que não é chave de bloco');
    check_same(404, $controller->save(blkb_post($app, '/admin/blocks/save', ['type' => 'nao_existe']))->getStatus());
});

test('janela do bloco: um campo de texto do bloco não oferece blocos', function () {
    $app = blkb_boot(bct_blocks());
    $html = (new BlockController())->form(blkb_post($app, '/admin/blocks/form', ['type' => 'video']))->getBody();
    check(str_contains($html, 'name="block[legenda]"'), 'o campo de texto do bloco está lá');
    check(!str_contains($html, 'data-cmd="block"'), 'sem bloco dentro de bloco');
});

test('janela do bloco: salvar devolve o tipo e os valores normalizados', function () {
    $app = blkb_boot(bct_blocks());
    $response = (new BlockController())->save(blkb_post($app, '/admin/blocks/save', [
        'type' => 'bignumbers',
        'block' => [
            'titulo' => 'Fim </script><script>alert(1)</script>',
            // `destaque` desmarcado não vem no POST; `sobra` não é campo do bloco.
            'sobra' => 'x',
            'itens' => [
                3 => ['valor' => '120+', 'rotulo' => 'Clientes ativos'],
                7 => ['valor' => '98%', 'rotulo' => 'Renovação'],
            ],
        ],
    ]));
    $html = $response->getBody();

    check_same(200, $response->getStatus());
    check(str_contains($html, '<body class="embed"'), 'a resposta também é uma página embutida');
    check_same(
        ['type' => 'bignumbers', 'data' => [
            'titulo' => 'Fim </script><script>alert(1)</script>',
            'destaque' => false,
            'itens' => [['valor' => '120+', 'rotulo' => 'Clientes ativos'], ['valor' => '98%', 'rotulo' => 'Renovação']],
        ]],
        bct_result($html)
    );
    check(!str_contains($html, '<script>alert(1)</script>'), 'o dado não vira script na página');
});

test('janela do bloco: sem login vai para a tela de entrada; sem CSRF é recusado', function () {
    $visitante = blkb_boot(bct_blocks(), false);
    foreach (['form', 'save'] as $acao) {
        $response = (new BlockController())->$acao(blkb_post($visitante, '/admin/blocks/' . $acao, ['type' => 'bignumbers']));
        check_same(302, $response->getStatus(), $acao);
        check_same('/admin/login', $response->getHeader('Location'), $acao);
    }

    // A janela é um iframe: o navegador manda Sec-Fetch-Dest: iframe, e a
    // falha de CSRF responde ali mesmo (não redireciona para o editor).
    $app = blkb_boot(bct_blocks());
    foreach (['form', 'save'] as $acao) {
        $request = new \Velk\Request('POST', '/admin/blocks/' . $acao, [], ['_csrf' => 'token-errado', 'type' => 'bignumbers'], [], ['HTTP_SEC_FETCH_DEST' => 'iframe'], []);
        $response = (new BlockController())->$acao($request);
        check_same(403, $response->getStatus(), $acao);
        check(!str_contains($response->getBody(), 'block['), 'nada do formulário sem o token');
    }
});

test('janela do bloco: as telas respondem com a política de conteúdo do painel', function () {
    $app = blkb_boot(bct_blocks());
    $controller = new BlockController();
    $telas = [
        'formulário' => $controller->form(blkb_post($app, '/admin/blocks/form', ['type' => 'bignumbers'])),
        'resposta' => $controller->save(blkb_post($app, '/admin/blocks/save', ['type' => 'bignumbers', 'block' => ['titulo' => 'x']])),
    ];
    foreach ($telas as $nome => $response) {
        // Sem script embutido: por isso a resposta do `save` vai num bloco de dados.
        check(str_contains((string) $response->getHeader('Content-Security-Policy'), "script-src 'self'"), $nome);
    }
});

test('janela do bloco: as duas rotas estão no painel, só por POST', function () {
    $app = blkb_boot(bct_blocks());
    $router = new Router();
    AdminRouter::register($router, $app->config);

    $form = $router->dispatch(blkb_post($app, '/admin/blocks/form', ['type' => 'bignumbers']));
    check(str_contains($form->getBody(), 'name="block[titulo]"'), 'blocks/form não chegou ao controlador');
    $save = $router->dispatch(blkb_post($app, '/admin/blocks/save', ['type' => 'bignumbers', 'block' => ['titulo' => 'x']]));
    check_same('x', bct_result($save->getBody())['data']['titulo']);

    // Por GET o token de CSRF não é conferido: a janela não abre, nem com o tipo na URL.
    $get = $router->dispatch(new \Velk\Request('GET', '/admin/blocks/form', ['type' => 'bignumbers'], [], [], [], []));
    check_same(404, $get->getStatus(), 'a janela não abre por GET');
    check(!str_contains($get->getBody(), 'block['), 'a janela não abre por GET');
});

test('janela do bloco: salvar gera a variante da imagem escolhida no bloco', function () {
    if (!ImageVariantService::gdAvailable()) {
        return; // sem GD o motor não gera variante nenhuma (mesma regra do variant_service_test)
    }
    $uploads = sys_get_temp_dir() . '/velk_bct_' . bin2hex(random_bytes(4));
    mkdir($uploads, 0777, true);
    $img = imagecreatetruecolor(400, 300);
    imagepng($img, $uploads . '/foto.png');

    $blocks = ['depoimento' => ['label' => 'Depoimento', 'fields' => [
        ['name' => 'foto', 'type' => 'image', 'label' => 'Foto', 'variants' => [['name' => 'depo', 'width' => 80, 'height' => 80, 'fit' => 'crop']]],
    ]]];
    $app = blkb_boot($blocks, true, ['uploads' => $uploads]);
    $app->db->pdo()->exec("INSERT INTO media (id, filename, original_name, mime, size, width, height, created_at) VALUES (5, 'foto.png', 'foto.png', 'image/png', 100, 400, 300, '2026-01-01 00:00:00')");

    try {
        $response = (new BlockController())->save(blkb_post($app, '/admin/blocks/save', ['type' => 'depoimento', 'block' => ['foto' => '5']]));
        check_same(200, $response->getStatus());
        check(is_file(ImageVariantService::variantPath($uploads, 'depo', 'foto.png')), 'a variante não foi gerada ao salvar o bloco');
    } finally {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($uploads);
    }
});
