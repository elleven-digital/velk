<?php

declare(strict_types=1);

/**
 * Revisão de pagamentos 2026-09: schema:validate conhece a configuração de
 * pagamento — planos, tipos vendáveis, moeda — e acusa meia-configuração.
 *
 * Nada disto era validado. É o único recurso do engine em que um site.json
 * errado só aparece com dinheiro de cliente do outro lado.
 *
 * Mesma técnica do manual_order_schema_test: patch + restore do
 * theme/site.json real, porque bin/velk fixa $rootPath e boot() exige banco.
 * Env extra vai como prefixo de shell — Env::load() NÃO sobrescreve chave
 * já presente, então o prefixo vence o .env.
 */

if (!function_exists('psv_validate')) {
    /**
     * @param array<string,mixed> $patch   mesclado por chave de topo (members/store/item_types.<primeiro>)
     * @param array<string,string> $env    variáveis para o subprocesso
     */
    function psv_validate(array $patch = [], array $env = []): string
    {
        $raiz = dirname(__DIR__, 2);
        $origem = $raiz . '/theme/site.json';
        if (!is_file($origem)) {
            throw new RuntimeException('psv_validate: theme/site.json ausente — este teste precisa de um theme/ real');
        }
        $backup = $origem . '.psv-backup';
        if (is_file($backup)) { rename($backup, $origem); }

        $site = json_decode((string) file_get_contents($origem), true);
        foreach ($patch as $k => $v) {
            if ($k === 'item_types.first') {
                $first = array_key_first((array) ($site['item_types'] ?? []));
                if ($first !== null) $site['item_types'][$first] = array_merge($site['item_types'][$first], $v);
            } else {
                $site[$k] = array_merge((array) ($site[$k] ?? []), $v);
            }
        }

        if (!copy($origem, $backup)) throw new RuntimeException('psv_validate: backup falhou');
        try {
            file_put_contents($origem, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $prefix = '';
            foreach ($env as $k => $v) $prefix .= $k . '=' . escapeshellarg($v) . ' ';
            $out = shell_exec($prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/bin/velk') . ' schema:validate 2>&1');
        } finally {
            if (!rename($backup, $origem)) throw new RuntimeException('psv_validate: restore falhou');
        }
        return is_string($out) ? $out : '';
    }
}

$planoOk = ['members' => ['enabled' => true, 'plans' => ['pro' => ['name' => 'Pro', 'intervals' => ['month', 'year'], 'features' => ['a', 'b']]]]];

test('validate: plano bem formado passa', function () use ($planoOk) {
    $out = psv_validate($planoOk);
    check(str_contains($out, 'site.json is valid'), "deveria validar:\n{$out}");
});

test('validate: plano sem name é acusado', function () {
    $out = psv_validate(['members' => ['plans' => ['pro' => ['intervals' => ['month']]]]]);
    check(str_contains($out, 'Plan "pro" is missing a non-empty "name"'), $out);
});

test('validate: intervalo que não é month/year é acusado', function () {
    $out = psv_validate(['members' => ['plans' => ['pro' => ['name' => 'Pro', 'intervals' => ['weekly']]]]]);
    check(str_contains($out, 'interval "weekly" is not "month" or "year"'), $out);
});

test('validate: features que não é lista de strings é acusado', function () {
    $out = psv_validate(['members' => ['plans' => ['pro' => ['name' => 'Pro', 'features' => 'tudo']]]]);
    check(str_contains($out, '"features" must be a list of strings'), $out);
});

test('validate: sellable como string é acusado', function () {
    $out = psv_validate(['item_types.first' => ['sellable' => 'true']]);
    check(str_contains($out, '"sellable" must be true or false'), $out);
});

test('validate: moeda fora do padrão ISO é acusada (antes caía em BRL em silêncio)', function () {
    $out = psv_validate(['store' => ['currency' => 'usd']]);
    check(str_contains($out, '"store.currency" must be a 3-letter uppercase ISO code'), $out);
});

test('validate: meia-configuração — chave sem webhook secret, com planos declarados — é acusada', function () use ($planoOk) {
    $out = psv_validate($planoOk, ['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_WEBHOOK_SECRET' => '']);
    check(str_contains($out, 'STRIPE_WEBHOOK_SECRET is empty'), "deveria acusar o secret faltando:\n{$out}");
    check(str_contains($out, '/member/stripe-webhook'), 'a mensagem precisa dizer QUAL endpoint criar');
});

test('validate: com os dois secrets, sem aviso', function () use ($planoOk) {
    $out = psv_validate($planoOk, ['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_WEBHOOK_SECRET' => 'whsec_x']);
    check(!str_contains($out, 'STRIPE_WEBHOOK_SECRET is empty'), $out);
});

test('validate: sem Stripe configurado, planos declarados não geram aviso de webhook', function () use ($planoOk) {
    // Site que declara planos mas ainda não ligou o Stripe está só se preparando.
    $out = psv_validate($planoOk, ['STRIPE_SECRET_KEY' => '']);
    check(!str_contains($out, 'STRIPE_WEBHOOK_SECRET'), $out);
});

test('validate: tipo vendável com chave e sem secret da LOJA é acusado com o endpoint certo', function () {
    $out = psv_validate(['item_types.first' => ['sellable' => true]], ['STRIPE_SECRET_KEY' => 'sk_test_x', 'STRIPE_STORE_WEBHOOK_SECRET' => '']);
    check(str_contains($out, 'STRIPE_STORE_WEBHOOK_SECRET is empty'), $out);
    check(str_contains($out, '/store/stripe-webhook'), 'endpoint da loja, não o de assinatura');
});

// ─── Cardinalidade (spec 2026-09-07) ───────────────────────────────────

test('validate: members.multiple_plans que não é booleano é acusado; true passa', function () use ($planoOk) {
    $out = psv_validate(['members' => ['multiple_plans' => 'yes']]);
    check(str_contains($out, '"members.multiple_plans" must be true or false'), $out);
    $ok = $planoOk;
    $ok['members']['multiple_plans'] = true;
    check(str_contains(psv_validate($ok), 'site.json is valid'), 'true com planos válidos deveria validar');
});

test('validate: repeat_purchase que não é booleano é acusado', function () {
    $out = psv_validate(['item_types.first' => ['sellable' => true, 'repeat_purchase' => 'no']]);
    check(str_contains($out, '"repeat_purchase" must be true or false'), $out);
});

test('validate: repeat_purchase num tipo sem sellable: true é acusado — não tem efeito ali', function () {
    $out = psv_validate(['item_types.first' => ['sellable' => false, 'repeat_purchase' => false]]);
    check(str_contains($out, '"repeat_purchase" has no effect without "sellable": true'), $out);
});

test('validate: sellable + repeat_purchase false é a forma boa e passa', function () {
    $out = psv_validate(['item_types.first' => ['sellable' => true, 'repeat_purchase' => false]]);
    check(str_contains($out, 'site.json is valid'), "deveria validar:\n{$out}");
});
