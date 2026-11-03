<?php

declare(strict_types=1);

namespace Velk\Services;

use Velk\App;

/**
 * Campos de arquivo em formulários públicos (`theme/site.json → forms.*.fields[]
 * → type: "file"`). Ver docs/superpowers/specs/2026-07-28-form-file-uploads-design.md.
 *
 * Deliberadamente SEPARADO de MediaService: os arquivos aqui NUNCA viram um
 * registro `Media`, nunca aparecem em /admin/media. Ficam em
 * `storage/form-uploads/YYYY/MM/<token>.<ext>` — nome em disco é um token
 * aleatório (não o nome original), path privado por construção (storage/ é
 * negado por .htaccess/nginx), só alcançável via download autenticado do
 * admin (FormController::downloadFile + absolutePath() abaixo).
 *
 * Reaproveita a detecção de MIME e o sanitizador de SVG de MediaService (ver
 * MediaService::detectMime / sanitizeSvgMarkup) — não duplica essa lógica.
 * A whitelist e o teto de tamanho são PRÓPRIOS e mais restritos que os do
 * admin, porque o upload aqui é anônimo (visitante do site público, não um
 * usuário autenticado do painel).
 */
final class FormUploadService
{
    /**
     * mime → extensão aceita. Mais restrita que MediaService::ALLOWED_MIMES:
     * sem vídeo, sem áudio.
     *
     * SVG está listado mas NÃO é aceito por padrão: exige `accept: ["svg"]`
     * explícito no campo (ver store()). Quando aceito, é sanitizado pelo
     * mesmo sanitizador do admin e servido apenas via download autenticado
     * com `Content-Disposition: attachment`, nunca inline.
     */
    private const ALLOWED_MIMES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'image/svg+xml' => 'svg',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/zip' => 'zip',
    ];

    /** Teto default por arquivo quando o campo não declara `max_size` (MB). */
    private const DEFAULT_MAX_SIZE = 10 * 1024 * 1024;

    /**
     * Valida e persiste UM arquivo enviado por um campo `file` de formulário
     * público. `$uploaded` é a entrada crua de `$_FILES[<campo>]`; `$fieldDef`
     * é a definição do campo em site.json (usa `accept` e `max_size`).
     *
     * Retorna o array de metadados gravado em `submission.data[<campo>]`:
     *   ['_file' => true, 'path' => '2026/07/<token>.pdf',
     *    'name' => '<nome original>', 'size' => <bytes>, 'mime' => '<mime>']
     *
     * Em qualquer falha de validação/gravação, lança \RuntimeException com
     * uma mensagem pronta para exibir ao visitante (pt-BR, sem detalhe
     * técnico — diferente de MediaService::upload(), que expõe diagnóstico
     * de MIME porque quem vê o erro ali é um admin autenticado; aqui é um
     * visitante anônimo).
     */
    public static function store(array $uploaded, array $fieldDef): array
    {
        $error = (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new FormUploadException(self::uploadErrorCode($error));
        }

        $size = (int) ($uploaded['size'] ?? 0);
        if ($size <= 0) {
            throw new FormUploadException('empty_file');
        }

        $tmp = (string) ($uploaded['tmp_name'] ?? '');
        if ($tmp === '' || (!is_uploaded_file($tmp) && !is_file($tmp))) {
            throw new FormUploadException('invalid_upload');
        }

        $originalName = mb_substr((string) ($uploaded['name'] ?? 'arquivo'), 0, 255);

        // MIME real (finfo + magic bytes + promoção zip→OOXML) — mesma
        // detecção do admin, via MediaService::detectMime (agora public).
        $mime = MediaService::detectMime($tmp, $originalName);
        if ($mime === null || !isset(self::ALLOWED_MIMES[$mime])) {
            throw new FormUploadException('type_not_allowed');
        }

        $ext = self::ALLOWED_MIMES[$mime];

        // `accept` do campo (site.json) restringe AINDA MAIS a whitelist —
        // nunca a alarga (normalizeAccept descarta qualquer extensão fora
        // de ALLOWED_MIMES).
        $accept = self::normalizeAccept($fieldDef['accept'] ?? null);
        if ($accept !== [] && !in_array($ext, $accept, true)) {
            throw new FormUploadException(
                'type_not_accepted',
                ['accepted' => implode(', ', $accept)],
                'Type not accepted for this field'
            );
        }

        // SVG exige OPT-IN explícito do campo (`accept: ["svg"]`). Seguro por
        // padrão: SVG é XML executável, sanitizadores vivem em corrida com
        // novos bypasses, e aqui quem envia é anônimo. Sanitizamos e servimos
        // só como attachment — mas um formulário que não pediu SVG não deve
        // recebê-lo por acidente. Quem tem o caso legítimo (logo em briefing)
        // declara `accept` e assume a escolha.
        if ($ext === 'svg' && $accept === []) {
            throw new FormUploadException('type_not_allowed');
        }

        $maxSize = self::maxSizeFor($fieldDef);
        if ($size > $maxSize) {
            throw new FormUploadException(
                'too_large',
                ['max_mb' => (int) round($maxSize / 1024 / 1024)],
                'File exceeds the field limit'
            );
        }

        $shard = self::shardDir();
        $token = bin2hex(random_bytes(16)); // 32 hex — nome em disco, NÃO o original
        $filename = $token . '.' . $ext;
        $destPath = $shard['abs'] . '/' . $filename;

        if (is_uploaded_file($tmp)) {
            if (!@move_uploaded_file($tmp, $destPath)) {
                throw new FormUploadException('store_failed');
            }
        } else {
            // Fallback pra chamadas fora de um request HTTP real (testes) —
            // mesmo padrão de MediaService::upload().
            if (!@copy($tmp, $destPath)) {
                throw new FormUploadException('store_failed');
            }
        }

        // SVG: sanitiza em disco e rejeita se não sanitizar (mesma garantia
        // do upload do admin — reaproveita MediaService::sanitizeSvgMarkup,
        // não reescreve o sanitizador).
        if ($mime === 'image/svg+xml') {
            $content = @file_get_contents($destPath);
            $sanitized = is_string($content) ? MediaService::sanitizeSvgMarkup($content) : null;
            if ($sanitized === null) {
                @unlink($destPath);
                throw new FormUploadException('invalid_svg');
            }
            @file_put_contents($destPath, $sanitized);
            clearstatcache(true, $destPath);
        }

        $finalSize = (int) (@filesize($destPath) ?: $size);

        return [
            '_file' => true,
            'path'  => $shard['rel'] . '/' . $filename,
            'name'  => $originalName,
            'size'  => $finalSize,
            'mime'  => $mime,
        ];
    }

    /**
     * Resolve um `path` gravado em `submission.data[<campo>]` para o caminho
     * absoluto no disco, com containment por realpath() — mesmo padrão do
     * UploadController::serve(). `null` quando o caminho tenta escapar de
     * `form_uploads` (`../`, symlink apontando pra fora) OU quando o arquivo
     * não existe. O caminho SEMPRE vem da submissão gravada no banco, nunca
     * de input do usuário no momento do download (ver FormController::downloadFile).
     */
    public static function absolutePath(string $relPath): ?string
    {
        if ($relPath === '' || str_contains($relPath, '..')) {
            return null;
        }

        $base = App::instance()->config->path('form_uploads');
        $baseReal = realpath($base);
        if ($baseReal === false) {
            return null;
        }

        $full = realpath($base . '/' . $relPath);
        if ($full === false) {
            return null;
        }

        if (!str_starts_with($full, $baseReal . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return is_file($full) ? $full : null;
    }

    /**
     * Apaga o arquivo de um `path` gravado. Não-fatal: arquivo ausente ou
     * caminho inválido/fora de `form_uploads` simplesmente não faz nada —
     * usado tanto na limpeza de órfãos (submissão rejeitada na validação
     * depois de já ter gravado um arquivo) quanto na exclusão de submissão
     * (FormController::deleteSubmission).
     */
    public static function delete(string $relPath): void
    {
        $abs = self::absolutePath($relPath);
        if ($abs !== null) {
            @unlink($abs);
        }
    }

    /**
     * Tamanho legível (B/KB/MB) — mesma convenção de Media::humanSize().
     * Usado pelos pontos de renderização (admin + email) via o helper
     * global human_filesize() em core/helpers.php.
     */
    public static function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return number_format($bytes / 1024, 1) . ' KB';
        return number_format($bytes / 1024 / 1024, 1) . ' MB';
    }

    /**
     * Diretório de destino shardado por ano/mês (`form_uploads/YYYY/MM`),
     * criado sob demanda (0775 recursivo). Retorna o absoluto (pra
     * gravar) e o relativo (pra persistir em submission.data).
     *
     * @return array{abs: string, rel: string}
     */
    private static function shardDir(): array
    {
        $base = App::instance()->config->path('form_uploads');
        $rel = date('Y/m');
        $abs = $base . '/' . $rel;
        if (!is_dir($abs)) {
            @mkdir($abs, 0775, true);
            @chmod($abs, 0775);
        }
        return ['abs' => $abs, 'rel' => $rel];
    }

    /**
     * Normaliza `accept` do campo (site.json) pra uma lista de extensões
     * válidas — só as que existem em ALLOWED_MIMES sobrevivem, dedupe,
     * minúsculas. Entrada ausente/inválida ou vazia = [] (sem restrição
     * além da whitelist inteira). Pura — testável isolada.
     *
     * @return list<string>
     */
    private static function normalizeAccept(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $validExts = array_values(self::ALLOWED_MIMES);
        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) continue;
            $ext = strtolower(trim($item));
            if ($ext !== '' && in_array($ext, $validExts, true) && !in_array($ext, $out, true)) {
                $out[] = $ext;
            }
        }
        return $out;
    }

    /** Teto em bytes: `max_size` do campo (MB) se declarado e > 0, senão DEFAULT_MAX_SIZE. */
    private static function maxSizeFor(array $fieldDef): int
    {
        $declared = $fieldDef['max_size'] ?? null;
        if (is_numeric($declared) && (float) $declared > 0) {
            return (int) round(((float) $declared) * 1024 * 1024);
        }
        return self::DEFAULT_MAX_SIZE;
    }

    /** Mesmas mensagens de MediaService::uploadErrorMessage() — UX consistente. */
    /**
     * Código do erro nativo do PHP.
     *
     * Os três últimos são falha de SERVIDOR, não do visitante — colapsam num
     * código só porque a distinção não muda nada para quem está na tela, e
     * detalhar diretório temporário ou extensão do PHP entrega infraestrutura
     * a quem não deveria ver. O `getMessage()` mantém o detalhe para o log.
     */
    private static function uploadErrorCode(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'too_large',
            UPLOAD_ERR_PARTIAL => 'incomplete_upload',
            UPLOAD_ERR_NO_FILE => 'no_file',
            default => 'store_failed',
        };
    }
}
