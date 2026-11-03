<?php
declare(strict_types=1);
use Velk\Admin\I18n;

function admin_i18n(string $current = 'es'): I18n {
    $i = new I18n(__DIR__ . '/../fixtures/admin-lang', 'pt', 'en');
    $i->setCurrent($current);
    return $i;
}

test('translate returns active-locale value', function () {
    check_same('A_es', admin_i18n('es')->translate('a'));
});
test('missing in active falls back to default', function () {
    check_same('B_pt', admin_i18n('es')->translate('b'), 'es misses b → default pt');
});
test('missing in active+default falls back to source en', function () {
    check_same('C_en', admin_i18n('es')->translate('c'), 'es+pt miss c → en');
});
test('missing everywhere returns the key', function () {
    check_same('z.z', admin_i18n('es')->translate('z.z'));
});
test('interpolates named placeholders', function () {
    check_same('3 items', admin_i18n('en')->translate('items.count', ['count' => 3]));
});
test('interpolation escapes html in params', function () {
    $i = admin_i18n('en');
    check_same('&lt;b&gt; items', $i->translate('items.count', ['count' => '<b>']));
});
test('available lists catalog locales sorted', function () {
    check_same(['en','es','pt'], admin_i18n()->available());
});
test('labelFor maps known codes', function () {
    check_same('Português', admin_i18n()->labelFor('pt'));
});

// I10: subjects are built via __() into a plain-text sink (the email
// Subject header), not HTML — so params must NOT be HTML-escaped there.
// translateRaw() is the same lookup/fallback/interpolation as translate(),
// minus the escaping step.
test('translateRaw: interpola placeholders SEM escapar HTML', function () {
    $i = admin_i18n('en');
    check_same('<b> items', $i->translateRaw('items.count', ['count' => '<b>']));
});
test('translateRaw: apóstrofo e "&" ficam literais (regressão I10 — Subject de email)', function () {
    $i = admin_i18n('en');
    check_same("Joe's & Co items", $i->translateRaw('items.count', ['count' => "Joe's & Co"]));
});
test('translateRaw: sem params, igual translate()', function () {
    check_same('A_es', admin_i18n('es')->translateRaw('a'));
});
test('translateRaw: fallback de locale (ativo → default → source → key) igual translate()', function () {
    check_same('B_pt', admin_i18n('es')->translateRaw('b'), 'es misses b → default pt');
    check_same('C_en', admin_i18n('es')->translateRaw('c'), 'es+pt miss c → en');
    check_same('z.z', admin_i18n('es')->translateRaw('z.z'), 'missing everywhere → key');
});
test('translate() continua escapando HTML nos params (uso em corpo HTML preservado)', function () {
    $i = admin_i18n('en');
    check_same('&amp; items', $i->translate('items.count', ['count' => '&']));
    check_same('Joe&#039;s &amp; Co items', $i->translate('items.count', ['count' => "Joe's & Co"]));
});
