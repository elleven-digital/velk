<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;

/**
 * `FormSubmission::exportQuery()` e `exportRows()` — a leitura da exportação
 * em blocos.
 *
 * O que o keyset garante sob concorrência (não duplicar linha quando chega
 * submissão durante o download) não se reproduz num teste de unidade. O que
 * se prende aqui é a forma que dá essa garantia — `id < ?`, nunca OFFSET —,
 * o recorte de leads reais herdado do `filterWhere()`, e o laço que junta os
 * blocos: nenhuma linha repetida ou perdida na fronteira, inclusive quando o
 * total é múltiplo exato do bloco.
 *
 * Os casos de `exportRows()` rodam contra SQLite em memória, montado pelo
 * boot compartilhado dos testes da exportação.
 */

require_once dirname(__DIR__) . '/fixtures/form_export_boot.php';

if (!function_exists('fexq_ids')) {
    /**
     * Os ids que `exportRows()` entregou, na ordem.
     *
     * Com teto: um laço cujo keyset não avança devolve o primeiro bloco para
     * sempre, e sem o teto isso estoura a memória e derruba a suíte inteira
     * com um stack trace — em vez de um teste que diz o que quebrou.
     *
     * @return list<int>
     */
    function fexq_ids(int $chunk, array $filters = []): array
    {
        $ids = [];
        foreach (FormSubmission::exportRows('contato', $filters, $chunk) as $s) {
            $ids[] = $s->id;
            if (count($ids) > 100) {
                throw new RuntimeException('exportRows não termina: o keyset não avança entre os blocos');
            }
        }
        return $ids;
    }
}

test('exportQuery: primeiro bloco — só leads reais, do mais novo, com limite', function () {
    [$sql, $args] = FormSubmission::exportQuery('contato', [], null, 500);
    check_same(
        'SELECT * FROM form_submissions WHERE form_id = ? AND blocked_by IS NULL AND pending_step IS NULL'
            . ' ORDER BY id DESC LIMIT 500',
        $sql
    );
    check_same(['contato'], $args);
});

test('exportQuery: blocos seguintes por keyset (id < último), nunca OFFSET', function () {
    // Com OFFSET, cada submissão que chega durante o download empurra a
    // janela e duplica uma linha no arquivo. `id < ?` não se move.
    [$sql, $args] = FormSubmission::exportQuery('contato', [], 1234, 500);
    check(str_ends_with($sql, ' AND id < ? ORDER BY id DESC LIMIT 500'), $sql);
    check(!str_contains($sql, 'OFFSET'), 'keyset, não OFFSET');
    check_same(['contato', 1234], $args);
});

test('exportQuery: o período entra pelo filterWhere, antes do keyset', function () {
    [$sql, $args] = FormSubmission::exportQuery(
        'contato',
        ['date_from' => '2026-01-01', 'date_to' => '2026-01-31'],
        99,
        10
    );
    check_same(
        'SELECT * FROM form_submissions WHERE form_id = ? AND blocked_by IS NULL AND pending_step IS NULL'
            . ' AND created_at >= ? AND created_at <= ? AND id < ? ORDER BY id DESC LIMIT 10',
        $sql
    );
    check_same(['contato', '2026-01-01 00:00:00', '2026-01-31 23:59:59', 99], $args);
});

test('exportRows: blocos se juntam sem repetir nem perder linha na fronteira', function () {
    fexb_boot([], [[1, null, null], [2, null, null], [3, null, null], [4, null, null], [5, null, null]]);
    // 2 → três blocos (2, 2, 1); 5 → múltiplo exato, e o laço ainda para;
    // 1 → uma linha por bloco; 50 → um bloco só.
    foreach ([2, 5, 1, 50] as $chunk) {
        check_same([5, 4, 3, 2, 1], fexq_ids($chunk), "bloco de {$chunk}");
    }
});

test('exportRows: só leads reais — spam e em andamento ficam de fora', function () {
    fexb_boot([], [[1, null, null], [2, 'honeypot', null], [3, null, 2], [4, 'manual', 2], [5, null, null]]);
    check_same([5, 1], fexq_ids(1));
});

test('exportRows: formulário sem leads não entrega nada', function () {
    fexb_boot();
    check_same([], fexq_ids(500));
});

test('exportRows: o período recorta de verdade, e nenhuma outra chave abre spam ou em andamento', function () {
    // Só date_from/date_to chegam ao filterWhere(): mesmo que um chamador
    // repasse a query string inteira, o recorte de leads reais continua o
    // default. Fevereiro tem três linhas, e só a 2 é lead real.
    fexb_boot([], [
        [1, null, null, '{}', null, '2026-01-10 09:00:00'],
        [2, null, null, '{}', null, '2026-02-10 09:00:00'],
        [3, 'honeypot', null, '{}', null, '2026-02-11 09:00:00'],
        [4, null, 2, '{}', null, '2026-02-12 09:00:00'],
        [5, null, null, '{}', null, '2026-03-10 09:00:00'],
    ]);
    check_same([2], fexq_ids(1, [
        'date_from' => '2026-02-01', 'date_to' => '2026-02-28',
        'spam' => '1', 'completeness' => 'all',
    ]));
});

test('exportQuery: bloco menor que 1 é recusado — com 0 o exportRows() não pararia', function () {
    // Na função pura, e não no gerador: sem a guarda, um exportRows() de
    // bloco 0 giraria para sempre sem produzir nada, e o teste travaria a
    // suíte em vez de falhar.
    foreach ([0, -1] as $limit) {
        try {
            FormSubmission::exportQuery('contato', [], null, $limit);
            check(false, "bloco de {$limit} deveria ser recusado");
        } catch (InvalidArgumentException $e) {
            check_same('exportQuery: o bloco precisa ter pelo menos 1 linha.', $e->getMessage());
        }
    }
});

test('exportRows: a primeira consulta roda na chamada, e o recorte vazio se percorre sem erro', function () {
    // Na chamada, e não no primeiro foreach: é o que deixa o painel e o CLI
    // descobrirem um banco fora do ar antes de mandar qualquer byte.
    $app = fexb_boot();
    $app->db->pdo()->exec('DROP TABLE form_submissions');
    $threw = false;
    try {
        FormSubmission::exportRows('contato', []);
    } catch (PDOException $e) {
        $threw = true;
    }
    check($threw, 'a exceção vem na chamada, sem precisar percorrer');

    // E um recorte sem linhas: o gerador que terminou sem produzir nada
    // lançaria "Cannot traverse an already closed generator" no foreach.
    fexb_boot([], [[1, null, null]]);
    check_same([], fexq_ids(500, ['date_from' => '2030-01-01']));
});
