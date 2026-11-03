<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;
use Velk\Models\Media;

final class MediaService
{
    /** mime → extension (only mimes in this map are accepted) */
    private const ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/svg+xml' => 'svg',
        'application/pdf' => 'pdf',
        // Vídeo
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        // Áudio (finfo varia entre audio/x-* e audio/* conforme a versão)
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/ogg' => 'ogg',
        'application/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        // Documentos Office (contêiner zip; ver promoteZipMime)
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        // Compactados
        'application/zip' => 'zip',
    ];

    /** Rótulos pt-BR por categoria pra mensagens de erro de tamanho. */
    private const CATEGORY_LABELS = [
        'image' => 'imagens',
        'video' => 'vídeo',
        'audio' => 'áudio',
        'document' => 'documentos',
        'archive' => 'arquivos compactados',
    ];

    /**
     * Process a $_FILES entry, validate, persist file + DB row.
     *
     * A global `thumb` variant (see ImageVariantService::THUMB_BOX) is
     * generated right after save, for resizable raster images only
     * (jpeg/png/gif/webp — never SVG). Generation is best-effort and
     * non-fatal: missing GD or any failure just skips the thumb, it never
     * fails the upload. The full-size original is still always served
     * as-is regardless; per-field named variants are handled separately —
     * generated at content-save time by VariantService, with the
     * `media:variants` CLI available to backfill existing media.
     */
    public function upload(array $uploaded): Media
    {
        $error = (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(self::uploadErrorMessage($error));
        }

        $size = (int) ($uploaded['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('Arquivo vazio.');
        }

        $tmp = (string) ($uploaded['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
            throw new \RuntimeException('Upload inválido.');
        }

        $mime = self::detectMime($tmp, (string) ($uploaded['name'] ?? 'arquivo'));
        if ($mime === null || !isset(self::ALLOWED_MIMES[$mime])) {
            // When detection fails, surface what each layer saw — this is
            // far more useful than "desconhecido" for diagnosing weird files
            // (HEIC from iPhones, PSD, corrupted images, etc.). The file is
            // already rejected, so there's no information leak.
            $diag = self::diagnoseMime($tmp);
            throw new \RuntimeException(sprintf(
                'Tipo de arquivo não permitido. Detecção retornou: %s · Primeiros bytes: %s',
                $diag['summary'],
                $diag['hex']
            ));
        }

        // Limite por categoria — o mime precisa vir antes pra saber qual vale.
        $maxSize = self::maxSizeFor($mime);
        if ($size > $maxSize) {
            throw new \RuntimeException(sprintf(
                'Arquivo muito grande (máximo %d MB para %s).',
                (int) round($maxSize / 1024 / 1024),
                self::CATEGORY_LABELS[\Velk\Models\Media::categoryOf($mime)] ?? 'este tipo'
            ));
        }

        $originalName = (string) ($uploaded['name'] ?? 'arquivo');
        $ext = self::ALLOWED_MIMES[$mime];

        $base = preg_replace('/\.[^.]+$/', '', $originalName) ?? $originalName;
        $base = \slugify($base);
        $filename = $this->uniqueFilename($base . '.' . $ext);

        $uploadsPath = App::instance()->config->path('uploads');
        if (!is_dir($uploadsPath)) {
            @mkdir($uploadsPath, 0775, true);
            @chmod($uploadsPath, 0775);
        }
        $destPath = $uploadsPath . '/' . $filename;

        if (is_uploaded_file($tmp)) {
            if (!move_uploaded_file($tmp, $destPath)) {
                throw new \RuntimeException('Não foi possível salvar o arquivo.');
            }
        } else {
            if (!copy($tmp, $destPath)) {
                throw new \RuntimeException('Não foi possível salvar o arquivo.');
            }
        }

        // Sanitize SVG before any reference reaches the DB.
        //
        // SVG files served with Content-Type: image/svg+xml render as
        // active content in the browser — any <script>, on* event
        // handler, or javascript: URI inside would execute in the site's
        // origin (stored XSS). We strip dangerous elements/attributes
        // server-side so the file on disk is safe to serve. If the SVG
        // can't be parsed (broken XML, malicious payload disguised as
        // SVG), the upload is rejected entirely.
        if ($mime === 'image/svg+xml') {
            if (!$this->sanitizeSvg($destPath)) {
                @unlink($destPath);
                throw new \RuntimeException(
                    'SVG inválido ou contém conteúdo não permitido (script, eventos, URIs javascript:).'
                );
            }
        }

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            $info = @getimagesize($destPath);
            if (is_array($info)) {
                $width = (int) $info[0];
                $height = (int) $info[1];
            }
        }

        // Thumb global best-effort pra prévia pequena no admin. Nunca
        // derruba o upload: generate() já não lança (ver ImageVariantService),
        // o try/catch aqui é só cinto-e-suspensório.
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' && ImageVariantService::gdAvailable()) {
            try {
                $thumbDest = ImageVariantService::variantPath($uploadsPath, 'thumb', $filename);
                ImageVariantService::generate($destPath, $thumbDest, ImageVariantService::THUMB_BOX, ImageVariantService::THUMB_BOX, 'contain');
            } catch (\Throwable $e) {
                error_log('[Velk] thumb generation failed: ' . $e->getMessage());
            }
        }

        return Media::create([
            'filename' => $filename,
            'original_name' => $originalName,
            'mime' => $mime,
            'size' => $size,
            'width' => $width,
            'height' => $height,
        ]);
    }

    /**
     * Limite de upload (bytes) por categoria de mídia. O teto REAL de um
     * upload ainda é o menor entre isto e upload_max_filesize/post_max_size
     * do PHP — esses são config de servidor e ficam fora do alcance da app.
     */
    public static function maxSizeMap(): array
    {
        return [
            'image' => 25 * 1024 * 1024,
            'document' => 25 * 1024 * 1024,
            'audio' => 50 * 1024 * 1024,
            'archive' => 100 * 1024 * 1024,
            'video' => 200 * 1024 * 1024,
        ];
    }

    public static function maxSizeFor(string $mime): int
    {
        return self::maxSizeMap()[\Velk\Models\Media::categoryOf($mime)] ?? 25 * 1024 * 1024;
    }

    /**
     * docx/xlsx/pptx são zips; quando a detecção só enxerga o contêiner
     * (application/zip), a extensão do NOME ORIGINAL desempata entre os
     * subtipos de zip permitidos. Nunca destrava tipos fora do zip real —
     * o pior caso é um zip renomeado, que segue sendo um zip inofensivo.
     * Pura — testável sem arquivos.
     */
    public static function promoteZipMime(string $mime, string $originalName): string
    {
        if ($mime !== 'application/zip') {
            return $mime;
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return match ($ext) {
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/zip',
        };
    }

    public function delete(Media $media): void
    {
        $uploadsPath = App::instance()->config->path('uploads');

        $original = $uploadsPath . '/' . $media->filename;
        if (is_file($original)) {
            @unlink($original);
        }

        // Remove as variantes geradas (thumb + variantes de campo) desta imagem.
        foreach (glob($uploadsPath . '/_variants/*/' . $media->filename) ?: [] as $variantFile) {
            @unlink($variantFile);
        }

        App::instance()->db->delete('media', ['id' => $media->id]);
    }

    /**
     * Sanitize an SVG file in place. Removes scripts, event handlers,
     * external resource references and other vectors that could turn an
     * uploaded SVG into a stored XSS when rendered in the browser.
     *
     * Returns false if the file can't be parsed as XML, in which case
     * the caller should reject the upload. Returns true after the file
     * has been overwritten with the sanitized version.
     */
    private function sanitizeSvg(string $path): bool
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || $content === '') {
            return false;
        }
        $sanitized = self::sanitizeSvgMarkup($content);
        if ($sanitized === null) {
            return false;
        }
        return @file_put_contents($path, $sanitized) !== false;
    }

    /**
     * Sanitiza markup SVG e devolve a versão segura, ou null se o conteúdo
     * não parsear como XML (o chamador deve rejeitar o upload). Puro — sem
     * I/O, testável isolado.
     *
     * Remove elementos perigosos (case-insensitive — nomes de elemento SVG
     * são case-sensitive no XML, mas viram case-insensitive quando um tema
     * faz INLINE do SVG num documento HTML, onde <SCRIPT> executa), qualquer
     * handler on*, href/xlink:href com javascript:/vbscript:/data:, e style
     * com expression()/javascript:/behavior:. `<style>` é removido inteiro
     * (um ícone enviado não tem por que carregar CSS do autor). XXE fica
     * fechado: LIBXML_NONET e SEM LIBXML_NOENT; o DOCTYPE é removido.
     */
    public static function sanitizeSvgMarkup(string $content): ?string
    {
        if ($content === '') {
            return null;
        }

        $previousErrors = libxml_use_internal_errors(true);

        $dom = new \DOMDocument();
        // LIBXML_NONET impede fetch de recursos externos no parse; NÃO passamos
        // LIBXML_NOENT, então entidades internas ficam sem expandir (XXE/DoS).
        $loaded = $dom->loadXML($content, LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        if (!$loaded) {
            return null;
        }

        // 0. Remove o DOCTYPE inteiro — um DOCTYPE com entidades externas
        //    (`<!ENTITY xxe SYSTEM "file://...">`) é vetor mesmo sem expansão.
        if ($dom->doctype !== null) {
            $dom->removeChild($dom->doctype);
        }

        // 1. Remove elementos perigosos independentemente de atributos.
        //    `foreignObject` embute HTML arbitrário. A comparação é
        //    case-INSENSITIVE via translate(): local-name() é case-SENSITIVE,
        //    então sem isso <SCRIPT> escaparia. `<style>` NÃO entra aqui — é
        //    preservado (SVGs de design tools trazem CSS interno legítimo) e
        //    só cai fora se o conteúdo tiver padrão perigoso (passo 1b).
        $dangerousTags = [
            'script', 'foreignobject', 'iframe', 'object', 'embed',
            'handler', 'listener', 'set',
        ];
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $xpath = new \DOMXPath($dom);
        foreach ($dangerousTags as $tag) {
            $q = "//*[translate(local-name(),'{$upper}','{$lower}')='{$tag}']";
            foreach ($xpath->query($q) ?: [] as $node) {
                if ($node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // 1b. `<style>` fica preservado (CSS legítimo de ícones exportados de
        //     Figma/Illustrator: fill/stroke/…), MAS é removido inteiro se o
        //     conteúdo tiver um vetor conhecido — javascript:/vbscript:/
        //     expression()/behavior:/-moz-binding/@import (fetch externo).
        //     Declarações comuns não casam nada e sobrevivem intactas.
        $cssDanger = ['javascript:', 'vbscript:', 'expression(', 'behavior:', '-moz-binding', '@import'];
        $styleQ = "//*[translate(local-name(),'{$upper}','{$lower}')='style']";
        foreach ($xpath->query($styleQ) ?: [] as $styleEl) {
            $css = strtolower($styleEl->textContent);
            foreach ($cssDanger as $needle) {
                if (str_contains($css, $needle)) {
                    if ($styleEl->parentNode !== null) {
                        $styleEl->parentNode->removeChild($styleEl);
                    }
                    break;
                }
            }
        }

        // 2. Percorre os elementos restantes e remove atributos perigosos.
        foreach ($xpath->query('//*') ?: [] as $element) {
            if (!$element instanceof \DOMElement) continue;

            // Snapshot da lista (modificar durante a iteração é UB no DOM).
            $attrs = [];
            foreach ($element->attributes as $attr) {
                $attrs[] = $attr;
            }

            foreach ($attrs as $attr) {
                $localName = strtolower($attr->localName ?? $attr->name);
                $value = trim($attr->value);
                $valueLower = strtolower($value);

                $strip = false;

                // 2a. Qualquer handler de evento (onclick, onload, …).
                if (str_starts_with($localName, 'on')) {
                    $strip = true;
                }

                // 2b. href/xlink:href com esquema de execução de código.
                if (in_array($localName, ['href', 'xlink:href'], true)) {
                    if (
                        str_starts_with($valueLower, 'javascript:')
                        || str_starts_with($valueLower, 'vbscript:')
                        || str_starts_with($valueLower, 'data:')
                    ) {
                        $strip = true;
                    }
                }

                // 2c. atributo style com padrões de execução de código.
                if ($localName === 'style') {
                    if (
                        str_contains($valueLower, 'javascript:')
                        || str_contains($valueLower, 'expression(')
                        || str_contains($valueLower, 'behavior:')
                    ) {
                        $strip = true;
                    }
                }

                if ($strip) {
                    if ($attr->namespaceURI !== null && $attr->namespaceURI !== '') {
                        $element->removeAttributeNS($attr->namespaceURI, $attr->localName);
                    } else {
                        $element->removeAttribute($attr->name);
                    }
                }
            }
        }

        // 3. Regrava. saveXML() devolve false em falha (muito raro).
        $sanitized = $dom->saveXML();
        return is_string($sanitized) && $sanitized !== '' ? $sanitized : null;
    }

    private function uniqueFilename(string $name): string
    {
        $uploadsPath = App::instance()->config->path('uploads');
        if (!is_file($uploadsPath . '/' . $name)) {
            return $name;
        }
        $info = pathinfo($name);
        $base = $info['filename'];
        $ext = isset($info['extension']) ? '.' . $info['extension'] : '';
        $i = 1;
        while (true) {
            $candidate = $base . '-' . $i . $ext;
            if (!is_file($uploadsPath . '/' . $candidate)) {
                return $candidate;
            }
            $i++;
        }
    }

    /**
     * Mime real do arquivo: detecção em camadas (ver detectMimeRaw) +
     * promoção zip→OOXML pela extensão do nome original (promoteZipMime).
     *
     * Public: reaproveitado por Velk\Services\FormUploadService (campos de
     * arquivo em formulários públicos) para não duplicar a detecção de MIME
     * aqui implementada. Comportamento inalterado — só a visibilidade.
     */
    public static function detectMime(string $path, string $originalName): ?string
    {
        $mime = self::detectMimeRaw($path);
        return $mime === null ? null : self::promoteZipMime($mime, $originalName);
    }

    /**
     * Detect a file's real MIME type via three layers, in order:
     *   1. `getimagesize()` — gold standard for images, parses real header.
     *   2. Magic-byte sniffing — catches PNG/JPEG/GIF/WebP/PDF/SVG even when
     *      finfo's database is broken.
     *   3. `finfo` extension — last resort.
     *
     * Returns null when nothing recognizes it. The browser-supplied mime type
     * is never trusted (it's user-controlled).
     */
    private static function detectMimeRaw(string $path): ?string
    {
        // 1. getimagesize — works reliably for raster images.
        $info = @getimagesize($path);
        if (is_array($info) && !empty($info['mime'])) {
            return (string) $info['mime'];
        }

        // 2. Magic bytes / signature sniffing.
        $sig = self::sniffMagic($path);
        if ($sig !== null) {
            return $sig;
        }

        // 3. finfo (may return 'text/plain' or 'application/octet-stream' on
        //    some PHP versions for valid binary files; we treat those as
        //    unrecognized).
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = @$finfo->file($path);
            if (is_string($detected) && $detected !== ''
                && !in_array($detected, ['text/plain', 'application/octet-stream'], true)) {
                return $detected;
            }
        }

        return null;
    }

    public static function sniffMagic(string $path): ?string
    {
        $head = @file_get_contents($path, false, null, 0, 32);
        if (!is_string($head) || strlen($head) < 4) {
            return null;
        }

        // PNG
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) return 'image/png';
        // JPEG
        if (str_starts_with($head, "\xFF\xD8\xFF"))     return 'image/jpeg';
        // GIF
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) return 'image/gif';
        // Contêiner RIFF — tipo no offset 8 (WebP imagem, WAVE áudio)
        if (str_starts_with($head, 'RIFF')) {
            $riffType = substr($head, 8, 4);
            if ($riffType === 'WEBP') return 'image/webp';
            if ($riffType === 'WAVE') return 'audio/wav';
        }
        // PDF
        if (str_starts_with($head, '%PDF-')) return 'application/pdf';
        // BMP
        if (str_starts_with($head, 'BM')) return 'image/bmp';
        // ICO
        if (str_starts_with($head, "\x00\x00\x01\x00")) return 'image/x-icon';
        // TIFF (little-endian II*\0  or big-endian MM\0*)
        if (str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")) return 'image/tiff';
        // MP3 — tag ID3 ou frame-sync MPEG
        if (str_starts_with($head, 'ID3')) return 'audio/mpeg';
        $b2 = substr($head, 0, 2);
        if ($b2 === "\xFF\xFB" || $b2 === "\xFF\xF3" || $b2 === "\xFF\xF2") return 'audio/mpeg';
        // Ogg (áudio)
        if (str_starts_with($head, 'OggS')) return 'audio/ogg';
        // WebM — contêiner EBML; o DocType ("webm") aparece nos primeiros
        // bytes. Matroska (.mkv) usa o mesmo header sem "webm" → segue
        // não-reconhecido (fora da whitelist). Zip (PK) fica de fora daqui
        // de propósito: o finfo distingue docx/xlsx/pptx de zip genérico.
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            $ebmlHead = @file_get_contents($path, false, null, 0, 4096);
            if (is_string($ebmlHead) && str_contains($ebmlHead, 'webm')) return 'video/webm';
            return null;
        }
        // HEIC / HEIF / AVIF — ISO BMFF: 4-byte big-endian size + 'ftyp' + brand at offset 8
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            $brand = substr($head, 8, 4);
            return match ($brand) {
                'heic', 'heix', 'hevc', 'heim', 'heis', 'heiv', 'mif1', 'msf1' => 'image/heic',
                'avif', 'avis' => 'image/avif',
                'isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42',
                'avc1', 'dash', 'mmp4', 'M4V ', 'M4VP' => 'video/mp4',
                'qt  ' => 'video/quicktime',
                'M4A ' => 'audio/mp4',
                // Brand desconhecido: deixa o finfo tentar — o mime final
                // ainda precisa estar na whitelist de qualquer forma.
                default => null,
            };
        }
        // Photoshop PSD
        if (str_starts_with($head, '8BPS')) return 'image/vnd.adobe.photoshop';

        // SVG: <svg ...> or <?xml ... <svg ...>
        $longerHead = @file_get_contents($path, false, null, 0, 2048);
        if (is_string($longerHead)) {
            $trimmed = ltrim($longerHead);
            if (str_starts_with($trimmed, '<svg') ||
                (str_starts_with($trimmed, '<?xml') && str_contains($longerHead, '<svg'))) {
                return 'image/svg+xml';
            }
        }

        return null;
    }

    /**
     * Run all detection methods and report their results, plus the file's
     * first 16 bytes in hex. Used to produce actionable error messages.
     *
     * @return array{summary:string, hex:string}
     */
    private static function diagnoseMime(string $path): array
    {
        $bytes = @file_get_contents($path, false, null, 0, 16) ?: '';
        $size = @filesize($path);

        $img = @getimagesize($path);
        $imgMime = is_array($img) && !empty($img['mime']) ? $img['mime'] : 'fail';

        $magic = self::sniffMagic($path) ?? 'no match';

        $finfoMime = 'fail';
        if (class_exists(\finfo::class)) {
            $f = new \finfo(FILEINFO_MIME_TYPE);
            $detected = @$f->file($path);
            if (is_string($detected) && $detected !== '') {
                $finfoMime = $detected;
            }
        }

        $summary = sprintf(
            'getimagesize=%s, magic=%s, finfo=%s, size=%s',
            $imgMime,
            $magic,
            $finfoMime,
            $size === false ? 'fail' : ($size . ' bytes')
        );

        return [
            'summary' => $summary,
            'hex'     => bin2hex(substr($bytes, 0, 16)),
        ];
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Arquivo excede o tamanho máximo permitido.',
            UPLOAD_ERR_PARTIAL => 'Upload incompleto.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado.',
            UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário não encontrado no servidor.',
            UPLOAD_ERR_CANT_WRITE => 'Não foi possível salvar o arquivo.',
            UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP.',
            default => 'Erro desconhecido no upload.',
        };
    }
}
