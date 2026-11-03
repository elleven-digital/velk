<?php

declare(strict_types=1);

/**
 * Regressões de CSS do campo `item` (combobox com busca).
 *
 * O JS esconde opções e o botão limpar via o atributo `hidden` (o.hidden=true).
 * Mas `[hidden]` (UA: display:none) PERDE pro `display` do autor — e
 * `.item-field__option`/`.item-field__clear` definem display. Sem uma regra
 * `[hidden]` explícita, esconder não funciona (a busca não filtra; o × fica
 * sempre visível). E o menu absoluto é clipado pelo `overflow:hidden` da linha
 * de repeater/flexible (que existe pros cantos arredondados).
 */

if (!function_exists('velk_admin_css')) {
    function velk_admin_css(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/core/Admin/assets/css/admin.css'
        );
    }
}

test('item CSS: opção e botão limpar somem de fato com [hidden]', function () {
    $css = velk_admin_css();
    check(
        (bool) preg_match('/\.item-field__option\[hidden\]\s*\{\s*display:\s*none/', $css),
        'falta .item-field__option[hidden]{display:none} — a busca não esconde opções'
    );
    check(
        (bool) preg_match('/\.item-field__clear\[hidden\]\s*\{\s*display:\s*none/', $css),
        'falta .item-field__clear[hidden]{display:none} — o botão limpar fica sempre visível'
    );
});

test('item CSS: menu escapa do overflow da linha (repeater/flexible)', function () {
    $css = velk_admin_css();
    check(
        str_contains($css, ':has(.item-field__menu:not([hidden]))'),
        'falta a regra :has() que libera o overflow da linha enquanto o menu está aberto'
    );
});
