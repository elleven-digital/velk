<?php

declare(strict_types=1);

/**
 * I10: core/Admin/I18n::translate() HTML-escapes interpolated params — right
 * for the HTML sinks __() is normally used in, wrong for the two email
 * Subject headers (a plain-text sink), which rendered e.g.
 * "Joe&#039;s &amp; Co — ..." instead of "Joe's & Co — ...". Fixed by
 * routing ONLY those two subject lines through a non-escaping variant.
 *
 * Boots the lightest real App (Config only reads config/env.php +
 * theme/site.json, no DB) with adminI18n pointed at the REAL catalogs —
 * same technique as field_file_test.php — so this exercises the actual
 * production strings, not a stub fixture.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('email_subject_test_app')) {
    function email_subject_test_app(): \Velk\App
    {
        $app = new \Velk\App(new \Velk\Config(dirname(__DIR__, 2)));
        $app->adminI18n = new \Velk\Admin\I18n(dirname(__DIR__, 2) . '/core/Admin/lang', 'en', 'en');
        return $app;
    }
}

test('__raw(): subject do OTP com nome de site "Joe\'s & Co" fica literal, sem entidades HTML', function () {
    email_subject_test_app();
    $subject = __raw('email.otp.subject', ['site' => "Joe's & Co"]);
    check_same("Joe's & Co — your verification code", $subject);
    check(!str_contains($subject, '&#039;'), 'não pode escapar o apóstrofo num Subject de email');
    check(!str_contains($subject, '&amp;'), 'não pode escapar o & num Subject de email');
});

test('__raw(): subject do reset de senha também fica literal', function () {
    email_subject_test_app();
    $subject = __raw('email.reset.subject', ['site' => "Joe's & Co"]);
    check_same("Password reset — Joe's & Co", $subject);
    check(!str_contains($subject, '&#039;') && !str_contains($subject, '&amp;'));
});

test('__(): permanece escapando (uso em HTML-body preservado — não regrediu)', function () {
    email_subject_test_app();
    $escaped = __('email.otp.subject', ['site' => "Joe's & Co"]);
    check(str_contains($escaped, '&#039;'), '__() em contexto HTML deve continuar escapando');
});

// Regression guards on the actual call sites — a static check that the two
// known Subject builders route through __raw(), not __(), so this can't
// silently regress back to the escaped variant.
if (!function_exists('read_source')) {
    function read_source(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
    }
}

test('EmailOtpService: Subject do código usa __raw(), não __()', function () {
    $src = read_source('core/Services/EmailOtpService.php');
    check(
        (bool) preg_match('/__raw\(\s*[\'"]email\.otp\.subject[\'"]/', $src),
        'esperava __raw(\'email.otp.subject\', ...) no Subject do OTP'
    );
});

test('PasswordResetController: Subject do reset usa __raw(), não __()', function () {
    $src = read_source('core/Admin/Controllers/PasswordResetController.php');
    check(
        (bool) preg_match('/__raw\(\s*[\'"]email\.reset\.subject[\'"]/', $src),
        'esperava __raw(\'email.reset.subject\', ...) no Subject do reset'
    );
});
