<?php

declare(strict_types=1);

use Velk\Services\TurnstileService;

/**
 * Turnstile — o que dá pra provar sem rede.
 *
 * A chamada à Cloudflare não é exercitada aqui (seria teste de rede, não de
 * unidade). O que importa travar são as decisões que NÃO dependem do
 * provedor responder, e que são justamente as que definem o comportamento
 * em produção:
 *
 *   - sem chave no .env, o recurso inteiro fica inerte
 *   - token ausente é rejeitado antes de qualquer round-trip
 *
 * A falha aberta (Cloudflare fora → submissão passa) foi verificada por
 * script descartável apontando a VERIFY_URL pra um host morto — mesmo
 * padrão de variant_service_test.
 */

// O helper de ambiente (`fst_with_turnstile_env`) morava aqui e virou fixture:
// `form_step_antispam_test.php` também precisa dele e carrega ANTES deste no
// alfabeto do glob, então um helper definido aqui não existiria lá. O
// docblock dele conta a história inteira.
require_once dirname(__DIR__) . '/fixtures/form_step_boot.php';

test('isConfigured: exige as DUAS chaves', function () {
    check(!fst_with_turnstile_env(null, null, fn() => TurnstileService::isConfigured()), 'sem nenhuma');
    check(!fst_with_turnstile_env('site', null, fn() => TurnstileService::isConfigured()), 'só a site key');
    check(!fst_with_turnstile_env(null, 'secret', fn() => TurnstileService::isConfigured()), 'só a secret');
    check(fst_with_turnstile_env('site', 'secret', fn() => TurnstileService::isConfigured()), 'as duas');
});

test('isConfigured: chave só com espaço não conta como configurada', function () {
    check(!fst_with_turnstile_env('   ', 'secret', fn() => TurnstileService::isConfigured()));
});

test('verify: sem configuração, sempre libera', function () {
    // É o que faz um tema que já imprime form_captcha() continuar
    // funcionando idêntico num site sem chave nenhuma.
    check(fst_with_turnstile_env(null, null, fn() => TurnstileService::verify(null)));
    check(fst_with_turnstile_env(null, null, fn() => TurnstileService::verify('qualquer-coisa')));
});

test('verify: configurado e SEM token, rejeita sem chamar a rede', function () {
    // Widget não renderizado, JS bloqueado, ou POST direto de bot.
    check(!fst_with_turnstile_env('site', 'secret', fn() => TurnstileService::verify(null)));
    check(!fst_with_turnstile_env('site', 'secret', fn() => TurnstileService::verify('')));
    check(!fst_with_turnstile_env('site', 'secret', fn() => TurnstileService::verify('   ')));
});

test('siteKey é pública; a secret não é exposta', function () {
    // A site key vai no HTML, então tem getter. A secret não pode ter —
    // se um dia alguém precisar dela fora da classe, é sinal de que o
    // desenho vazou.
    check_same('abc123', fst_with_turnstile_env('abc123', 'secret', fn() => TurnstileService::siteKey()));

    $m = new ReflectionMethod(TurnstileService::class, 'secretKey');
    check($m->isPrivate(), 'secretKey() precisa continuar privada');
});

test('o campo do token é o nome que o widget da Cloudflare injeta', function () {
    // Se isto divergir, o token chega e o Velk não acha — falha silenciosa
    // que só aparece como "todo mundo reprovando".
    check_same('cf-turnstile-response', TurnstileService::RESPONSE_FIELD);
});
