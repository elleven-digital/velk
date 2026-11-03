<?php

declare(strict_types=1);

use Velk\Database;
use Velk\Models\ManualOrder;

/**
 * v3.7.5 — o miolo da ordem manual.
 *
 * As três operações que mantêm a sequência viva, isoladas num helper porque
 * items e termos precisam das mesmas e só mudam tabela e coluna de escopo.
 *
 * A permutação é a parte que merece mais atenção. Ela NÃO renumera de 1 a N:
 * ela redistribui, entre as linhas recebidas, os valores de `sort_order` que
 * essas mesmas linhas já ocupavam. É isso que faz paginação e filtro
 * funcionarem sem regra especial — reordenar a página 2 mexe só nas posições
 * que já eram da página 2, e reordenar um resultado de busca mexe só nas
 * posições daquelas linhas. Renumerar de 1 a N colidiria com as outras
 * páginas e embaralharia o resto da lista em silêncio.
 */

if (!function_exists('mo_db')) {
    /**
     * Banco em memória com uma tabela `items` mínima — só o que a ordem
     * manual toca. Devolve o Database do Velk apontando para ele.
     *
     * @param list<array{int,string,string,int}> $linhas  [id, type, title, sort_order]
     */
    function mo_db(array $linhas): Database
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec(
            'CREATE TABLE items (
                id INTEGER PRIMARY KEY, type TEXT, title TEXT,
                sort_order INTEGER NOT NULL DEFAULT 0,
                published_at TEXT, created_at TEXT
            )'
        );
        $stmt = $pdo->prepare(
            'INSERT INTO items (id, type, title, sort_order, published_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        foreach ($linhas as $l) {
            // published_at decrescente por id, para que a ordem-semente
            // (published_at DESC) seja determinística e diferente de id ASC.
            $stmt->execute([
                $l[0], $l[1], $l[2], $l[3],
                sprintf('2026-01-%02d 00:00:00', 30 - $l[0]),
                sprintf('2026-01-%02d 00:00:00', 30 - $l[0]),
            ]);
        }

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);

        return $db;
    }
}

if (!function_exists('mo_ordem')) {
    /** @return array<int,int> id => sort_order */
    function mo_ordem(Database $db, string $tipo): array
    {
        $out = [];
        foreach ($db->fetchAll('SELECT id, sort_order FROM items WHERE type = ? ORDER BY id', [$tipo]) as $r) {
            $out[(int) $r['id']] = (int) $r['sort_order'];
        }
        return $out;
    }
}

test('apply: redistribui os valores que as próprias linhas ocupavam', function () {
    $db = mo_db([[7, 'produto', 'A', 3], [12, 'produto', 'B', 8], [30, 'produto', 'C', 5]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [12, 7, 30]);

    // Valores existentes {3,5,8} redistribuídos na nova ordem visual.
    check_same([7 => 5, 12 => 3, 30 => 8], mo_ordem($db, 'produto'));
});

test('apply: NÃO renumera de 1 a N — é o que protege as outras páginas', function () {
    // Linhas da "página 2", ocupando 51..53. Depois de reordenar, elas têm
    // que continuar em 51..53, senão invadem a página 1.
    $db = mo_db([[1, 'produto', 'A', 51], [2, 'produto', 'B', 52], [3, 'produto', 'C', 53]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [3, 1, 2]);

    check_same([1 => 52, 2 => 53, 3 => 51], mo_ordem($db, 'produto'));
});

test('apply: linha fora da lista recebida não é tocada', function () {
    // O caso do filtro: quem está escondido fica onde estava, e quem foi
    // arrastado de fato troca de posição entre si.
    $db = mo_db([[1, 'produto', 'A', 10], [2, 'produto', 'B', 20], [3, 'produto', 'C', 30]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [3, 1]);

    $ordem = mo_ordem($db, 'produto');
    check_same(20, $ordem[2], 'a linha 2 não estava na lista e não pode ter mudado');
    check_same(
        [1 => 30, 3 => 10],
        [1 => $ordem[1], 3 => $ordem[3]],
        'as linhas 1 e 3 formavam a lista recebida e devem ter permutado suas posições entre si'
    );
});

test('apply: id de outro escopo é ignorado', function () {
    // Defesa: o POST manda ids; um id de outro tipo não pode ser movido.
    $db = mo_db([[1, 'produto', 'A', 10], [2, 'produto', 'B', 20], [9, 'servico', 'X', 99]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [2, 9, 1]);

    check_same(99, mo_ordem($db, 'servico')[9], 'o item de outro tipo não pode ser tocado');
    check_same([1 => 20, 2 => 10], mo_ordem($db, 'produto'));
});

test('apply: id inexistente ou <= 0 é pulado sem quebrar', function () {
    $db = mo_db([[1, 'produto', 'A', 10], [2, 'produto', 'B', 20]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [2, 0, -5, 999, 1]);

    check_same([1 => 20, 2 => 10], mo_ordem($db, 'produto'));
});

test('apply: id repetido não estoura', function () {
    // POST malformado ou hostil. Sem array_unique, a lista de alvos fica
    // maior que a de posições e a atribuição sai do índice.
    $db = mo_db([[1, 'produto', 'A', 10], [2, 'produto', 'B', 20]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [2, 2, 1, 2]);

    check_same([1 => 20, 2 => 10], mo_ordem($db, 'produto'));
});

test('apply: lista vazia é no-op', function () {
    $db = mo_db([[1, 'produto', 'A', 10], [2, 'produto', 'B', 20]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', []);

    check_same([1 => 10, 2 => 20], mo_ordem($db, 'produto'));
});

test('apply: sobre sequência toda empatada é no-op', function () {
    // Se o escopo inteiro estiver empatado (por exemplo {0,0,0}), apply()
    // só redistribui os valores que já existem: {0,0,0} redistribuído
    // continua {0,0,0} — nenhuma ordem visual nova se materializa. É
    // exatamente o estado terminal que ensureSeeded() existe para impedir:
    // por isso a listagem semeia o escopo antes de aceitar qualquer
    // arrasto, e não depois. Uma vez que o empate exista, só uma correção
    // estrutural (ensureSeeded), não um arrasto (apply), o desfaz.
    $db = mo_db([[1, 'produto', 'A', 0], [2, 'produto', 'B', 0], [3, 'produto', 'C', 0]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [3, 1, 2]);

    check_same([1 => 0, 2 => 0, 3 => 0], mo_ordem($db, 'produto'));
});

test('apply: todas as linhas já na posição certa não escreve nada', function () {
    // Cobre o `continue` do laço: quando a ordem recebida já bate com o
    // sort_order atual de cada linha, não há UPDATE a fazer.
    $db = mo_db([[1, 'produto', 'A', 10], [2, 'produto', 'B', 20], [3, 'produto', 'C', 30]]);

    ManualOrder::apply($db, 'items', 'type', 'produto', [1, 2, 3]);

    check_same([1 => 10, 2 => 20, 3 => 30], mo_ordem($db, 'produto'));
});

test('ensureSeeded: sequência virgem recebe a ordem visível, não a ordem de id', function () {
    // Tudo em 0. A semente é published_at DESC, que aqui é o INVERSO de id.
    // Semear por id reordenaria o site ao ligar a flag — é o bug que este
    // teste existe para impedir.
    $db = mo_db([[1, 'produto', 'A', 0], [2, 'produto', 'B', 0], [3, 'produto', 'C', 0]]);

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');

    // published_at decresce com o id, então id 1 é o mais recente → posição 1.
    check_same([1 => 1, 2 => 2, 3 => 3], mo_ordem($db, 'produto'));
});

test('ensureSeeded: sequência já distinta não é mexida', function () {
    $db = mo_db([[1, 'produto', 'A', 40], [2, 'produto', 'B', 10], [3, 'produto', 'C', 25]]);

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');

    check_same([1 => 40, 2 => 10, 3 => 25], mo_ordem($db, 'produto'));
});

test('ensureSeeded: cura o escopo onde um registro novo entrou antes da semeadura', function () {
    // O cenário do defeito: a migration deixa {1,2,3} tudo em sort_order=0
    // e, antes de a listagem daquele tipo ser aberta pela primeira vez, um
    // registro 4 é criado usando next() = MAX(0)+1 = 1. O escopo vira
    // {0,0,0,1}: MIN=0, MAX=1 — a detecção antiga (MIN=MAX) lia isso como
    // "já semeado" e nunca corrigia. E como apply() só redistribui os
    // valores que já existem, o empate {0,0,0} nunca se desfazia sozinho:
    // falha silenciosa e permanente, por tipo, sem esta correção.
    $db = mo_db([
        [1, 'produto', 'A', 0],
        [2, 'produto', 'B', 0],
        [3, 'produto', 'C', 0],
        [4, 'produto', 'D', 1],
    ]);

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');

    $ordem = mo_ordem($db, 'produto');
    check_same(4, count(array_unique(array_values($ordem))), 'as quatro posições devem ficar distintas');
    check_same($ordem[4], max($ordem), 'o registro criado antes da semeadura deve ficar por último');
});

test('ensureSeeded: desfaz empate preservando a ordem curada', function () {
    // 1 antes do grupo, 4 depois — ordem já curada à mão por arrasto. Só o
    // empate entre 2 e 3 (por exemplo, dois registros que uma importação
    // criou com o mesmo sort_order) nunca foi resolvido. A correção deve
    // desfazer só esse empate, sem perder a curadoria ao redor dele.
    $db = mo_db([
        [1, 'produto', 'A', 10],
        [2, 'produto', 'B', 20],
        [3, 'produto', 'C', 20],
        [4, 'produto', 'D', 30],
    ]);

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');

    $ordem = mo_ordem($db, 'produto');
    check_same(4, count(array_unique(array_values($ordem))), 'as posições devem ficar distintas');
    check(
        $ordem[1] < $ordem[2] && $ordem[1] < $ordem[3],
        'o registro 1 deve continuar antes do grupo que estava empatado'
    );
    check(
        $ordem[2] < $ordem[4] && $ordem[3] < $ordem[4],
        'o registro 4 deve continuar depois do grupo que estava empatado'
    );
});

test('ensureSeeded: chamada duas vezes seguidas é idempotente', function () {
    $db = mo_db([
        [1, 'produto', 'A', 0],
        [2, 'produto', 'B', 0],
        [3, 'produto', 'C', 0],
        [4, 'produto', 'D', 1],
    ]);

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');
    $primeira = mo_ordem($db, 'produto');

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');

    check_same($primeira, mo_ordem($db, 'produto'), 'a segunda chamada não pode mudar nada — a sequência já está íntegra');
});

test('ensureSeeded: uma linha só não é considerada virgem', function () {
    // A guarda é `total < 2`: com uma linha só não existe par pra empatar
    // (não há "posição repetida" possível), e semear seria escrita inútil a
    // cada carregamento da listagem.
    $db = mo_db([[1, 'produto', 'A', 0]]);

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');

    check_same([1 => 0], mo_ordem($db, 'produto'));
});

test('ensureSeeded: escopo vazio é no-op', function () {
    $db = mo_db([[1, 'servico', 'X', 0]]);

    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');

    check_same([1 => 0], mo_ordem($db, 'servico'));
});

if (!class_exists('MotCountingPdo')) {
    /**
     * PDO que conta prepare() — usada só pra medir quantos statements
     * ensureSeeded() de fato executa (item 2 da revisão final: antes da
     * correção, o laço final fazia um UPDATE por linha do escopo, sem pular
     * as que já estavam no valor certo).
     */
    class MotCountingPdo extends PDO
    {
        public int $prepared = 0;

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->prepared++;
            return parent::prepare($query, $options);
        }
    }
}

test('ensureSeeded: escopo grande e quase todo em ordem só grava as linhas que de fato mudam de posição', function () {
    // Medido na revisão (item 2): 5002 linhas com UM empate no meio → antes
    // desta correção, 5002 UPDATEs (um por linha, sem pular as que já
    // estavam certas, cada um em autocommit próprio dentro de um GET), dos
    // quais só 1 mudava algo de fato. Aqui com 200 linhas pra manter o
    // teste rápido, mesma forma do defeito: sort_order = id pra 1..199,
    // com a linha 199 e a 200 empatadas (ambas em sort_order=199) — só uma
    // das duas precisa se mover.
    $n = 200;
    $pdo = new MotCountingPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE items (
            id INTEGER PRIMARY KEY, type TEXT, title TEXT,
            sort_order INTEGER NOT NULL DEFAULT 0,
            published_at TEXT, created_at TEXT
        )'
    );
    $ins = $pdo->prepare('INSERT INTO items (id, type, title, sort_order, published_at, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $original = [];
    for ($id = 1; $id <= $n; $id++) {
        $so = $id === $n ? $n - 1 : $id; // a última linha empata com a penúltima
        $original[$id] = $so;
        // published_at CRESCENTE com id — desempate determinístico do par empatado.
        $ts = sprintf('2026-01-01 %02d:%02d:00', intdiv($id, 60), $id % 60);
        $ins->execute([$id, 'produto', 'Item ' . $id, $so, $ts, $ts]);
    }

    $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);

    $pdo->prepared = 0; // zera: só conta o que ensureSeeded() faz daqui pra frente
    ManualOrder::ensureSeeded($db, 'items', 'type', 'produto', 'published_at DESC, created_at DESC');
    $preparedPorEnsureSeeded = $pdo->prepared;

    $ordem = mo_ordem($db, 'produto');
    $mudaram = 0;
    foreach ($original as $id => $so) {
        if ($ordem[$id] !== $so) {
            $mudaram++;
        }
    }

    check_same($n, count(array_unique(array_values($ordem))), 'as posições devem ficar distintas');
    check_same(1, $mudaram, 'só a linha que estava empatada deveria ter mudado de sort_order');
    // 1 SELECT (resumo total/distintos) + 1 SELECT (releitura ordenada) + 1
    // UPDATE (a única linha que de fato muda) = 3. O laço antigo (um UPDATE
    // por linha, sem pular as que já estavam certas) faria 1 + 1 + $n = 202
    // pra este mesmo fixture — a diferença é exatamente a economia que este
    // teste existe pra proteger.
    check_same(
        3,
        $preparedPorEnsureSeeded,
        "ensureSeeded() preparou {$preparedPorEnsureSeeded} statements pra {$n} linhas com 1 empate — esperado 3 (2 selects + 1 update)"
    );
});

test('next: devolve MAX + 1 do escopo', function () {
    $db = mo_db([[1, 'produto', 'A', 4], [2, 'produto', 'B', 9], [3, 'servico', 'X', 99]]);

    check_same(10, ManualOrder::next($db, 'items', 'type', 'produto'));
});

test('next: escopo vazio começa em 1', function () {
    $db = mo_db([[1, 'servico', 'X', 5]]);

    check_same(1, ManualOrder::next($db, 'items', 'type', 'produto'));
});
