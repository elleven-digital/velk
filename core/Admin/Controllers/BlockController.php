<?php

declare(strict_types=1);

namespace Velk\Admin\Controllers;

use Velk\FieldRenderer;
use Velk\Request;
use Velk\Response;
use Velk\Services\VariantService;

/**
 * A janela de um bloco do tema, aberta pelo editor de texto (admin.js).
 *
 * O bloco é declarado no `site.json` (`richtext_blocks`) e inserido no meio de um
 * campo richtext. Os dados dele vivem no próprio texto, num marcador (ver
 * Velk\RichtextBlocks), então estas duas rotas não gravam nada: `form`
 * desenha o formulário dos campos do bloco, e `save` devolve os valores
 * normalizados à página de fora, que os guarda no editor.
 *
 * As duas respondem por POST, dentro do iframe da janela (layout embed), com
 * login e CSRF como a edição de um item. Abrir por POST, e não por GET, é
 * porque os dados de um bloco que está sendo editado não cabem numa URL.
 */
final class BlockController extends Controller
{
    /**
     * O formulário do bloco pedido (`type`). A escolha do bloco é na barra do
     * editor, numa faixa com os blocos do campo (admin.js); a janela já abre
     * sabendo qual é.
     */
    public function form(Request $request): Response
    {
        $guard = $this->guard($request);
        if ($guard !== null) return $guard;

        $config = $this->app->config;
        $type = $request->post['type'] ?? '';
        $def = is_string($type) ? $config->richtextBlock($type) : null;
        if ($def === null) {
            return Response::notFound();
        }

        // `data[]=x` chega como lista: não é o JSON do bloco, e abre vazio.
        $raw = $request->post['data'] ?? '';
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return $this->render('blocks/form', [
            'type' => $type,
            'label' => (string) ($def['label'] ?? $type),
            'fieldDefs' => $config->richtextBlockFields($type),
            'values' => is_array($data) ? $data : [],
        ], 'layout/embed');
    }

    /**
     * Os campos do bloco, normalizados como os de qualquer formulário do
     * painel, entregues à página de fora num bloco de dados (a tela
     * `blocks/saved`; o admin.js o lê e chama o editor).
     *
     * As variantes de imagem são geradas aqui: é quando a declaração e os
     * valores estão lado a lado. O salvamento do item vê o bloco como texto.
     */
    public function save(Request $request): Response
    {
        $guard = $this->guard($request);
        if ($guard !== null) return $guard;

        $type = $request->post['type'] ?? '';
        if (!is_string($type) || $this->app->config->richtextBlock($type) === null) {
            return Response::notFound();
        }

        $defs = $this->app->config->richtextBlockFields($type);
        $values = FieldRenderer::collect($defs, $request->post['block'] ?? []);
        VariantService::ensureForFields($defs, $values);

        return $this->render('blocks/saved', ['result' => ['type' => $type, 'data' => $values]], 'layout/embed');
    }

    private function guard(Request $request): ?Response
    {
        return $this->requireAuth($request) ?? $this->verifyCsrfOrFail($request);
    }
}
