<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;

/**
 * `FormSubmission::previewFrom()` — o rótulo do cartão fechado na lista de
 * preenchimentos.
 *
 * Até aqui o rótulo era "o primeiro valor de texto não-vazio", que funciona
 * por acidente de ordenação: os dados são gravados na ordem de declaração dos
 * campos, então o primeiro texto preenchido costuma ser o nome. Duas coisas
 * ruins vinham disso — quem monta o formulário não tinha como adivinhar a
 * regra, e reordenar campos por motivo de layout trocava o rótulo da lista
 * sem aviso nenhum.
 *
 * `forms.<id>.preview_field` torna a escolha explícita. O fallback continua
 * inteiro, porque um campo opcional em branco não pode zerar o rótulo.
 */

test('sem preview_field: primeiro texto não-vazio (comportamento histórico)', function () {
    check_same('Gustavo', FormSubmission::previewFrom([
        'name'  => 'Gustavo',
        'email' => 'g@exemplo.com',
    ]));

    // Explicitamente null e '' seguem o mesmo caminho.
    check_same('Gustavo', FormSubmission::previewFrom(['name' => 'Gustavo'], null));
    check_same('Gustavo', FormSubmission::previewFrom(['name' => 'Gustavo'], ''));
});

test('preview_field escolhe o campo, mesmo não sendo o primeiro', function () {
    $data = [
        'name'  => 'Gustavo',
        'email' => 'g@exemplo.com',
    ];

    check_same('g@exemplo.com', FormSubmission::previewFrom($data, 'email'));
    check_same('Gustavo', FormSubmission::previewFrom($data, 'name'));
});

test('campo nomeado vazio cai no fallback, não em branco', function () {
    // Campo opcional que ninguém preencheu. Um rótulo pior é melhor que
    // nenhum — cartão sem texto não ajuda a identificar o lead.
    check_same('Gustavo', FormSubmission::previewFrom([
        'name'    => 'Gustavo',
        'company' => '',
    ], 'company'));

    // Só espaços conta como vazio.
    check_same('Gustavo', FormSubmission::previewFrom([
        'name'    => 'Gustavo',
        'company' => '   ',
    ], 'company'));
});

test('campo nomeado ausente dos dados cai no fallback', function () {
    // Acontece de verdade: campo adicionado ao site.json depois, com
    // submissões antigas gravadas sem ele.
    check_same('Gustavo', FormSubmission::previewFrom(['name' => 'Gustavo'], 'company'));
});

test('campo de arquivo não vira rótulo', function () {
    // Arquivo guarda array de metadados, não string. Vale tanto quando é o
    // campo apontado quanto quando é só o primeiro do fallback.
    $file = ['_file' => true, 'path' => 'x.pdf', 'name' => 'x.pdf'];

    check_same('Gustavo', FormSubmission::previewFrom(['anexo' => $file, 'name' => 'Gustavo'], 'anexo'));
    check_same('Gustavo', FormSubmission::previewFrom(['anexo' => $file, 'name' => 'Gustavo']));
});

test('sem nada aproveitável devolve string vazia', function () {
    // '' e não '#id': o model não conhece o id, quem chama decide o fallback.
    check_same('', FormSubmission::previewFrom([]));
    check_same('', FormSubmission::previewFrom(['a' => '', 'b' => '   ']));
    check_same('', FormSubmission::previewFrom(['consent' => true, 'n' => 42]));
    check_same('', FormSubmission::previewFrom(['x' => null], 'x'));
});

test('corta em 60 caracteres e apara as pontas', function () {
    $longo = str_repeat('a', 100);
    check_same(60, mb_strlen(FormSubmission::previewFrom(['t' => $longo])));
    check_same(60, mb_strlen(FormSubmission::previewFrom(['t' => $longo], 't')));

    check_same('Gustavo', FormSubmission::previewFrom(['t' => '  Gustavo  ']));
});

test('corta por CARACTERE, não por byte', function () {
    // mb_substr, não substr: 60 acentuados cortados por byte devolveriam
    // metade do texto e possivelmente um caractere quebrado no fim.
    $acentuado = str_repeat('ã', 100);
    $out = FormSubmission::previewFrom(['t' => $acentuado]);

    check_same(60, mb_strlen($out));
    check_same($acentuado !== $out, true);
    check(mb_check_encoding($out, 'UTF-8'), 'o corte não pode quebrar o UTF-8');
});

test('nunca lança, seja qual for o formato dos dados', function () {
    $bizarros = [
        ['data' => ['a' => ['aninhado' => 'x']], 'campo' => null],
        ['data' => ['a' => 3.14, 'b' => false], 'campo' => 'a'],
        ['data' => ['a' => 'ok'], 'campo' => 'inexistente'],
        ['data' => [], 'campo' => 'qualquer'],
    ];

    foreach ($bizarros as $i => $caso) {
        try {
            $out = FormSubmission::previewFrom($caso['data'], $caso['campo']);
            check(is_string($out), "caso #{$i} deveria devolver string");
        } catch (\Throwable $e) {
            check(false, "caso #{$i} lançou: " . $e->getMessage());
        }
    }
});

// ─────────────── o subtítulo ───────────────
// `forms.<id>.preview_subtitle_field`: um segundo campo, na linha da data.

test('subtitleFrom: o campo nomeado, aparado e cortado como o rótulo', function () {
    check_same('ACME', FormSubmission::subtitleFrom(['name' => 'Gustavo', 'empresa' => '  ACME  '], 'empresa'));
    check_same(str_repeat('é', 60), FormSubmission::subtitleFrom(['empresa' => str_repeat('é', 70)], 'empresa'));
});

test('subtitleFrom: sem campo configurado, ou com ele vazio, não há subtítulo — nunca outro campo no lugar', function () {
    // Ao contrário do rótulo, sem fallback: o "primeiro texto" seria o
    // próprio título repetido embaixo dele.
    $data = ['name' => 'Gustavo', 'empresa' => ''];
    check_same('', FormSubmission::subtitleFrom($data, null));
    check_same('', FormSubmission::subtitleFrom($data, ''));
    check_same('', FormSubmission::subtitleFrom($data, 'empresa'));
    check_same('', FormSubmission::subtitleFrom($data, 'cargo'));
});

test('subtitleFrom: campo de arquivo não vira subtítulo', function () {
    $file = ['_file' => true, 'path' => 'x.pdf', 'name' => 'x.pdf'];
    check_same('', FormSubmission::subtitleFrom(['anexo' => $file, 'name' => 'Gustavo'], 'anexo'));
});
