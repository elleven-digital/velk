<?php

declare(strict_types=1);

namespace Velk\Services;

/**
 * Geometria de redimensionamento (contain/cover/crop) + execução via GD.
 *
 * `targetGeometry()` é pura matemática — sem I/O, sem GD — usada tanto por
 * `generate()` quanto pelos testes. `generate()` é quem toca disco/GD e
 * NUNCA lança: qualquer falha (GD ausente, origem ilegível/tipo não
 * suportado, escrita malsucedida) vira `false`. Isso é proposital —
 * variantes de imagem são um recurso best-effort; o original sempre serve
 * de fallback (ver `Media::url()`), então um erro aqui nunca deve
 * derrubar upload/save de conteúdo.
 *
 * Downscale-only em todo lugar: nenhum modo faz upscale além do tamanho
 * original da fonte.
 */
final class ImageVariantService
{
    /** Caixa (px) do thumb global gerado no upload — ver MediaService::upload(). */
    public const THUMB_BOX = 400;

    /**
     * Formatos com canal alpha real (transparência por pixel) — recebem
     * canvas truecolor transparente antes do resample. GIF NÃO entra
     * aqui: não tem canal alpha, só um único índice de cor da paleta
     * marcado como transparente. Tratar GIF como se tivesse alpha de
     * verdade é justamente o bug que este arquivo corrige — ver
     * `prepGifCanvas()`.
     */
    private const TRUE_ALPHA_TYPES = [IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * Calcula o retângulo de origem + tamanho de destino para
     * `imagecopyresampled($dst, $src, 0, 0, srcX, srcY, dstW, dstH, srcW, srcH)`.
     * Pura: só matemática, nenhum I/O.
     *
     * Regras:
     * - Só uma dimensão da caixa setada: escala proporcional só por ela;
     *   o `$fit` não importa (inclusive 'crop', que sem as duas dimensões
     *   vira "essa dimensão + aspecto", sem corte — o validador do schema
     *   avisa sobre isso, ver Task 3).
     * - `contain`: cabe inteira dentro da caixa (ratio = min das duas razões).
     * - `cover`: cobre a caixa inteira, um lado pode exceder (ratio = max).
     * - `crop`: corta o centro da fonte no aspecto da caixa, depois escala
     *   pra caixa exata — sem upscale (se a região cortada já for menor
     *   que a caixa, sai no tamanho nativo da região).
     * - Nenhum modo faz upscale: o ratio é sempre limitado a 1.0.
     * - `$fit` desconhecido cai no mesmo caminho de `contain` — função
     *   pura nunca lança por causa de um valor de `$fit` inválido; quem
     *   garante `contain|cover|crop` é a validação de schema (Task 3).
     *
     * @return array{dstW:int,dstH:int,srcX:int,srcY:int,srcW:int,srcH:int}
     */
    public static function targetGeometry(int $srcW, int $srcH, ?int $boxW, ?int $boxH, string $fit): array
    {
        if ($boxW === null && $boxH === null) {
            // Sem caixa nenhuma: nada a fazer, devolve a fonte inteira.
            return self::scaled($srcW, $srcH, 1.0);
        }

        if ($boxH === null) {
            return self::scaled($srcW, $srcH, min(1.0, $boxW / $srcW));
        }
        if ($boxW === null) {
            return self::scaled($srcW, $srcH, min(1.0, $boxH / $srcH));
        }

        return match ($fit) {
            'cover' => self::scaled($srcW, $srcH, min(1.0, max($boxW / $srcW, $boxH / $srcH))),
            'crop' => self::cropGeometry($srcW, $srcH, $boxW, $boxH),
            default => self::scaled($srcW, $srcH, min(1.0, min($boxW / $srcW, $boxH / $srcH))), // 'contain'
        };
    }

    /** Escala a fonte inteira (sem cortar) por `$r`, já limitado a 1.0 pelo chamador. */
    private static function scaled(int $srcW, int $srcH, float $r): array
    {
        return [
            'dstW' => (int) round($srcW * $r),
            'dstH' => (int) round($srcH * $r),
            'srcX' => 0,
            'srcY' => 0,
            'srcW' => $srcW,
            'srcH' => $srcH,
        ];
    }

    /** Center-crop no aspecto da caixa, depois escala pra caixa (sem upscale). */
    private static function cropGeometry(int $srcW, int $srcH, int $boxW, int $boxH): array
    {
        $cropAspect = $boxW / $boxH;
        $srcAspect = $srcW / $srcH;

        if ($srcAspect > $cropAspect) {
            // Fonte mais "larga" que a caixa: corta as laterais.
            $cW = (int) round($srcH * $cropAspect);
            $cH = $srcH;
        } else {
            // Fonte mais "alta" (ou mesmo aspecto): corta topo/base.
            $cW = $srcW;
            $cH = (int) round($srcW / $cropAspect);
        }

        $srcX = intdiv($srcW - $cW, 2);
        $srcY = intdiv($srcH - $cH, 2);

        if ($cW >= $boxW) {
            $dstW = $boxW;
            $dstH = $boxH;
        } else {
            // Região cortada já é menor que a caixa — sai no tamanho
            // nativo da região (downscale-only, nunca upscale).
            $dstW = $cW;
            $dstH = $cH;
        }

        return ['dstW' => $dstW, 'dstH' => $dstH, 'srcX' => $srcX, 'srcY' => $srcY, 'srcW' => $cW, 'srcH' => $cH];
    }

    /**
     * Gera uma variante em `$destPath` a partir de `$srcPath`, criando o
     * diretório de destino se preciso. Preserva o formato de saída (mesmo
     * tipo da origem). Transparência por formato:
     * - PNG/WebP: canal alpha real, por pixel — preservado com fidelidade
     *   total (canvas truecolor transparente antes do resample).
     * - GIF: só tem um único índice de cor marcado como transparente (sem
     *   canal alpha). Quando a origem tem essa cor definida, ela é
     *   propagada pro destino (mesma RGB alocada + marcada transparente
     *   antes do resample) — o resultado continua transparente onde a
     *   origem era. Nas bordas entre região transparente e opaca pode
     *   sobrar uma leve franja (cor intermediária, não totalmente
     *   transparente) — limitação inerente ao modelo de cor-chave única
     *   do GIF, não um bug. GIF sem transparência sai opaco, sem surpresa.
     * - JPEG: não tem conceito de transparência — canvas opaco comum.
     *
     * Nunca lança — qualquer falha (GD ausente, origem ilegível, tipo não
     * suportado, escrita malsucedida) devolve `false`.
     */
    public static function generate(string $srcPath, string $destPath, ?int $boxW, ?int $boxH, string $fit): bool
    {
        if (!self::gdAvailable()) {
            return false;
        }

        try {
            return self::doGenerate($srcPath, $destPath, $boxW, $boxH, $fit);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function doGenerate(string $srcPath, string $destPath, ?int $boxW, ?int $boxH, string $fit): bool
    {
        $info = @getimagesize($srcPath);
        if (!is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return false;
        }
        [$srcW, $srcH, $type] = $info;
        $type = (int) $type;

        $src = self::loadImage($srcPath, $type);
        if ($src === false) {
            return false;
        }

        $geom = self::targetGeometry((int) $srcW, (int) $srcH, $boxW, $boxH, $fit);
        if ($geom['dstW'] < 1 || $geom['dstH'] < 1) {
            return false;
        }

        $dst = @imagecreatetruecolor($geom['dstW'], $geom['dstH']);
        if ($dst === false) {
            return false;
        }

        if (in_array($type, self::TRUE_ALPHA_TYPES, true)) {
            self::prepTrueAlphaCanvas($dst);
        } elseif ($type === IMAGETYPE_GIF) {
            self::prepGifCanvas($src, $dst);
        }
        // JPEG (e qualquer outro tipo aceito no futuro): canvas opaco
        // comum, sem preparo — não existe conceito de transparência.

        $resampled = imagecopyresampled(
            $dst,
            $src,
            0,
            0,
            $geom['srcX'],
            $geom['srcY'],
            $geom['dstW'],
            $geom['dstH'],
            $geom['srcW'],
            $geom['srcH']
        );
        if (!$resampled) {
            return false;
        }

        @mkdir(dirname($destPath), 0775, true);

        // Nota: sem imagedestroy($src)/imagedestroy($dst) — desde o PHP 8.0
        // GdImage é um objeto comum, contado por referência; a função virou
        // no-op e o PHP 8.5 já marca a chamada como deprecated. $src/$dst
        // são liberados sozinhos ao sair de escopo (todo `return` acima).
        return self::saveImage($dst, $destPath, $type);
    }

    /** PNG/WebP: canal alpha real — canvas truecolor totalmente transparente antes do resample. */
    private static function prepTrueAlphaCanvas(\GdImage $dst): void
    {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
    }

    /**
     * GIF: sem canal alpha — a transparência é um único índice de cor da
     * paleta (`imagecolortransparent`). Propaga esse índice pro canvas
     * truecolor de destino: lê a RGB do índice transparente da origem,
     * aloca a mesma cor no destino, marca como transparente e preenche o
     * canvas inteiro com ela ANTES do resample. Se a origem não tem cor
     * transparente (`$ti < 0`), não faz nada — canvas truecolor opaco
     * comum, igual ao caminho do JPEG.
     *
     * Importante (achado empírico, testado nesta build de GD): o canvas
     * precisa ficar com `imagealphablending(true)` — LIGADO — durante o
     * `imagecopyresampled()` seguinte, não desligado. O GD marca
     * internamente os pixels de origem que vêm do índice transparente com
     * alpha 127 (totalmente transparente) ao reamostrar; com blending
     * ligado, esses pixels viram no-op sobre o preenchimento (a cor
     * transparente sobrevive intacta); com blending desligado, o valor
     * bruto (RGB certo, mas com alpha 127 embutido) é escrito por cima do
     * preenchimento e o índice transparente registrado via
     * `imagecolortransparent()` fica órfão — nenhum pixel da paleta final
     * bate com ele, e a transparência se perde de novo (mesmo bug que
     * este método existe pra corrigir). Verificado com paleta completa +
     * pixel a pixel antes de fixar esta implementação.
     *
     * Nas bordas entre região transparente e opaca, o resample interpola
     * (média ponderada dos pixels de origem), então pode sobrar uma
     * franja de ~1px com uma cor intermediária, nem totalmente
     * transparente nem a cor original — limitação inerente ao modelo de
     * "color-key" único do GIF (PNG/WebP não sofrem disso, pois carregam
     * alpha por pixel de verdade). Aceitável e documentado.
     */
    private static function prepGifCanvas(\GdImage $src, \GdImage $dst): void
    {
        $ti = imagecolortransparent($src);
        if ($ti < 0) {
            return;
        }

        $rgb = imagecolorsforindex($src, $ti);
        $c = imagecolorallocate($dst, $rgb['red'], $rgb['green'], $rgb['blue']);
        imagecolortransparent($dst, $c);
        imagealphablending($dst, true);
        imagefill($dst, 0, 0, $c);
    }

    /** @return \GdImage|false */
    private static function loadImage(string $path, int $type): \GdImage|false
    {
        return match ($type) {
            IMAGETYPE_JPEG => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            IMAGETYPE_PNG => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            IMAGETYPE_GIF => function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false,
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false, // tipo não suportado (ex.: bmp) — mesma whitelist da MediaService.
        };
    }

    private static function saveImage(\GdImage $image, string $destPath, int $type): bool
    {
        return match ($type) {
            IMAGETYPE_JPEG => (bool) @imagejpeg($image, $destPath, 85),
            IMAGETYPE_PNG => (bool) @imagepng($image, $destPath, 9),
            IMAGETYPE_GIF => (bool) @imagegif($image, $destPath),
            IMAGETYPE_WEBP => function_exists('imagewebp') ? (bool) @imagewebp($image, $destPath, 85) : false,
            default => false,
        };
    }

    /** Caminho absoluto em disco: `"$uploadsPath/_variants/$variant/$filename"`. */
    public static function variantPath(string $uploadsPath, string $variant, string $filename): string
    {
        return $uploadsPath . '/_variants/' . $variant . '/' . $filename;
    }

    /** Caminho relativo à pasta de uploads (pra montar URL pública): `"_variants/$variant/$filename"`. */
    public static function variantRelPath(string $variant, string $filename): string
    {
        return '_variants/' . $variant . '/' . $filename;
    }

    public static function gdAvailable(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatetruecolor');
    }
}
