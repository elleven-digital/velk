<?php

declare(strict_types=1);

namespace Velk;

final class ThemeAssetController
{
    public function serve(Request $request, array $params): Response
    {
        $path = (string) ($params['path'] ?? '');
        if ($path === '' || str_contains($path, '..')) {
            return Response::notFound('Invalid theme asset path.');
        }

        $themePath = App::instance()->config->path('theme');
        $file = realpath($themePath . '/' . ltrim($path, '/'));
        $themeReal = realpath($themePath);

        if ($file === false || $themeReal === false || !str_starts_with($file, $themeReal . DIRECTORY_SEPARATOR)) {
            return Response::notFound('Theme asset not found.');
        }
        if (!is_file($file)) {
            return Response::notFound('Theme asset not found.');
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            return Response::notFound('PHP files cannot be served as assets.');
        }

        $mime = self::mimeFor($ext, $file);
        $contents = (string) file_get_contents($file);

        return (new Response())
            ->status(200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'public, max-age=86400')
            ->body($contents);
    }

    private static function mimeFor(string $ext, string $file): string
    {
        $map = [
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if (function_exists('mime_content_type')) {
            $detected = mime_content_type($file);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
        return 'application/octet-stream';
    }
}
