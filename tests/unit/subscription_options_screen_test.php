<?php

declare(strict_types=1);

use Velk\Admin\SubscriptionOptions;
use Velk\App;
use Velk\Config;

/**
 * A tela "Planos & Assinaturas" (/admin/options/subscriptions), que o engine
 * gera dos planos de `site.json → members.plans`.
 *
 * Até a v3.9.0 ela vinha montada no Config com o texto cravado em português
 * — fora do catálogo do painel, então não seguia o idioma de quem usa —, dizia
 * "valor em reais" com a moeda em USD e mostrava abas de idioma num grupo que
 * ninguém lê traduzido: o SubscriptionService só lê os valores do idioma
 * padrão.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('sos_boot')) {
    function sos_boot(string $locale, array $plans): App
    {
        $root = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $root . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, ['members' => ['enabled' => true, 'plans' => $plans]]);
        $app = new App($config);
        $app->adminI18n = new \Velk\Admin\I18n($root . '/core/Admin/lang', $locale, 'en');
        return $app;
    }

    function sos_plans(): array
    {
        return [
            'basico' => ['name' => 'Básico', 'intervals' => ['month', 'year']],
            'pro' => ['name' => 'Pro & Max', 'intervals' => ['month']],
        ];
    }

    /** @return array<string, array<string, mixed>> nome do campo => definição */
    function sos_fields(array $group): array
    {
        $out = [];
        foreach ($group['fields'] as $f) {
            $out[$f['name']] = $f;
        }
        return $out;
    }
}

test('assinaturas: os nomes dos campos não mudam (são as chaves que o SubscriptionService lê)', function () {
    sos_boot('en', sos_plans());
    check_same(
        ['basico_active', 'basico_price_month', 'basico_price_year', 'pro_active', 'pro_price_month', 'currency'],
        array_keys(sos_fields(SubscriptionOptions::group(sos_plans())))
    );
});

test('assinaturas: só o intervalo declarado ganha campo de preço', function () {
    sos_boot('en', sos_plans());
    $fields = sos_fields(SubscriptionOptions::group(sos_plans()));
    check(!isset($fields['pro_price_year']), 'pro só declara month');
    check_same('boolean', $fields['pro_active']['type']);
});

test('assinaturas: rótulos e ajudas seguem o idioma do painel', function () {
    sos_boot('en', sos_plans());
    $en = SubscriptionOptions::group(sos_plans());
    $enFields = sos_fields($en);
    check_same('Plans & Subscriptions', $en['label']);
    check_same('Básico — Active', $enFields['basico_active']['label']);
    check_same('Básico — Monthly price', $enFields['basico_price_month']['label']);
    check_same('Currency', $enFields['currency']['label']);

    sos_boot('pt', sos_plans());
    $pt = SubscriptionOptions::group(sos_plans());
    $ptFields = sos_fields($pt);
    check_same('Planos & Assinaturas', $pt['label']);
    check_same('Básico — Ativo', $ptFields['basico_active']['label']);
    check_same('Básico — Preço anual', $ptFields['basico_price_year']['label']);
    check_same('Moeda', $ptFields['currency']['label']);
});

test('assinaturas: o nome do plano entra cru no rótulo (quem escapa é o FieldRenderer)', function () {
    sos_boot('en', sos_plans());
    $fields = sos_fields(SubscriptionOptions::group(sos_plans()));
    check_same('Pro & Max — Active', $fields['pro_active']['label']);
});

test('assinaturas: a ajuda do preço não fala em reais (a moeda é configurável)', function () {
    foreach (['en', 'pt', 'es'] as $locale) {
        sos_boot($locale, sos_plans());
        $fields = sos_fields(SubscriptionOptions::group(sos_plans()));
        foreach (['basico_price_month', 'basico_price_year', 'pro_price_month'] as $name) {
            check(!preg_match('/reais|\bR\$/iu', (string) $fields[$name]['help']), "{$locale} {$name}: {$fields[$name]['help']}");
        }
    }
});

test('assinaturas: o grupo é marcado como não traduzível (sem abas de idioma)', function () {
    sos_boot('en', sos_plans());
    check_same(false, SubscriptionOptions::group(sos_plans())['translatable']);
});

test('assinaturas: Config::builtinOptions() entrega o grupo gerado; sem planos, nada', function () {
    $app = sos_boot('pt', sos_plans());
    $builtin = $app->config->builtinOptions();
    check_same('Planos & Assinaturas', $builtin['subscriptions']['label']);
    check_same(SubscriptionOptions::group(sos_plans()), $builtin['subscriptions']);

    $vazio = sos_boot('pt', []);
    check_same([], $vazio->config->builtinOptions());
});
