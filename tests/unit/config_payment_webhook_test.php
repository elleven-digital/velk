<?php

declare(strict_types=1);

use Velk\Config;

/**
 * T2 (payment-webhook): Config::paymentWebhook() — normalização da chave de
 * TOPO `payment_webhook` em site.json, mesma forma aceita pelo webhook de
 * formulários (`forms.<id>.webhook`, ver PublicFormController::dispatchWebhook
 * e Velk\Services\WebhookDispatcher): uma string (um handler) ou uma lista de
 * strings (vários, em ordem). Permissivo — nunca lança, só normaliza a FORMA;
 * validar se o formato é aceitável é trabalho de schema:validate (bin/velk).
 *
 * Config exige rootPath + env.php no construtor — pra testes puros, instancia
 * sem construtor e injeta o array `site` via reflection (mesma técnica de
 * tests/unit/config_image_variants_test.php — paymentWebhook() só lê
 * $this->site, igual imageVariants()).
 */
if (!function_exists('config_with_site')) {
    function config_with_site(array $site): Config
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(Config::class, 'site');
        $prop->setValue($config, $site);
        return $config;
    }
}

test('paymentWebhook: string não-vazia vira lista de um elemento', function () {
    $config = config_with_site(['payment_webhook' => 'studioweb_payment_event']);
    check_same(['studioweb_payment_event'], $config->paymentWebhook());
});

test('paymentWebhook: lista de strings é mantida na mesma ordem', function () {
    $config = config_with_site(['payment_webhook' => ['deliver_ebook', 'notify_crm']]);
    check_same(['deliver_ebook', 'notify_crm'], $config->paymentWebhook());
});

test('paymentWebhook: lista mista descarta entradas não-string e vazias, preservando a ordem das válidas', function () {
    $config = config_with_site(['payment_webhook' => ['deliver_ebook', '', 42, null, true, 'notify_crm', []]]);
    check_same(['deliver_ebook', 'notify_crm'], $config->paymentWebhook());
});

test('paymentWebhook: chave ausente vira lista vazia', function () {
    $config = config_with_site([]);
    check_same([], $config->paymentWebhook());
});

test('paymentWebhook: string vazia vira lista vazia', function () {
    $config = config_with_site(['payment_webhook' => '']);
    check_same([], $config->paymentWebhook());
});

test('paymentWebhook: array vazio vira lista vazia', function () {
    $config = config_with_site(['payment_webhook' => []]);
    check_same([], $config->paymentWebhook());
});

test('paymentWebhook: inteiro vira lista vazia', function () {
    $config = config_with_site(['payment_webhook' => 42]);
    check_same([], $config->paymentWebhook());
});

test('paymentWebhook: booleano vira lista vazia', function () {
    $config = config_with_site(['payment_webhook' => true]);
    check_same([], $config->paymentWebhook());
});

test('paymentWebhook: array associativo (chaves string, ex.: {"a":"b"}) é tratado como forma inválida — vira lista vazia', function () {
    // Decisão de design: json_decode de um objeto JSON vira array associativo
    // em PHP, indistinguível de uma lista só pelo tipo — array_is_list() é
    // quem separa os dois casos. Um objeto no lugar de string|list é uma
    // forma que schema:validate também vai rejeitar (nem string, nem lista);
    // aqui o comportamento é o mesmo "silenciosamente inválido" aplicado a
    // qualquer outro tipo fora do contrato.
    $config = config_with_site(['payment_webhook' => ['a' => 'b']]);
    check_same([], $config->paymentWebhook());
});
