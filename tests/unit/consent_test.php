<?php

declare(strict_types=1);

use Velk\Config;
use Velk\Consent;

/**
 * `Velk\Consent` e `Config::consentCategories()` — o núcleo do gate de
 * scripts por consentimento.
 *
 * A regra que mais importa aqui, e a mais fácil de quebrar sem perceber:
 * categoria AUSENTE do cookie não é "negada", é "não respondida". Quem
 * decide nesse caso é o `mode` do site.json. Confundir os dois faria um site
 * com `opt_out` parar de medir para todo visitante novo — sem erro, sem log,
 * só os números caindo.
 */

if (!function_exists('cs_cats')) {
    /** Uma de cada modo, que é o cenário real de qualquer site. */
    function cs_cats(): array
    {
        return [
            'necessarios' => ['label' => 'Necessários', 'mode' => 'required'],
            'analise'     => ['label' => 'Análise',     'mode' => 'opt_out'],
            'marketing'   => ['label' => 'Marketing',   'mode' => 'opt_in'],
        ];
    }
}

if (!function_exists('cs_config')) {
    function cs_config(array $site): Config
    {
        $dir = sys_get_temp_dir() . '/velk_consent_' . bin2hex(random_bytes(6));
        mkdir($dir . '/config', 0777, true);
        mkdir($dir . '/theme', 0777, true);
        file_put_contents($dir . '/config/env.php', "<?php\nreturn [];\n");
        file_put_contents($dir . '/theme/site.json', json_encode($site));
        $c = new Config($dir);
        cs_rrmdir($dir);
        return $c;
    }
}

if (!function_exists('cs_rrmdir')) {
    function cs_rrmdir(string $d): void
    {
        if (!is_dir($d)) return;
        foreach (scandir($d) ?: [] as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = "$d/$f";
            is_dir($p) ? cs_rrmdir($p) : unlink($p);
        }
        rmdir($d);
    }
}

// ─── sem resposta do visitante: cada modo decide sozinho ──────────────

test('sem cookie: required liga, opt_out liga, opt_in NÃO liga', function () {
    $cats = cs_cats();
    $vazio = Consent::parse(null);

    check_same(true,  Consent::isGranted('necessarios', $cats, $vazio));
    check_same(true,  Consent::isGranted('analise', $cats, $vazio));
    check_same(false, Consent::isGranted('marketing', $cats, $vazio));
});

test('ausente do cookie ≠ negado — cai no mode, não em false', function () {
    // O erro clássico: tratar "não respondeu" como recusa. Um site com
    // analise em opt_out pararia de medir todo visitante novo.
    $cats = cs_cats();
    $sóMarketing = Consent::parse(Consent::encode(['marketing' => true]));

    check_same(true, Consent::isGranted('analise', $cats, $sóMarketing));
    check(!array_key_exists('analise', $sóMarketing), 'analise não deveria estar no cookie');
});

// ─── escolhas explícitas ──────────────────────────────────────────────

test('escolha explícita vence o padrão do modo, nos dois sentidos', function () {
    $cats = cs_cats();

    $recusou = Consent::parse(Consent::encode(['analise' => false]));
    check_same(false, Consent::isGranted('analise', $cats, $recusou));

    $aceitou = Consent::parse(Consent::encode(['marketing' => true]));
    check_same(true, Consent::isGranted('marketing', $cats, $aceitou));
});

test('required ignora o cookie — não dá pra revogar', function () {
    $cats = cs_cats();
    $tentou = Consent::parse(Consent::encode(['necessarios' => false]));

    check_same(true, Consent::isGranted('necessarios', $cats, $tentou));
});

// ─── robustez do cookie ───────────────────────────────────────────────

test('cookie inválido é tratado como "não respondeu", nunca como aceite', function () {
    $cats = cs_cats();

    foreach ([null, '', '   ', 'nao-json', '[]', '{}', '{"v":99,"c":{"marketing":true}}',
              '{"v":1}', '{"v":1,"c":"x"}', '{"c":{"marketing":true}}'] as $i => $raw) {
        $parsed = Consent::parse($raw);
        check_same([], $parsed);
        // O ponto: nenhum cookie quebrado pode LIGAR opt_in.
        check_same(false, Consent::isGranted('marketing', $cats, $parsed));
    }
});

test('valores não-booleanos no cookie são descartados', function () {
    // Só bool de verdade. "1"/"true"/1 indicariam adulteração ou formato
    // antigo — e o lado seguro é ignorar, não converter.
    $parsed = Consent::parse('{"v":1,"c":{"a":"true","b":1,"c":null,"d":true}}');
    check_same(['d' => true], $parsed);
});

test('encode/parse fecham o ciclo', function () {
    $orig = ['analise' => false, 'marketing' => true];
    check_same($orig, Consent::parse(Consent::encode($orig)));

    // Mapa vazio precisa virar objeto, não array — senão o parse do outro
    // lado recebe [] e o formato deixa de ser estável.
    check(str_contains(Consent::encode([]), '"c":{}'), 'vazio deveria serializar como objeto');
});

// ─── categoria que sumiu do site.json ─────────────────────────────────

test('categoria fora do site.json é negada, mesmo aceita no cookie', function () {
    // Cookie antigo de quando o tema declarava "ads". Os scripts dela também
    // não existem mais, então negar é o que combina com a página real.
    $cats = cs_cats();
    $antigo = Consent::parse(Consent::encode(['ads' => true]));

    check_same(false, Consent::isGranted('ads', $cats, $antigo));
    check_same(false, Consent::isGranted('inexistente', $cats, []));
});

// ─── estado agregado, o que o tema consome ────────────────────────────

test('resolveAll devolve todas as declaradas, e só elas', function () {
    $cats = cs_cats();
    $escolhas = Consent::parse(Consent::encode(['marketing' => true, 'ads' => true]));

    check_same(
        ['necessarios' => true, 'analise' => true, 'marketing' => true],
        Consent::resolveAll($cats, $escolhas)
    );
});

test('hasAnswered separa "aceitou tudo" de "nunca viu o banner"', function () {
    // Os dois podem resolver pro mesmo estado; só o segundo mostra o popup.
    check_same(false, Consent::hasAnswered(Consent::parse(null)));
    check_same(true,  Consent::hasAnswered(Consent::parse(Consent::encode(['analise' => true]))));
    check_same(true,  Consent::hasAnswered(Consent::parse(Consent::encode(['analise' => false]))));
});

// ─── renderScripts: o gate propriamente dito ──────────────────────────

if (!function_exists('cs_rows')) {
    function cs_rows(): array
    {
        return [
            ['label' => 'Monitor', 'category' => 'necessarios', 'location' => 'head',      'code' => '<script>A</script>'],
            ['label' => 'GA',      'category' => 'analise',     'location' => 'head',      'code' => '<script>B</script>'],
            ['label' => 'Pixel',   'category' => 'marketing',   'location' => 'head',      'code' => '<script>C</script>'],
            ['label' => 'Chat',    'category' => 'marketing',   'location' => 'body_end',  'code' => '<script>D</script>'],
        ];
    }
}

if (!function_exists('cs_tag')) {
    /** Um <script> como a página o recebe: com a senha (nonce) dela. */
    function cs_tag(string $body): string
    {
        return '<script nonce="' . \Velk\PublicCsp::nonce() . '">' . $body . '</script>';
    }
}

test('sem resposta: concedidos saem crus, negados saem inertes', function () {
    $html = Consent::renderScripts(cs_rows(), 'head', cs_cats(), []);

    check(str_contains($html, cs_tag('A')), 'required deveria sair cru');
    check(str_contains($html, cs_tag('B')), 'opt_out deveria sair cru');
    check(!str_contains($html, '>C</script>'), 'opt_in NÃO pode sair executável');
    check(str_contains($html, 'type="text/plain" data-velk-consent="marketing"'), 'opt_in deveria sair inerte');
});

test('filtra por posição — body_end não vaza pro head', function () {
    $head = Consent::renderScripts(cs_rows(), 'head', cs_cats(), []);
    check(!str_contains($head, base64_encode(cs_tag('D'))), 'o chat é do body_end');

    $end = Consent::renderScripts(cs_rows(), 'body_end', cs_cats(), []);
    check(str_contains($end, base64_encode(cs_tag('D'))), 'o chat deveria estar no body_end');
    check(!str_contains($end, '>A</script>'), 'o monitor é do head');
});

test('aceito depois: o mesmo script passa a sair cru', function () {
    $aceitou = Consent::parse(Consent::encode(['marketing' => true]));
    $html = Consent::renderScripts(cs_rows(), 'head', cs_cats(), $aceitou);

    check(str_contains($html, cs_tag('C')), 'aceito deveria sair cru');
    check(!str_contains($html, 'data-velk-consent'), 'não deveria sobrar placeholder');
});

test('recusa explícita torna um opt_out inerte', function () {
    $recusou = Consent::parse(Consent::encode(['analise' => false]));
    $html = Consent::renderScripts(cs_rows(), 'head', cs_cats(), $recusou);

    check(!str_contains($html, '>B</script>'), 'recusado não pode sair executável');
    check(str_contains($html, 'data-velk-consent="analise"'), 'deveria sair inerte');
});

test('o conteúdo inerte vai em base64 — </script> não fecha o wrapper', function () {
    // O caso real: qualquer snippet que escreve outra tag contém </script>.
    // Sem base64 o wrapper fecharia no meio e o resto viraria markup solto.
    $rows = [['category' => 'marketing', 'location' => 'head',
              'code' => '<script>document.write("<script>x<\/script>")</script>']];
    $html = Consent::renderScripts($rows, 'head', cs_cats(), []);

    // Exatamente um par de tags script no HTML inerte.
    check_same(1, preg_match_all('#<script#', $html));
    check_same(1, preg_match_all('#</script>#', $html));
    // E o conteúdo é recuperável.
    preg_match('#>([A-Za-z0-9+/=]+)</script>#', $html, $m);
    check_same(cs_tag('document.write("<script>x<\/script>")'), base64_decode($m[1] ?? ''));
});

test('acento sobrevive ao base64', function () {
    $rows = [['category' => 'marketing', 'location' => 'head', 'code' => '<script>var x="ação";</script>']];
    $html = Consent::renderScripts($rows, 'head', cs_cats(), []);
    preg_match('#>([A-Za-z0-9+/=]+)</script>#', $html, $m);
    check_same(cs_tag('var x="ação";'), base64_decode($m[1] ?? ''));
});

test('categoria VAZIA é necessário — sai cru, sem gate', function () {
    // Vazio é o padrão da tela: ninguém marcou "exige consentimento". Tem
    // que carregar sempre. Se algum dia isto virar "não emite", todo script
    // necessário some do site em silêncio.
    $rows = [['category' => '', 'location' => 'head', 'code' => '<script>SEM</script>']];
    $html = Consent::renderScripts($rows, 'head', cs_cats(), []);

    check(str_contains($html, cs_tag('SEM')), 'necessário deveria sair cru');
    check(!str_contains($html, 'data-velk-consent'), 'não deveria virar inerte');
});

test('categoria ÓRFÃ não emite nada — nem cru nem inerte', function () {
    // Diferente de vazia: o tema declarava e deixou de declarar (renome no
    // site.json). Aí não há como decidir, e "não sei" tem que virar "não
    // carrega" — senão erro de configuração vira rastreamento sem consentimento.
    $rows = [['category' => 'sumiu', 'location' => 'head', 'code' => '<script>ORFA</script>']];
    check_same('', Consent::renderScripts($rows, 'head', cs_cats(), []));
});

test('linha malformada é pulada sem lançar', function () {
    $rows = [
        'string em vez de array',
        ['category' => 'necessarios', 'location' => 'head'],                 // sem code
        ['category' => 'necessarios', 'location' => 'head', 'code' => ''],    // vazio
        ['category' => 'necessarios', 'location' => 'head', 'code' => '   '], // só espaço
        ['category' => 'necessarios', 'code' => '<script>X</script>'],        // sem location
        ['category' => 'necessarios', 'location' => 'head', 'code' => '<script>OK</script>'],
    ];
    $html = Consent::renderScripts($rows, 'head', cs_cats(), []);
    check_same(1, substr_count($html, cs_tag('OK')));
});

test('o nome da categoria é escapado no atributo', function () {
    // O rótulo vem do site.json, mas a CHAVE também — e ela vira atributo.
    $cats = ['x"onload="alert(1)' => ['label' => 'X', 'mode' => 'opt_in']];
    $rows = [['category' => 'x"onload="alert(1)', 'location' => 'head', 'code' => '<script>X</script>']];
    $html = Consent::renderScripts($rows, 'head', $cats, []);

    check(!str_contains($html, 'onload="alert(1)"'), 'a aspa não pode escapar do atributo');
    check(str_contains($html, '&quot;'), 'deveria estar escapado');
});

// ─── leitura do site.json ─────────────────────────────────────────────

test('consentCategories lê o bloco e normaliza', function () {
    $c = cs_config(['consent' => ['categories' => [
        'nec' => ['label' => '  Necessários  ', 'mode' => 'required'],
        'mkt' => ['mode' => 'opt_in'],                 // sem label → usa a chave
    ]]]);

    check_same([
        'nec' => ['label' => 'Necessários', 'mode' => 'required'],
        'mkt' => ['label' => 'mkt', 'mode' => 'opt_in'],
    ], $c->consentCategories());
});

test('entradas inválidas são descartadas, não viram categoria quebrada', function () {
    $c = cs_config(['consent' => ['categories' => [
        'ok'   => ['label' => 'OK', 'mode' => 'opt_in'],
        'ruim' => ['label' => 'Ruim', 'mode' => 'talvez'],
        'nao'  => 'string em vez de objeto',
        ''     => ['label' => 'Vazia', 'mode' => 'required'],
    ]]]);

    check_same(['ok'], array_keys($c->consentCategories()));
});

test('quem liga o consentimento é `enabled`, não a presença de categorias', function () {
    // O tema afirma que TEM banner. Declarar categoria não basta: um tema
    // podia declarar categorias e não ter interface nenhuma para aceitar,
    // e aí um script opt_in ficava inerte para sempre, sem erro.
    $comCategorias = ['consent' => ['categories' => [
        'nec' => ['label' => 'N', 'mode' => 'required'],
    ]]];
    check_same(false, cs_config($comCategorias)->isConsentEnabled(), 'categoria sozinha não liga');
    check_same([], cs_config($comCategorias)->effectiveConsentCategories());

    $ligado = ['consent' => ['enabled' => true, 'categories' => [
        'nec' => ['label' => 'N', 'mode' => 'required'],
    ]]];
    check_same(true, cs_config($ligado)->isConsentEnabled());
    check_same(['nec'], array_keys(cs_config($ligado)->effectiveConsentCategories()));
});

test('ligado sem categorias declaradas cai nas implícitas', function () {
    $c = cs_config(['consent' => ['enabled' => true]]);
    check_same(true, $c->isConsentEnabled());
    check_same([Consent::IMPLICIT_ON, Consent::IMPLICIT_OFF],
        array_keys($c->effectiveConsentCategories()));
});

test('desligado: nenhuma categoria efetiva, nem as implícitas', function () {
    // É o que faz a área de consentimento sumir do painel e todo script ser
    // tratado como necessário.
    foreach ([[], ['consent' => 'x'], ['consent' => ['enabled' => false]],
              ['consent' => ['categories' => []]]] as $i => $site) {
        $c = cs_config($site);
        check_same(false, $c->isConsentEnabled(), "caso #{$i}");
        check_same([], $c->effectiveConsentCategories(), "caso #{$i}");
    }
});

// ─── consent.enabled: o tema afirma ter mecanismo ─────────────────────

test('desligado, todo script é tratado como necessário na emissão', function () {
    // O helper zera a categoria antes de chamar o gate. Sem isso, um script
    // com categoria RESIDUAL (de quando o tema tinha banner) cairia no caso
    // "categoria órfã" e sumiria da página — em vez de carregar, que é o que
    // "sem mecanismo de consentimento" significa.
    $rows = [
        ['category' => '',              'location' => 'head', 'code' => '<script>A</script>'],
        ['category' => '_optional_off', 'location' => 'head', 'code' => '<script>B</script>'],
    ];
    foreach ($rows as $i => $row) {
        $rows[$i]['category'] = '';   // o que velk_tracking_emit() faz
    }
    $html = Consent::renderScripts($rows, 'head', [], []);

    check(str_contains($html, cs_tag('A')), 'necessário deveria sair');
    check(str_contains($html, cs_tag('B')), 'o que exigia consentimento também, agora');
    check(!str_contains($html, 'data-velk-consent'), 'nada inerte quando não há mecanismo');
});

test('sem zerar a categoria, um script residual sumiria', function () {
    // A prova de que o passo acima é necessário, e não zelo: com categorias
    // efetivas vazias, uma categoria preenchida é "órfã" e não emite nada.
    $rows = [['category' => '_optional_off', 'location' => 'head', 'code' => '<script>B</script>']];
    check_same('', Consent::renderScripts($rows, 'head', [], []));
});
