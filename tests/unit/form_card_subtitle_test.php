<?php

declare(strict_types=1);

use Velk\Admin\Controllers\FormController;
use Velk\Request;

/**
 * A segunda linha do cartão fechado na lista de preenchimentos: o campo de
 * `forms.<id>.preview_subtitle_field`, quando há, antes da data — e o IP fora
 * dela. Sem subtítulo, só a data. E o schema:validate recusando um nome que
 * não é campo do formulário, como faz com o `preview_field`.
 */

// admin_url()/base_path(), chamados pelo controller, vêm de core/helpers.php.
require_once dirname(__DIR__, 2) . '/core/helpers.php';
require_once dirname(__DIR__) . '/fixtures/form_export_boot.php';
require_once dirname(__DIR__) . '/fixtures/schema_validate_form.php';

if (!function_exists('fcs_line')) {
    /** A segunda linha do cartão do único lead, sem tags e com os espaços juntados. */
    function fcs_line(array $formExtra, string $data = '{"nome":"Ana","empresa":"ACME"}'): string
    {
        $app = fexb_boot(['label' => 'Contato', 'fields' => [
            ['name' => 'nome', 'label' => 'Nome', 'type' => 'text'],
            ['name' => 'empresa', 'label' => 'Empresa', 'type' => 'text'],
        ]] + $formExtra, [[1, null, null, $data, null, '2026-08-22 13:19:00']]);
        $app->db->pdo()->exec("UPDATE form_submissions SET ip = '127.0.0.1'");
        fexb_admin($app, true);
        $html = (new FormController())
            ->show(new Request('GET', '/admin/forms/contato', [], [], [], [], []), ['id' => 'contato'])
            ->getBody();
        if (!preg_match('#<div class="muted form-card__meta">(.*?)</div>#s', $html, $m)) {
            throw new RuntimeException('a linha de baixo do cartão não foi encontrada');
        }
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($m[1])));
    }
}

test('cartão: o subtítulo vem antes da data, e o IP saiu da linha', function () {
    check_same('ACME · 22/08/2026 13:19', fcs_line(['preview_subtitle_field' => 'empresa']));
});

test('cartão: sem preview_subtitle_field, só a data', function () {
    check_same('22/08/2026 13:19', fcs_line([]));
});

test('cartão: o campo configurado vazio neste lead — só a data, sem separador sobrando', function () {
    check_same('22/08/2026 13:19', fcs_line(['preview_subtitle_field' => 'empresa'], '{"nome":"Ana","empresa":""}'));
});

test('validate: preview_subtitle_field que não é campo do formulário é acusado, com os válidos', function () {
    $out = svf_validate([
        'fields' => [['name' => 'nome', 'type' => 'text'], ['name' => 'empresa', 'type' => 'text']],
        'preview_subtitle_field' => 'cargo',
    ]);
    check(str_contains($out, "'preview_subtitle_field' points to \"cargo\", which is not a field of this form. Available: nome, empresa."), $out);
});

test('validate: preview_subtitle_field vazio é acusado', function () {
    $out = svf_validate(['fields' => [['name' => 'nome', 'type' => 'text']], 'preview_subtitle_field' => '']);
    check(str_contains($out, "'preview_subtitle_field' must be a non-empty field name."), $out);
});

test('validate: preview_subtitle_field apontando um campo do formulário passa', function () {
    $out = svf_validate([
        'fields' => [['name' => 'nome', 'type' => 'text'], ['name' => 'empresa', 'type' => 'text']],
        'preview_subtitle_field' => 'empresa',
    ]);
    check(!str_contains($out, 'preview_subtitle_field'), $out);
});
