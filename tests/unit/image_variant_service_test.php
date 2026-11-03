<?php

declare(strict_types=1);

use Velk\Services\ImageVariantService;

/**
 * T1 (image-variants): geometria de redimensionamento (contain/cover/crop)
 * + execução GD do ImageVariantService. Os casos abaixo cobrem os 3 modos,
 * downscale-only, caixa com uma dimensão só, os dois ramos do cálculo de
 * aspecto do crop, e os caminhos defensivos (fit desconhecido, sem caixa).
 */

/**
 * dstW/dstH precisam bater exato; srcX/srcY/srcW/srcH (retângulo de
 * origem) toleram ±1px — arredondamento do crop pode variar por
 * implementação sem impacto perceptível (ver brief da Task 1).
 */
if (!function_exists('assertGeom')) {
    function assertGeom(
        int $srcW,
        int $srcH,
        ?int $boxW,
        ?int $boxH,
        string $fit,
        int $expDstW,
        int $expDstH,
        int $expSrcX,
        int $expSrcY,
        int $expSrcW,
        int $expSrcH
    ): void {
        $g = ImageVariantService::targetGeometry($srcW, $srcH, $boxW, $boxH, $fit);
        $ctx = "{$fit} {$srcW}x{$srcH} caixa " . ($boxW ?? '·') . 'x' . ($boxH ?? '·');
        check_same($expDstW, $g['dstW'], "dstW ({$ctx})");
        check_same($expDstH, $g['dstH'], "dstH ({$ctx})");
        check(abs($expSrcX - $g['srcX']) <= 1, "srcX ({$ctx}): esperado ~{$expSrcX}, veio {$g['srcX']}");
        check(abs($expSrcY - $g['srcY']) <= 1, "srcY ({$ctx}): esperado ~{$expSrcY}, veio {$g['srcY']}");
        check(abs($expSrcW - $g['srcW']) <= 1, "srcW ({$ctx}): esperado ~{$expSrcW}, veio {$g['srcW']}");
        check(abs($expSrcH - $g['srcH']) <= 1, "srcH ({$ctx}): esperado ~{$expSrcH}, veio {$g['srcH']}");
    }
}

// --- targetGeometry: os 3 modos, mesma fonte/caixa (1000×1000 → 600×500) ---

test('targetGeometry: contain — cabe inteira dentro da caixa, sem cortar', function () {
    assertGeom(1000, 1000, 600, 500, 'contain', 500, 500, 0, 0, 1000, 1000);
});

test('targetGeometry: cover — cobre a caixa inteira, um lado pode exceder', function () {
    assertGeom(1000, 1000, 600, 500, 'cover', 600, 600, 0, 0, 1000, 1000);
});

test('targetGeometry: crop — corta o centro no aspecto da caixa, depois escala pra caixa exata', function () {
    assertGeom(1000, 1000, 600, 500, 'crop', 600, 500, 0, 83, 1000, 833);
});

// --- downscale-only: nunca upscala ---

test('targetGeometry: downscale-only — contain não amplia fonte menor que a caixa', function () {
    assertGeom(400, 400, 600, 500, 'contain', 400, 400, 0, 0, 400, 400);
});

test('targetGeometry: downscale-only — crop sai no tamanho nativo da região quando ela já é menor que a caixa', function () {
    assertGeom(400, 400, 600, 500, 'crop', 400, 333, 0, 33, 400, 333);
});

// --- uma dimensão só: fit fica moot ---

test('targetGeometry: só boxW informado — escala proporcional só por ela (fit irrelevante)', function () {
    assertGeom(1000, 800, 600, null, 'contain', 600, 480, 0, 0, 1000, 800);
});

test('targetGeometry: só boxH informado — escala proporcional só por ela, simétrico', function () {
    assertGeom(1000, 800, null, 400, 'cover', 500, 400, 0, 0, 1000, 800);
});

// --- thumb global: caixa quadrada numa imagem bem larga ---

test('targetGeometry: thumb — caixa 400×400 numa fonte 2000×1000 (borda mais longa vira 400)', function () {
    assertGeom(2000, 1000, 400, 400, 'contain', 400, 200, 0, 0, 2000, 1000);
});

// --- crop: os dois ramos do cálculo de aspecto + o caso degenerado ---

test('targetGeometry: crop — fonte mais larga que o aspecto da caixa (ramo srcAspect > cropAspect)', function () {
    // 2000x1000 (aspecto 2.0) numa caixa 500x500 (aspecto 1.0): corta as
    // laterais, mantém a altura inteira (o outro ramo do if, não coberto
    // pelos exemplos do brief — ambos eram srcAspect <= cropAspect).
    assertGeom(2000, 1000, 500, 500, 'crop', 500, 500, 500, 0, 1000, 1000);
});

test('targetGeometry: crop — aspecto da fonte igual ao da caixa (sem corte real, só possível downscale)', function () {
    assertGeom(800, 800, 400, 400, 'crop', 400, 400, 0, 0, 800, 800);
});

// --- limites e defensivo ---

test('targetGeometry: ratio exatamente 1.0 (caixa == fonte) não escala', function () {
    assertGeom(600, 500, 600, 500, 'contain', 600, 500, 0, 0, 600, 500);
});

test('targetGeometry: fit desconhecido cai em contain (defensivo — nunca lança)', function () {
    assertGeom(1000, 1000, 600, 500, 'noSuchFit', 500, 500, 0, 0, 1000, 1000);
});

test('targetGeometry: sem caixa nenhuma (boxW e boxH nulos) devolve a fonte inteira, sem escala', function () {
    assertGeom(800, 600, null, null, 'contain', 800, 600, 0, 0, 800, 600);
});

// --- path helpers ---

test('variantPath: monta $uploadsPath/_variants/$variant/$filename', function () {
    check_same(
        '/var/www/storage/uploads/_variants/thumb/foto.jpg',
        ImageVariantService::variantPath('/var/www/storage/uploads', 'thumb', 'foto.jpg')
    );
});

test('variantRelPath: monta _variants/$variant/$filename', function () {
    check_same('_variants/card/foto.jpg', ImageVariantService::variantRelPath('card', 'foto.jpg'));
});

// --- constante + gdAvailable ---

test('THUMB_BOX é 400', function () {
    check_same(400, ImageVariantService::THUMB_BOX);
});

test('gdAvailable: reflete extension_loaded(gd) + imagecreatetruecolor', function () {
    check_same(
        extension_loaded('gd') && function_exists('imagecreatetruecolor'),
        ImageVariantService::gdAvailable()
    );
});

// --- generate(): round-trip GD real ---

if (!function_exists('iv_tmp_dir')) {
    function iv_tmp_dir(): string
    {
        $dir = sys_get_temp_dir() . '/velk-imgvariant-' . uniqid();
        mkdir($dir, 0775, true);
        return $dir;
    }
}

if (!function_exists('iv_rrmdir')) {
    function iv_rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? iv_rrmdir($path) : @unlink($path);
        }
        // Best-effort: no macOS, um xattr de provenance às vezes é gravado
        // no diretório logo após ele ficar vazio, derrubando o primeiro
        // rmdir (ENOTEMPTY transitório). Uma segunda tentativa resolve; se
        // ainda assim falhar, é só litter de /tmp — não afeta o teste.
        if (!@rmdir($dir)) {
            clearstatcache(true, $dir);
            @rmdir($dir);
        }
    }
}

if (!function_exists('iv_make_image')) {
    /** Cria uma imagem sólida de teste no formato pedido. */
    function iv_make_image(string $path, int $w, int $h, int $type): void
    {
        $im = imagecreatetruecolor($w, $h);
        $color = imagecolorallocate($im, 90, 140, 200);
        imagefill($im, 0, 0, $color);
        match ($type) {
            IMAGETYPE_JPEG => imagejpeg($im, $path, 90),
            IMAGETYPE_GIF => imagegif($im, $path),
            IMAGETYPE_WEBP => imagewebp($im, $path, 90),
            IMAGETYPE_BMP => imagebmp($im, $path),
            default => imagepng($im, $path, 9),
        };
    }
}

test('generate: crop produz exatamente 600×500 e contain produz exatamente 500×500 (fonte 1000×1000)', function () {
    if (!ImageVariantService::gdAvailable()) {
        return; // ambiente sem GD — nada a verificar, teste passa vazio.
    }
    $dir = iv_tmp_dir();
    $src = $dir . '/source.png';
    iv_make_image($src, 1000, 1000, IMAGETYPE_PNG);

    // Caminho de destino aninhado, que ainda não existe — cobre a criação
    // recursiva do diretório também.
    $cropOut = $dir . '/_variants/card/source.png';
    check(false === is_dir(dirname($cropOut)), 'pré-condição: diretório de destino ainda não existe');
    check(true === ImageVariantService::generate($src, $cropOut, 600, 500, 'crop'), 'generate() (crop) deveria devolver true');
    check(is_file($cropOut), 'arquivo de saída (crop) deveria existir');
    $cropInfo = getimagesize($cropOut);
    check(is_array($cropInfo), 'getimagesize deveria ler a saída (crop)');
    check_same(600, $cropInfo[0], 'largura da saída (crop)');
    check_same(500, $cropInfo[1], 'altura da saída (crop)');

    $containOut = $dir . '/_variants/thumb/source.png';
    check(true === ImageVariantService::generate($src, $containOut, 500, 500, 'contain'), 'generate() (contain) deveria devolver true');
    $containInfo = getimagesize($containOut);
    check_same(500, $containInfo[0], 'largura da saída (contain)');
    check_same(500, $containInfo[1], 'altura da saída (contain)');

    iv_rrmdir($dir);
});

test('generate: preserva o formato de saída (mesmo tipo da origem) — jpeg/png/gif/webp', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = iv_tmp_dir();
    $cases = ['jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP];
    foreach ($cases as $ext => $type) {
        if ($type === IMAGETYPE_WEBP && !function_exists('imagewebp')) {
            continue; // build do GD sem suporte a webp — pula, não falha.
        }
        $src = $dir . "/source.{$ext}";
        iv_make_image($src, 300, 200, $type);
        $out = $dir . "/out/source.{$ext}";
        check(true === ImageVariantService::generate($src, $out, 150, 100, 'contain'), "generate() falhou pra {$ext}");
        $info = getimagesize($out);
        check(is_array($info), "getimagesize falhou na saída {$ext}");
        check_same($type, $info[2], "tipo de saída deveria continuar {$ext}");
    }
    iv_rrmdir($dir);
});

test('generate: preserva transparência (alpha) em PNG', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = iv_tmp_dir();

    // Fonte totalmente transparente — depois do resize, o canal alpha
    // precisa continuar 127 (totalmente transparente) em todo pixel.
    $transparentSrc = $dir . '/transparent.png';
    $im = imagecreatetruecolor(40, 40);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    $t = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefill($im, 0, 0, $t);
    imagepng($im, $transparentSrc, 9);

    $transparentOut = $dir . '/transparent-out.png';
    check(true === ImageVariantService::generate($transparentSrc, $transparentOut, 20, 20, 'contain'));
    $outIm = imagecreatefrompng($transparentOut);
    $rgba = imagecolorat($outIm, 10, 10);
    $alpha = ($rgba >> 24) & 0x7F;
    check_same(127, $alpha, 'pixel deveria continuar totalmente transparente após o resize');

    // Fonte opaca sólida — alpha precisa continuar 0 (opaco) e a cor
    // precisa sobreviver ao resize (campo uniforme: resample de cor sólida
    // é exato, sem blending pra checar).
    $opaqueSrc = $dir . '/opaque.png';
    $im2 = imagecreatetruecolor(40, 40);
    imagealphablending($im2, false);
    imagesavealpha($im2, true);
    $red = imagecolorallocatealpha($im2, 255, 0, 0, 0);
    imagefill($im2, 0, 0, $red);
    imagepng($im2, $opaqueSrc, 9);

    $opaqueOut = $dir . '/opaque-out.png';
    check(true === ImageVariantService::generate($opaqueSrc, $opaqueOut, 20, 20, 'contain'));
    $outIm2 = imagecreatefrompng($opaqueOut);
    $rgba2 = imagecolorat($outIm2, 10, 10);
    check_same(0, ($rgba2 >> 24) & 0x7F, 'pixel opaco deveria continuar com alpha 0 após o resize');
    check_same(255, ($rgba2 >> 16) & 0xFF, 'canal vermelho deveria sobreviver ao resize (campo uniforme)');

    iv_rrmdir($dir);
});

test('generate: preserva transparência (índice único) em GIF', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = iv_tmp_dir();

    // Fonte totalmente transparente (cor de índice único marcada via
    // imagecolortransparent, como todo GIF) — depois do resize, a saída
    // precisa continuar com uma cor transparente e o pixel amostrado
    // precisa bater com esse índice.
    $transparentSrc = $dir . '/transparent.gif';
    $im = imagecreatetruecolor(40, 40);
    $bg = imagecolorallocate($im, 0, 0, 0);
    imagecolortransparent($im, $bg);
    imagefill($im, 0, 0, $bg);
    imagegif($im, $transparentSrc);

    $transparentOut = $dir . '/transparent-out.gif';
    check(true === ImageVariantService::generate($transparentSrc, $transparentOut, 20, 20, 'contain'));
    $outIm = imagecreatefromgif($transparentOut);
    $ti = imagecolortransparent($outIm);
    check($ti >= 0, 'saída deveria ter um índice de cor transparente (imagecolortransparent >= 0)');
    $pixel = imagecolorat($outIm, 10, 10);
    check_same($ti, $pixel, 'pixel amostrado deveria bater com o índice transparente após o resize');

    // Fonte opaca sólida, sem cor transparente nenhuma — saída não deveria
    // ganhar transparência do nada, e a cor precisa sobreviver ao resize
    // (campo uniforme: resample de cor sólida é exato o bastante pra não
    // cair perto da cor-chave transparente por acidente).
    $opaqueSrc = $dir . '/opaque.gif';
    $im2 = imagecreatetruecolor(40, 40);
    $red = imagecolorallocate($im2, 255, 0, 0);
    imagefill($im2, 0, 0, $red);
    imagegif($im2, $opaqueSrc);

    $opaqueOut = $dir . '/opaque-out.gif';
    check(true === ImageVariantService::generate($opaqueSrc, $opaqueOut, 20, 20, 'contain'));
    $outIm2 = imagecreatefromgif($opaqueOut);
    check_same(-1, imagecolortransparent($outIm2), 'fonte sem transparência não deveria ganhar cor transparente na saída');
    $rgb2 = imagecolorsforindex($outIm2, imagecolorat($outIm2, 10, 10));
    check($rgb2['red'] > 200 && $rgb2['green'] < 60 && $rgb2['blue'] < 60, 'pixel opaco deveria continuar predominantemente vermelho após o resize');

    iv_rrmdir($dir);
});

test('generate: tipo não suportado (bmp) devolve false, não lança', function () {
    if (!ImageVariantService::gdAvailable()) {
        return;
    }
    $dir = iv_tmp_dir();
    $src = $dir . '/source.bmp';
    iv_make_image($src, 100, 100, IMAGETYPE_BMP);

    $out = $dir . '/out.bmp';
    check(false === ImageVariantService::generate($src, $out, 50, 50, 'contain'), 'bmp não é suportado — deveria devolver false');
    check(false === is_file($out), 'nenhum arquivo deveria ter sido escrito');

    iv_rrmdir($dir);
});

test('generate: fonte inexistente ou ilegível devolve false, sem lançar', function () {
    $dir = iv_tmp_dir();

    check(false === ImageVariantService::generate($dir . '/does-not-exist.jpg', $dir . '/out.jpg', 100, 100, 'contain'));

    $garbage = $dir . '/garbage.jpg';
    file_put_contents($garbage, 'isso não é uma imagem de verdade');
    check(false === ImageVariantService::generate($garbage, $dir . '/out2.jpg', 100, 100, 'contain'));

    iv_rrmdir($dir);
});
