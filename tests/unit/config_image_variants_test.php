<?php

declare(strict_types=1);

use Velk\Config;

/**
 * T3 (image-variants): Config::imageVariants() — registro global de
 * variantes de imagem coletado por uma varredura RECURSIVA de $site
 * inteiro (não enumera pages/item_types/taxonomies/field_groups/options
 * um por um — ver docblock do método). `thumb` é sempre incluído
 * (ImageVariantService::THUMB_BOX). Permissivo: só coleta, nunca valida
 * (isso é `schema:validate`, em bin/velk).
 *
 * Config exige rootPath + env.php no construtor — pra testes puros,
 * instancia sem construtor e injeta o array `site` via reflection (mesma
 * técnica de tests/unit/not_found_page_test.php e outros — imageVariants()
 * só lê $this->site).
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

/**
 * Site fixture cobrindo duas fontes de campo bem diferentes na árvore:
 * item_types[x].templates[y].fields (aninhado fundo) e field_groups[x].fields
 * (fonte totalmente separada) — prova que a varredura é genérica sobre a
 * árvore, não uma lista fixa de caminhos conhecidos.
 */
if (!function_exists('image_variants_fixture_site')) {
    function image_variants_fixture_site(): array
    {
        return [
            'field_groups' => [
                'hero' => [
                    'label' => 'Hero',
                    'fields' => [
                        [
                            'name' => 'hero_image',
                            'type' => 'image',
                            'label' => 'Imagem',
                            'variants' => [
                                ['name' => 'wide', 'width' => 1200, 'fit' => 'contain'], // width-only
                            ],
                        ],
                    ],
                ],
            ],
            'item_types' => [
                'post' => [
                    'templates' => [
                        'default' => [
                            'label' => 'Padrão',
                            'file' => 'post-default.php',
                            'fields' => [
                                [
                                    'name' => 'foto',
                                    'type' => 'image',
                                    'label' => 'Foto',
                                    'variants' => [
                                        ['name' => 'card', 'width' => 600, 'height' => 500, 'fit' => 'crop'],
                                        ['name' => 'banner', 'height' => 400, 'fit' => 'cover'], // height-only
                                        ['name' => 'nofit', 'width' => 300, 'height' => 300], // fit ausente
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}

test('imageVariants: sempre inclui o "thumb" reservado (400/400/contain), mesmo com site vazio', function () {
    $registry = config_with_site([])->imageVariants();
    check(isset($registry['thumb']), 'registro sem "thumb"');
    check_same(['width' => 400, 'height' => 400, 'fit' => 'contain'], $registry['thumb']);
});

test('imageVariants: "thumb" usa ImageVariantService::THUMB_BOX, não um número mágico', function () {
    $registry = config_with_site([])->imageVariants();
    check_same(\Velk\Services\ImageVariantService::THUMB_BOX, $registry['thumb']['width']);
    check_same(\Velk\Services\ImageVariantService::THUMB_BOX, $registry['thumb']['height']);
});

test('imageVariants: coleta variante aninhada em item_types[x].templates[y].fields', function () {
    $registry = config_with_site(image_variants_fixture_site())->imageVariants();
    check(isset($registry['card']), '"card" não coletado de item_types.post.templates.default.fields');
    check_same(['width' => 600, 'height' => 500, 'fit' => 'crop'], $registry['card']);
});

test('imageVariants: coleta variante em field_groups[x].fields', function () {
    $registry = config_with_site(image_variants_fixture_site())->imageVariants();
    check(isset($registry['wide']), '"wide" não coletado de field_groups.hero.fields');
    check_same(['width' => 1200, 'height' => null, 'fit' => 'contain'], $registry['wide']);
});

test('imageVariants: "fit" ausente vira "contain" por padrão', function () {
    $registry = config_with_site(image_variants_fixture_site())->imageVariants();
    check_same('contain', $registry['nofit']['fit']);
});

test('imageVariants: width-only mantém height null; height-only mantém width null', function () {
    $registry = config_with_site(image_variants_fixture_site())->imageVariants();
    check_same(['width' => 1200, 'height' => null, 'fit' => 'contain'], $registry['wide'], 'width-only');
    check_same(['width' => null, 'height' => 400, 'fit' => 'cover'], $registry['banner'], 'height-only');
});

test('imageVariants: type "gallery" também é coletado, não só "image"', function () {
    $site = ['options' => ['galeria' => ['fields' => [
        ['name' => 'fotos', 'type' => 'gallery', 'label' => 'Fotos', 'variants' => [
            ['name' => 'grid', 'width' => 300, 'height' => 300, 'fit' => 'crop'],
        ]],
    ]]]];
    $registry = config_with_site($site)->imageVariants();
    check_same(['width' => 300, 'height' => 300, 'fit' => 'crop'], $registry['grid']);
});

test('imageVariants: permissivo — nome duplicado em fontes diferentes, o último varrido vence', function () {
    // Config::imageVariants() não decide "qual está certo" — só coleta
    // (last-writer-wins). Detectar o conflito é trabalho do schema:validate.
    $site = [
        'a_fonte' => ['fields' => [['name' => 'f1', 'type' => 'image', 'variants' => [
            ['name' => 'dup', 'width' => 100, 'height' => 100, 'fit' => 'contain'],
        ]]]],
        'b_fonte' => ['fields' => [['name' => 'f2', 'type' => 'image', 'variants' => [
            ['name' => 'dup', 'width' => 200, 'height' => 200, 'fit' => 'cover'],
        ]]]],
    ];
    $registry = config_with_site($site)->imageVariants();
    check_same(['width' => 200, 'height' => 200, 'fit' => 'cover'], $registry['dup']);
});

test('imageVariants: campo sem "variants" (ou array vazio) não contribui nada', function () {
    $site = ['x' => ['fields' => [
        ['name' => 'sem_variants', 'type' => 'image'],
        ['name' => 'variants_vazio', 'type' => 'image', 'variants' => []],
    ]]];
    $registry = config_with_site($site)->imageVariants();
    check_same(['thumb'], array_keys($registry), 'só o thumb reservado deveria estar presente');
});

test('imageVariants: "thumb" global sempre vence, mesmo que um campo redeclare "thumb"', function () {
    // Regressão: collectImageVariants() faz $registry[$name] = [...] sem
    // checar nomes reservados, então um campo que declare uma variante
    // chamada "thumb" não pode sobrescrever a caixa global (400/400/contain)
    // usada de fato no upload — ver ImageVariantService::THUMB_BOX. O global
    // precisa ser aplicado DEPOIS da varredura pra sempre vencer.
    $site = ['x' => ['fields' => [
        [
            'name' => 'foto',
            'type' => 'image',
            'variants' => [
                ['name' => 'thumb', 'width' => 50, 'height' => 50, 'fit' => 'cover'],
            ],
        ],
    ]]];
    $registry = config_with_site($site)->imageVariants();
    check_same(['width' => 400, 'height' => 400, 'fit' => 'contain'], $registry['thumb'], 'thumb declarado por campo não deveria alterar o registro global');
});
