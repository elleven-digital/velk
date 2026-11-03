<?php

declare(strict_types=1);

use Velk\Commands\Content;

/**
 * I15: valueMatchesType() gates `content:item-create` / `item-update`
 * field validation. image/file fields are stored as an INTEGER media id
 * (FieldRenderer::collect default branch stores $raw as-is; AGENTS.md:
 * "Reference to a media library entry. Stored as integer ID, not URL.") —
 * but the CLI required is_string(), so the canonical
 * '{"fields":{"thumbnail":12}}' (a JSON number, decoded to a PHP int) was
 * rejected as "wrong type". Fixed to accept a positive int, or a numeric
 * string (defensive — some callers may pass IDs as strings).
 *
 * That fix left out the field's own "no media": the admin form stores "0"
 * (never picked) and "" (removed), so content saved there was rejected —
 * see content_validate_fields_test.php. Zero and "" are accepted too now.
 */

if (!function_exists('value_matches_type')) {
    function value_matches_type(mixed $value, string $type): bool
    {
        $method = new ReflectionMethod(Content::class, 'valueMatchesType');
        return $method->invoke(null, $value, $type);
    }
}

test('image/file: aceita o id canônico (int positivo, como um JSON number decodificado)', function () {
    check(true === value_matches_type(12, 'image'));
    check(true === value_matches_type(1, 'file'));
});

test('image/file: aceita string numérica positiva (defensivo)', function () {
    check(true === value_matches_type('12', 'image'));
    check(true === value_matches_type('12', 'file'));
});

test('image/file: aceita o vazio que o painel grava — "0" (mídia nunca escolhida), "" (removida) — e o zero inteiro', function () {
    foreach (['image', 'file'] as $type) {
        check(true === value_matches_type('0', $type), "'0' em {$type}");
        check(true === value_matches_type('', $type), "'' em {$type}");
        check(true === value_matches_type(0, $type), "0 em {$type}");
    }
});

test('image/file: rejeita negativo, não-numérico e o que só "parece" vazio', function () {
    check(false === value_matches_type(-5, 'image'));
    check(false === value_matches_type('-5', 'file'));
    check(false === value_matches_type('abc', 'image'));
    check(false === value_matches_type([12], 'file'));
    check(false === value_matches_type([], 'image'));
    check(false === value_matches_type(false, 'file'));
});

test('image/file: rejeita URL/string arbitrária — não é mais o contrato (integer id, não URL)', function () {
    check(false === value_matches_type('https://example.com/photo.jpg', 'image'));
});

test('image/file: null continua permitido (campo não setado)', function () {
    check(true === value_matches_type(null, 'image'));
    check(true === value_matches_type(null, 'file'));
});

test('outros tipos string continuam exigindo is_string (comportamento preservado)', function () {
    check(true === value_matches_type('hello', 'text'));
    check(false === value_matches_type(12, 'text'), 'text não vira número — só image/file ganharam a exceção int');
    check(true === value_matches_type('#fff', 'color'));
    check(true === value_matches_type('a@b.com', 'email'));
});
