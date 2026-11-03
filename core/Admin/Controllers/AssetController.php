<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\App;
use Velk\Request;
use Velk\Response;

final class AssetController
{
    public function css(Request $request, array $params): Response
    {
        return $this->serve($params['file'] ?? '', 'css', 'text/css; charset=UTF-8');
    }

    public function js(Request $request, array $params): Response
    {
        return $this->serve($params['file'] ?? '', 'js', 'application/javascript; charset=UTF-8');
    }

    public function font(Request $request, array $params): Response
    {
        $file = (string) ($params['file'] ?? '');
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            default => 'application/octet-stream',
        };
        return $this->serve($file, 'fonts', $mime);
    }

    private function serve(string $filename, string $subdir, string $mime): Response
    {
        if ($filename === '' || str_contains($filename, '..') || str_contains($filename, '/')) {
            return Response::notFound('Invalid asset path.');
        }
        $base = App::instance()->config->path('core') . '/Admin/assets/' . $subdir;
        $file = $base . '/' . $filename;
        if (!is_file($file)) {
            return Response::notFound('Asset not found.');
        }

        $contents = (string) file_get_contents($file);
        return (new Response())
            ->status(200)
            ->header('Content-Type', $mime)
            ->header('Cache-Control', 'public, max-age=86400')
            ->body($contents);
    }
}
