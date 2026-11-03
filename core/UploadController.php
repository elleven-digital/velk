<?php

declare(strict_types=1);

namespace Velk;

final class UploadController
{
    public function serve(Request $request, array $params): Response
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '' || str_contains($path, '..')) {
            return Response::notFound();
        }

        $uploadsPath = App::instance()->config->path('uploads');

        // Serves both originals and generated variants — variants live
        // under `_variants/<name>/<file>` inside the uploads directory, so
        // the same route and containment check cover both. The path must
        // resolve inside the uploads directory; symlinks pointing outside
        // are blocked via realpath comparison.
        $file = realpath($uploadsPath . '/' . $path);
        $uploadsReal = realpath($uploadsPath);
        if ($file === false || $uploadsReal === false || !str_starts_with($file, $uploadsReal . DIRECTORY_SEPARATOR)) {
            return Response::notFound();
        }
        if (!is_file($file)) {
            return Response::notFound();
        }

        return $this->serveFile($file);
    }

    /**
     * Range único "bytes=a-b" | "bytes=a-" | "bytes=-n".
     * null  → sem/ignorável (serve 200 completo — a RFC permite ignorar);
     * array → [start, end] válidos (206);
     * false → sintaticamente ok mas fora do arquivo (416).
     * Pura — testável isolada.
     */
    public static function parseRange(?string $header, int $fileSize): null|array|false
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($header), $m)) {
            return null; // lixo, multi-range, unidade desconhecida
        }
        [, $rawStart, $rawEnd] = $m;
        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            // Sufixo: últimos N bytes.
            $n = (int) $rawEnd;
            if ($n <= 0 || $fileSize <= 0) {
                return false;
            }
            return [max(0, $fileSize - $n), $fileSize - 1];
        }

        $start = (int) $rawStart;
        if ($start >= $fileSize) {
            return false;
        }
        $end = $rawEnd === '' ? $fileSize - 1 : (int) $rawEnd;
        if ($end < $start) {
            return null;
        }
        return [$start, min($end, $fileSize - 1)];
    }

    private function serveFile(string $file): Response
    {
        $mime = function_exists('mime_content_type')
            ? (mime_content_type($file) ?: 'application/octet-stream')
            : 'application/octet-stream';
        $size = (int) filesize($file);

        $res = (new Response())
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'public, max-age=2592000')
            ->header('Accept-Ranges', 'bytes');

        // SVG é o único upload que o navegador EXECUTA quando aberto direto
        // pela URL: é XML com <script>, handlers e href de esquema. O
        // MediaService sanitiza no upload (DOM, não regex), mas sanitizador
        // de SVG é uma corrida contra vetores novos, e este header é a rede
        // por baixo dela: `sandbox` bloqueia script e dá ao documento uma
        // origem opaca, então mesmo um vetor que escape não alcança cookies
        // nem o DOM do site. Não afeta <img src="..."> — imagem não executa
        // script de qualquer jeito, e CSP de resposta não se aplica a ela.
        if ($mime === 'image/svg+xml') {
            $res->header('Content-Security-Policy', 'sandbox');
        }

        $range = self::parseRange($_SERVER['HTTP_RANGE'] ?? null, $size);

        if ($range === false) {
            return $res->status(416)->header('Content-Range', "bytes */{$size}");
        }

        if ($range !== null) {
            [$start, $end] = $range;
            $length = $end - $start + 1;
            $res->status(206)->header('Content-Range', "bytes {$start}-{$end}/{$size}");
        } else {
            $start = 0;
            $length = $size;
            $res->status(200);
        }

        $res->header('Content-Length', (string) $length);

        // Chunks de 8 KB — vídeo faz seek (Safari exige Range) e nenhum
        // arquivo é mais carregado inteiro na memória.
        return $res->stream(function () use ($file, $start, $length): void {
            $fh = @fopen($file, 'rb');
            if ($fh === false) {
                return;
            }
            if ($start > 0) {
                fseek($fh, $start);
            }
            $remaining = $length;
            while ($remaining > 0 && !feof($fh)) {
                $chunk = fread($fh, (int) min(8192, $remaining));
                if ($chunk === false || $chunk === '') {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($fh);
        });
    }
}
