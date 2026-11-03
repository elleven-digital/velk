<?php

declare(strict_types=1);

use Velk\FieldRenderer;

/**
 * `FieldRenderer::rowTitle()` — o rótulo da linha de um repetidor quando o
 * campo declara `title_field`.
 *
 * A regra central, e a que mais fácil se quebra numa refatoração: devolver
 * STRING VAZIA quando não há texto. O "ITEM 01" é um contador de CSS que só
 * desenha com o span vazio (`:empty`), e por ser contador ele se renumera
 * sozinho ao arrastar e ao excluir linhas. Se este método algum dia devolver
 * "Item 3" em vez de '', a numeração passa a mentir depois de qualquer
 * reordenação — sem nenhum erro visível.
 */

if (!function_exists('rt_fields')) {
    /** Defs de linha usadas na maioria dos casos. */
    function rt_fields(): array
    {
        return [
            ['name' => 'titulo', 'type' => 'text', 'label' => 'Título'],
            ['name' => 'imagem', 'type' => 'image', 'label' => 'Imagem'],
            ['name' => 'destino', 'type' => 'link', 'label' => 'Destino'],
            ['name' => 'pais', 'type' => 'select', 'label' => 'País',
             'options' => ['br' => 'Brasil', 'pt' => 'Portugal']],
        ];
    }
}

test('sem title_field devolve vazio (o contador do CSS assume)', function () {
    check_same('', FieldRenderer::rowTitle(['titulo' => 'Sobre nós'], rt_fields(), null));
    check_same('', FieldRenderer::rowTitle(['titulo' => 'Sobre nós'], rt_fields(), ''));
});

test('campo de texto vira o rótulo', function () {
    check_same('Sobre nós', FieldRenderer::rowTitle(['titulo' => 'Sobre nós'], rt_fields(), 'titulo'));
});

test('campo vazio devolve vazio, não um rótulo em branco', function () {
    // Precisa ser '' exato: é o que devolve o contador. Um espaço, por
    // exemplo, faria o span deixar de ser :empty e a linha ficaria sem nada.
    check_same('', FieldRenderer::rowTitle(['titulo' => ''], rt_fields(), 'titulo'));
    check_same('', FieldRenderer::rowTitle(['titulo' => '   '], rt_fields(), 'titulo'));
    check_same('', FieldRenderer::rowTitle([], rt_fields(), 'titulo'));
});

test('link: usa o título; sem título, cai na URL', function () {
    $comTitulo = ['destino' => ['url' => 'https://x.com', 'title' => 'Fale conosco']];
    check_same('Fale conosco', FieldRenderer::rowTitle($comTitulo, rt_fields(), 'destino'));

    $semTitulo = ['destino' => ['url' => 'https://exemplo.com/contato', 'title' => '']];
    check_same('https://exemplo.com/contato', FieldRenderer::rowTitle($semTitulo, rt_fields(), 'destino'));

    // Título só com espaços conta como ausente.
    $espacos = ['destino' => ['url' => 'https://exemplo.com', 'title' => '  ']];
    check_same('https://exemplo.com', FieldRenderer::rowTitle($espacos, rt_fields(), 'destino'));

    // Link totalmente vazio devolve o contador.
    check_same('', FieldRenderer::rowTitle(['destino' => ['url' => '', 'title' => '']], rt_fields(), 'destino'));
    check_same('', FieldRenderer::rowTitle(['destino' => []], rt_fields(), 'destino'));
});

test('select: usa o RÓTULO da opção, não o valor', function () {
    // "br" é chave de armazenamento; ninguém escolheu isso como texto de tela.
    check_same('Brasil', FieldRenderer::rowTitle(['pais' => 'br'], rt_fields(), 'pais'));

    // Valor fora das opções cai nele mesmo — melhor que sumir.
    check_same('xx', FieldRenderer::rowTitle(['pais' => 'xx'], rt_fields(), 'pais'));
    check_same('', FieldRenderer::rowTitle(['pais' => ''], rt_fields(), 'pais'));
});

test('tipo incompatível devolve vazio, mesmo com valor', function () {
    // image guarda id; boolean não distingue linha nenhuma. O schema:validate
    // acusa isso na configuração — em runtime só volta pro contador.
    check_same('', FieldRenderer::rowTitle(['imagem' => 42], rt_fields(), 'imagem'));

    $comBool = [['name' => 'ativo', 'type' => 'boolean']];
    check_same('', FieldRenderer::rowTitle(['ativo' => true], $comBool, 'ativo'));
});

test('campo inexistente devolve vazio', function () {
    check_same('', FieldRenderer::rowTitle(['titulo' => 'Sobre'], rt_fields(), 'naoexiste'));
});

test('acha o campo dentro de group e de tabs', function () {
    // group/tabs são transparentes no armazenamento — o filho grava no mesmo
    // array plano da linha. Se a busca parasse no primeiro nível, title_field
    // falharia com o valor ali, disponível.
    $comGrupo = [
        ['type' => 'group', 'label' => 'Conteúdo', 'fields' => [
            ['name' => 'titulo', 'type' => 'text'],
        ]],
    ];
    check_same('Agrupado', FieldRenderer::rowTitle(['titulo' => 'Agrupado'], $comGrupo, 'titulo'));

    $comTabs = [
        ['type' => 'tabs', 'tabs' => [
            ['label' => 'Aba 1', 'fields' => [['name' => 'titulo', 'type' => 'text']]],
        ]],
    ];
    check_same('Em aba', FieldRenderer::rowTitle(['titulo' => 'Em aba'], $comTabs, 'titulo'));
});

test('corta em 80 caracteres, por caractere', function () {
    $longo = str_repeat('a', 200);
    check_same(80, mb_strlen(FieldRenderer::rowTitle(['titulo' => $longo], rt_fields(), 'titulo')));

    $acentuado = str_repeat('ç', 200);
    $out = FieldRenderer::rowTitle(['titulo' => $acentuado], rt_fields(), 'titulo');
    check_same(80, mb_strlen($out));
    check(mb_check_encoding($out, 'UTF-8'), 'o corte não pode quebrar o UTF-8');
});

test('nunca lança, seja qual for o formato', function () {
    $casos = [
        [['titulo' => ['inesperado' => 1]], rt_fields(), 'titulo'],
        [['destino' => 'string em vez de array'], rt_fields(), 'destino'],
        [[], [], 'qualquer'],
        [['x' => 1], [['name' => 'x']], 'x'],            // type ausente = text
        [['titulo' => 3.5], rt_fields(), 'titulo'],
    ];

    foreach ($casos as $i => [$values, $fields, $key]) {
        try {
            check(is_string(FieldRenderer::rowTitle($values, $fields, $key)), "caso #{$i} deveria devolver string");
        } catch (\Throwable $e) {
            check(false, "caso #{$i} lançou: " . $e->getMessage());
        }
    }
});

test('schema:validate reprova title_field inválido', function () {
    // Nome inexistente.
    $ruim = [['name' => 'blocos', 'type' => 'repeater', 'title_field' => 'naoexiste',
              'fields' => [['name' => 'titulo', 'type' => 'text']]]];
    $issues = FieldRenderer::validateFields($ruim, 'teste');
    check(count($issues) === 1, 'esperava 1 problema, veio ' . count($issues));
    check(str_contains($issues[0] ?? '', 'naoexiste'), 'a mensagem deveria citar o nome errado');

    // Tipo incompatível — a mensagem precisa dizer quais servem.
    $tipoRuim = [['name' => 'blocos', 'type' => 'repeater', 'title_field' => 'foto',
                  'fields' => [['name' => 'foto', 'type' => 'image']]]];
    $issues = FieldRenderer::validateFields($tipoRuim, 'teste');
    check(count($issues) === 1, 'esperava 1 problema, veio ' . count($issues));
    check(str_contains($issues[0] ?? '', 'image'), 'deveria citar o tipo recusado');
    check(str_contains($issues[0] ?? '', 'text'), 'deveria listar os tipos aceitos');

    // Vazio e não-string.
    foreach (['', 42, null] as $mau) {
        $def = [['name' => 'blocos', 'type' => 'repeater', 'title_field' => $mau,
                 'fields' => [['name' => 'titulo', 'type' => 'text']]]];
        check(FieldRenderer::validateFields($def, 'teste') !== [], 'title_field=' . var_export($mau, true) . ' deveria reprovar');
    }

    // Válido não gera ruído, inclusive apontando pra dentro de um group.
    $bom = [['name' => 'blocos', 'type' => 'repeater', 'title_field' => 'titulo',
             'fields' => [['type' => 'group', 'fields' => [['name' => 'titulo', 'type' => 'text']]]]]];
    check_same([], FieldRenderer::validateFields($bom, 'teste'));
});
