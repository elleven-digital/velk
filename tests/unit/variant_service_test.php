<?php

declare(strict_types=1);

use Velk\Services\ImageVariantService;
use Velk\Services\VariantService;

/**
 * T4 (image-variants): VariantService — geração de variantes por-campo
 * (image/gallery) no momento do save.
 *
 * `ensureForFields()` (o método público) depende de `Media::find()`
 * (banco real), então — mesmo padrão já usado em
 * front_router_template_fallback_test.php / not_found_page_test.php pra
 * evitar acoplar o teste a um banco — os testes abaixo exercitam via
 * Reflection as duas peças PRIVADAS que carregam toda a lógica: a
 * extração pura de ids (`extractMediaIds`) e a geração real de UMA
 * variante em disco (`ensureOne`, sem tocar banco — recebe caminhos já
 * resolvidos). Isso cobre extração de id, geração via GD, idempotência,
 * e os dois skips defensivos (nome "thumb", nome inválido) sem
 * depender de estado de banco. A integração ponta-a-ponta
 * (Media::find real + ensureForFields completo) foi verificada por
 * script descartável — ver task-4-report.md.
 */

if (!function_exists('vs_extract_media_ids')) {
    function vs_extract_media_ids(string $type, mixed $value): array
    {
        $method = new ReflectionMethod(VariantService::class, 'extractMediaIds');
        return $method->invoke(null, $type, $value);
    }
}

if (!function_exists('vs_collect_jobs')) {
    /**
     * @return array<int, array<string, mixed>> media id => nome da variante => def bruta
     */
    function vs_collect_jobs(array $defs, mixed $values): array
    {
        $method = new ReflectionMethod(VariantService::class, 'collectJobs');
        return $method->invoke(null, $defs, $values);
    }
}

if (!function_exists('vs_variant')) {
    /** Def de variante mínima e válida, pra deixar os testes do walk legíveis. */
    function vs_variant(string $name): array
    {
        return ['name' => $name, 'width' => 100, 'height' => 80, 'fit' => 'crop'];
    }
}

if (!function_exists('vs_ensure_one')) {
    function vs_ensure_one(string $uploadsPath, string $srcPath, string $filename, mixed $variant): void
    {
        $method = new ReflectionMethod(VariantService::class, 'ensureOne');
        $method->invoke(null, $uploadsPath, $srcPath, $filename, $variant);
    }
}

if (!function_exists('vs_tmp_dir')) {
    function vs_tmp_dir(): string
    {
        $dir = sys_get_temp_dir() . '/velk-variantsvc-' . uniqid();
        mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('vs_rrmdir')) {
    function vs_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? vs_rrmdir($path) : @unlink($path);
        }
        // Best-effort: mesmo tratamento de xattr-provenance transitório do
        // macOS usado em image_variant_service_test.php (iv_rrmdir).
        if (!@rmdir($dir)) {
            clearstatcache(true, $dir);
            @rmdir($dir);
        }
    }
}

if (!function_exists('vs_make_image')) {
    /** Cria um PNG sólido de teste. */
    function vs_make_image(string $path, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($im, 90, 140, 200);
        imagefill($im, 0, 0, $color);
        imagepng($im, $path, 9);
    }
}

// --- extractMediaIds: campo image (valor escalar) ---

test('extractMediaIds: image — numérico positivo (int ou string) vira [id]', function () {
    check_same([7], vs_extract_media_ids('image', '7'));
    check_same([7], vs_extract_media_ids('image', 7));
});

test('extractMediaIds: image — zero, negativo, não-numérico ou null vira []', function () {
    check_same([], vs_extract_media_ids('image', '0'));
    check_same([], vs_extract_media_ids('image', 0));
    check_same([], vs_extract_media_ids('image', -3));
    check_same([], vs_extract_media_ids('image', 'abc'));
    check_same([], vs_extract_media_ids('image', null));
});

// --- extractMediaIds: campo gallery (lista plana, ver FieldRenderer::collectGallery) ---

test('extractMediaIds: gallery — lista plana de ints positivos, preserva ordem', function () {
    check_same([3, 1, 9], vs_extract_media_ids('gallery', [3, 1, 9]));
});

test('extractMediaIds: gallery — filtra zero/negativo/não-numérico; valor não-array vira []', function () {
    check_same([2, 5], vs_extract_media_ids('gallery', [2, 0, -1, 'x', 5]));
    check_same([], vs_extract_media_ids('gallery', null));
    check_same([], vs_extract_media_ids('gallery', '5'));
});

// --- ensureOne: geração real via GD + idempotência + skips defensivos ---

test('ensureOne: gera o arquivo da variante nas dimensões esperadas', function () {
    if (!ImageVariantService::gdAvailable()) {
        return; // ambiente sem GD — nada a verificar, teste passa vazio.
    }
    $dir = vs_tmp_dir();
    $src = $dir . '/foto.png';
    vs_make_image($src, 1000, 1000);

    vs_ensure_one($dir, $src, 'foto.png', ['name' => 'card', 'width' => 100, 'height' => 80, 'fit' => 'crop']);

    $dest = ImageVariantService::variantPath($dir, 'card', 'foto.png');
    check(is_file($dest), 'arquivo da variante deveria existir em _variants/card/foto.png');
    $info = getimagesize($dest);
    check(is_array($info), 'getimagesize deveria ler o arquivo gerado');
    check_same(100, $info[0], 'largura');
    check_same(80, $info[1], 'altura');

    vs_rrmdir($dir);
});

test('ensureOne: idempotente — arquivo já existente nunca é sobrescrito', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = vs_tmp_dir();
    $src = $dir . '/foto.png';
    vs_make_image($src, 1000, 1000);
    $variant = ['name' => 'card', 'width' => 100, 'height' => 80, 'fit' => 'crop'];

    vs_ensure_one($dir, $src, 'foto.png', $variant);
    $dest = ImageVariantService::variantPath($dir, 'card', 'foto.png');
    check(is_file($dest), 'primeira chamada deveria gerar o arquivo');

    // Marca o arquivo com um conteúdo sentinela: se a segunda chamada
    // regenerasse (bug de idempotência), o sentinela seria perdido —
    // prova mais forte e determinística que comparar mtime (sem sleep()).
    file_put_contents($dest, 'SENTINEL');
    vs_ensure_one($dir, $src, 'foto.png', $variant);
    check_same('SENTINEL', (string) file_get_contents($dest), 'segunda chamada não deveria regravar o arquivo já existente');

    vs_rrmdir($dir);
});

test('ensureOne: nome "thumb" é sempre pulado (caixa global do upload, nunca por-campo)', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = vs_tmp_dir();
    $src = $dir . '/foto.png';
    vs_make_image($src, 1000, 1000);

    vs_ensure_one($dir, $src, 'foto.png', ['name' => 'thumb', 'width' => 100, 'height' => 100, 'fit' => 'contain']);

    $dest = ImageVariantService::variantPath($dir, 'thumb', 'foto.png');
    check(!is_file($dest), 'variante "thumb" declarada num campo não deveria ser gerada por ensureOne');

    vs_rrmdir($dir);
});

test('ensureOne: nome de variante fora do padrão ^[a-z0-9][a-z0-9-]*$ é pulado defensivamente', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = vs_tmp_dir();
    $src = $dir . '/foto.png';
    vs_make_image($src, 200, 200);

    foreach (['../evil', 'Card', '-card', 'card space', 'card/x'] as $badName) {
        vs_ensure_one($dir, $src, 'foto.png', ['name' => $badName, 'width' => 50, 'height' => 50, 'fit' => 'contain']);
    }
    check(!is_dir($dir . '/_variants'), 'nenhum nome inválido deveria criar diretório _variants');

    vs_rrmdir($dir);
});

test('ensureOne: variant sem "name" (ou não-array) é ignorado silenciosamente', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = vs_tmp_dir();
    $src = $dir . '/foto.png';
    vs_make_image($src, 200, 200);

    vs_ensure_one($dir, $src, 'foto.png', ['width' => 50, 'height' => 50]);
    vs_ensure_one($dir, $src, 'foto.png', 'not-an-array');
    check(!is_dir($dir . '/_variants'));

    vs_rrmdir($dir);
});

// --- collectJobs: pareia defs (árvore crua do site.json) com values -------
//
// O walk precisa descer nos MESMOS contêineres que FieldRenderer::collect()
// desce, senão um campo image/gallery aninhado nunca gera variante no save.
// São dois regimes distintos de escopo de valor:
//
//   group/tabs  → achatados: os subcampos dividem o MESMO $values dos irmãos
//   repeater    → cada linha é um escopo novo (lista de dicts)
//   flexible    → idem, e o escopo de DEFS depende do `_layout` da linha
//
// Puro: sem banco, sem disco. Retorna media id => nome da variante => def.

test('collectJobs: campo image de primeiro nível', function () {
    $defs = [['type' => 'image', 'name' => 'capa', 'variants' => [vs_variant('card')]]];
    $jobs = vs_collect_jobs($defs, ['capa' => 12]);

    check_same([12], array_keys($jobs));
    check_same(['card'], array_keys($jobs[12]));
});

test('collectJobs: campo sem variants declaradas não vira job', function () {
    $defs = [['type' => 'image', 'name' => 'capa']];
    check_same([], vs_collect_jobs($defs, ['capa' => 12]));
});

test('collectJobs: image dentro de group — valor mora achatado no escopo do pai', function () {
    $defs = [[
        'type' => 'group',
        'name' => 'bloco',
        'fields' => [['type' => 'image', 'name' => 'capa', 'variants' => [vs_variant('card')]]],
    ]];
    // Repare: 'capa' no topo de $values, NÃO dentro de 'bloco'.
    $jobs = vs_collect_jobs($defs, ['capa' => 12]);

    check_same([12], array_keys($jobs));
});

test('collectJobs: image dentro de tabs — também achatado', function () {
    $defs = [[
        'type' => 'tabs',
        'name' => 'abas',
        'tabs' => [
            ['label' => 'Conteúdo', 'fields' => [['type' => 'image', 'name' => 'capa', 'variants' => [vs_variant('card')]]]],
        ],
    ]];
    $jobs = vs_collect_jobs($defs, ['capa' => 12]);

    check_same([12], array_keys($jobs));
});

test('collectJobs: image dentro de repeater — uma linha por escopo', function () {
    $defs = [[
        'type' => 'repeater',
        'name' => 'slides',
        'fields' => [['type' => 'image', 'name' => 'foto', 'variants' => [vs_variant('card')]]],
    ]];
    $jobs = vs_collect_jobs($defs, ['slides' => [['foto' => 3], ['foto' => 7]]]);

    check_same([3, 7], array_keys($jobs));
});

test('collectJobs: gallery dentro de repeater — todos os ids de todas as linhas', function () {
    $defs = [[
        'type' => 'repeater',
        'name' => 'blocos',
        'fields' => [['type' => 'gallery', 'name' => 'fotos', 'variants' => [vs_variant('card')]]],
    ]];
    $jobs = vs_collect_jobs($defs, ['blocos' => [['fotos' => [1, 2]], ['fotos' => [5]]]]);

    check_same([1, 2, 5], array_keys($jobs));
});

test('collectJobs: image dentro de flexible — defs vêm do layout da linha', function () {
    $defs = [[
        'type' => 'flexible',
        'name' => 'secoes',
        'layouts' => [
            'hero' => ['fields' => [['type' => 'image', 'name' => 'fundo', 'variants' => [vs_variant('wide')]]]],
            'texto' => ['fields' => [['type' => 'richtext', 'name' => 'corpo']]],
        ],
    ]];
    $jobs = vs_collect_jobs($defs, ['secoes' => [
        ['_layout' => 'hero', 'fundo' => 4],
        ['_layout' => 'texto', 'corpo' => '<p>oi</p>'],
    ]]);

    check_same([4], array_keys($jobs));
    check_same(['wide'], array_keys($jobs[4]));
});

test('collectJobs: linha flexible com _layout desconhecido é ignorada', function () {
    $defs = [[
        'type' => 'flexible',
        'name' => 'secoes',
        'layouts' => ['hero' => ['fields' => [['type' => 'image', 'name' => 'fundo', 'variants' => [vs_variant('wide')]]]]],
    ]];
    // Mesma política self-heal do FieldRenderer::collectFlexible.
    $jobs = vs_collect_jobs($defs, ['secoes' => [['_layout' => 'sumiu', 'fundo' => 4]]]);

    check_same([], $jobs);
});

test('collectJobs: repeater aninhado em repeater', function () {
    $defs = [[
        'type' => 'repeater',
        'name' => 'secoes',
        'fields' => [[
            'type' => 'repeater',
            'name' => 'itens',
            'fields' => [['type' => 'image', 'name' => 'icone', 'variants' => [vs_variant('card')]]],
        ]],
    ]];
    $jobs = vs_collect_jobs($defs, ['secoes' => [['itens' => [['icone' => 9]]]]]);

    check_same([9], array_keys($jobs));
});

test('collectJobs: a mesma mídia em várias linhas colapsa num job só', function () {
    $defs = [[
        'type' => 'repeater',
        'name' => 'slides',
        'fields' => [['type' => 'image', 'name' => 'foto', 'variants' => [vs_variant('card')]]],
    ]];
    // 3 linhas apontando pra mídia 3: uma entrada, uma geração — é isso que
    // mantém o custo do save limitado ao número de mídias DISTINTAS.
    $jobs = vs_collect_jobs($defs, ['slides' => [['foto' => 3], ['foto' => 3], ['foto' => 3]]]);

    check_same([3], array_keys($jobs));
    check_same(['card'], array_keys($jobs[3]));
});

test('collectJobs: variantes de campos diferentes na mesma mídia se acumulam por nome', function () {
    $defs = [
        ['type' => 'image', 'name' => 'capa', 'variants' => [vs_variant('card')]],
        ['type' => 'image', 'name' => 'destaque', 'variants' => [vs_variant('wide')]],
    ];
    $jobs = vs_collect_jobs($defs, ['capa' => 8, 'destaque' => 8]);

    check_same([8], array_keys($jobs));
    check_same(['card', 'wide'], array_keys($jobs[8]));
});

test('collectJobs: valores ausentes/malformados não quebram o walk', function () {
    $defs = [[
        'type' => 'repeater',
        'name' => 'slides',
        'fields' => [['type' => 'image', 'name' => 'foto', 'variants' => [vs_variant('card')]]],
    ]];
    check_same([], vs_collect_jobs($defs, []));
    check_same([], vs_collect_jobs($defs, ['slides' => 'nao-e-lista']));
    check_same([], vs_collect_jobs($defs, ['slides' => ['linha-nao-array']]));
    check_same([], vs_collect_jobs($defs, ['slides' => [['foto' => 0]]]));
});
