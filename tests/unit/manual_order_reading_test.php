<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\Models\Item;
use Velk\Models\Term;

/**
 * v3.7.5 — o ORDER BY de leitura, com e sem a flag.
 *
 * O teste mais importante do conjunto é a INVARIANTE: tipo ou taxonomia sem
 * `manual_order` tem que continuar produzindo exatamente a ordem de hoje,
 * no site e no painel. Todo site Velk existente depende disso, e uma
 * regressão aqui reordena silenciosamente a home de quem atualizar.
 *
 * `Item::listAdmin()` está aqui de propósito. Ela ordena por `created_at
 * DESC`, independente do site. Sem ficar ciente da flag, a pessoa
 * arrastaria linhas exibidas em ordem de criação enquanto o site mostra
 * `sort_order` — o recurso não fecharia.
 */

if (!function_exists('mor_boot')) {
    /**
     * App com um site.json sintético e um banco em memória com items, terms
     * e item_term.
     *
     * @param array<string,mixed> $itemTypes
     * @param array<string,mixed> $taxonomies
     * @param list<array{0:int,1:int}> $itemTerms pares [item_id, term_id] pra popular item_term (default: nenhum vínculo)
     */
    function mor_boot(array $itemTypes, array $taxonomies, array $itemTerms = []): void
    {
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => []]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'item_types' => $itemTypes,
            'taxonomies' => $taxonomies,
        ]);

        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        // `id INTEGER NOT NULL` — NÃO `PRIMARY KEY` — de propósito: no
        // SQLite, `id INTEGER PRIMARY KEY` é alias de `rowid`, e a varredura
        // sai em ordem de id mesmo sem NENHUM `ORDER BY` pedir isso. Isso
        // mascarava a ausência de `id ASC` no código (os testes de empate
        // abaixo passavam mesmo removendo `id ASC` do ORDER BY). Como
        // coluna comum, `id` já não dita a ordem física — só a ordem de
        // INSERÇÃO (rowid implícito) faz isso quando `id ASC` não está no
        // SQL, o que os testes de empate exploram de propósito.
        $pdo->exec(
            'CREATE TABLE items (
                id INTEGER NOT NULL, type TEXT, slug TEXT, title TEXT,
                template TEXT, status TEXT, fields TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
                published_at TEXT, created_at TEXT, updated_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE terms (
                id INTEGER NOT NULL, taxonomy TEXT, slug TEXT, name TEXT,
                parent_id INTEGER, fields TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT, updated_at TEXT
            )'
        );
        // item_term: só o par (item_id, term_id), igual ao schema real — o
        // que byTerm() precisa pra juntar item x termo nos testes de taxonomia.
        $pdo->exec(
            'CREATE TABLE item_term (
                item_id INTEGER NOT NULL, term_id INTEGER NOT NULL
            )'
        );

        // items: id 1 é o mais ANTIGO em published_at, mas sort_order 1.
        // Assim as duas ordens são visivelmente diferentes.
        $ins = $pdo->prepare(
            'INSERT INTO items (id, type, slug, title, status, fields, sort_order, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([1, 'produto', 'a', 'A', 'published', '[]', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $ins->execute([2, 'produto', 'b', 'B', 'published', '[]', 2, '2026-01-02 00:00:00', '2026-01-02 00:00:00', '2026-01-02 00:00:00']);
        $ins->execute([3, 'produto', 'c', 'C', 'published', '[]', 3, '2026-01-03 00:00:00', '2026-01-03 00:00:00', '2026-01-03 00:00:00']);

        // terms: sort_order é o inverso alfabético de name.
        $insT = $pdo->prepare(
            'INSERT INTO terms (id, taxonomy, slug, name, parent_id, fields, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insT->execute([1, 'categoria', 'zebra', 'Zebra', null, '[]', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);
        $insT->execute([2, 'categoria', 'alfa', 'Alfa', null, '[]', 2, '2026-01-01 00:00:00', '2026-01-01 00:00:00']);

        // item_term: só quando o teste passa vínculos — vazio por padrão,
        // pra não afetar quem já chamava mor_boot() com dois argumentos.
        if ($itemTerms !== []) {
            $insIt = $pdo->prepare('INSERT INTO item_term (item_id, term_id) VALUES (?, ?)');
            foreach ($itemTerms as [$itemId, $termId]) {
                $insIt->execute([$itemId, $termId]);
            }
        }

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
    }
}

if (!function_exists('mor_ids')) {
    /** @param list<object> $registros */
    function mor_ids(array $registros): array
    {
        return array_map(static fn($r): int => (int) $r->id, $registros);
    }
}

if (!function_exists('mor_preseed_boot')) {
    /**
     * Item 1 da revisão final: o bloqueador. `mor_boot()` (acima) usa
     * published_at CRESCENTE com o id (1 é o mais antigo, 3 o mais novo) —
     * de propósito pros testes de INVARIANTE existentes, mas isso faz
     * published_at DESC e id ASC apontarem quase pra mesma direção, e um
     * teste de pré-semente aqui passaria por ACIDENTE mesmo se a cauda nova
     * de query()/listAdmin()/byTerm() ainda degenerasse pra `id ASC`.
     *
     * Este fixture tem 6 items cujo published_at (== created_at) NÃO
     * acompanha id: a ordem por data é [5, 3, 6, 1, 4, 2] — a mesma
     * permutação medida no relatório da revisão pro item 1 — bem diferente
     * de [1, 2, 3, 4, 5, 6] (ordem por id). Todos com sort_order=0 (estado
     * pré-semente) e vinculados ao termo 1 da taxonomia 'categoria', pros
     * três testes (query/listAdmin/byTerm) compartilharem o mesmo fixture.
     *
     * @param array<string,mixed> $typeExtra mesclado na definição do tipo 'produto' — normalmente ['manual_order' => true] pro cenário "com a flag, pré-semente"
     */
    function mor_preseed_boot(array $typeExtra = []): void
    {
        mor_boot(
            ['produto' => array_merge(['label' => 'Produtos'], $typeExtra)],
            ['categoria' => ['label' => 'Categorias']]
        );
        $db = App::instance()->db;
        $db->query('DELETE FROM items'); // descarta os 3 items padrão de mor_boot()

        $ins = $db->pdo()->prepare(
            'INSERT INTO items (id, type, slug, title, status, fields, sort_order, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?)'
        );
        $insTerm = $db->pdo()->prepare('INSERT INTO item_term (item_id, term_id) VALUES (?, 1)');

        // Índice = posição na ordem por data (0 = mais recente); o id é o
        // valor da permutação. sort_order fica sempre 0 — pré-semente.
        $ordemPorData = [5, 3, 6, 1, 4, 2];
        foreach ($ordemPorData as $posicao => $id) {
            $ts = sprintf('2026-02-%02d 00:00:00', 6 - $posicao);
            $ins->execute([$id, 'produto', 'item-' . $id, 'Item ' . $id, 'published', '[]', $ts, $ts, $ts]);
            $insTerm->execute([$id]);
        }
    }
}

test('INVARIANTE — items(): tipo sem a flag mantém published_at DESC', function () {
    mor_boot(['produto' => ['label' => 'Produtos']], []);

    // published_at cresce com o id, então DESC é 3, 2, 1.
    check_same([3, 2, 1], mor_ids(Item::query('produto')));
});

test('items(): tipo com a flag ordena por sort_order ASC', function () {
    mor_boot(['produto' => ['label' => 'Produtos', 'manual_order' => true]], []);

    check_same([1, 2, 3], mor_ids(Item::query('produto')));
});

test('items(): sort_order empatado desempata por id ASC de forma determinística', function () {
    // Empate é o estado NORMAL assim que a flag liga, antes da semeadura —
    // sem `id ASC`, duas linhas com o mesmo sort_order (e mesma
    // published_at/created_at — a cauda nova do item 1 da revisão final)
    // saem em ordem indefinida no MySQL.
    //
    // id=0 empata com id=1 em sort_order, published_at E created_at — só
    // sobra `id` pra desempatar — e é inserido DEPOIS dele. Com `id INTEGER
    // NOT NULL` (ver mor_boot), isso inverte a ordem física de inserção em
    // relação à ordem de id: só o `id ASC` explícito no ORDER BY garante
    // id=0 antes de id=1 no resultado; sem ele, o SQLite resolve o empate
    // pela ordem de inserção (id=1 primeiro) e este teste pegaria a regressão.
    mor_boot(['produto' => ['label' => 'Produtos', 'manual_order' => true]], []);
    App::instance()->db->query(
        "INSERT INTO items (id, type, slug, title, status, fields, sort_order, published_at, created_at, updated_at)
         VALUES (0, 'produto', 'zero', 'Zero', 'published', '[]', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
    );

    // sort_order ASC: {0,1} (so=1) empatam em tudo menos id — 0 primeiro;
    // depois 2 (so=2) e 3 (so=3), já distintos.
    check_same([0, 1, 2, 3], mor_ids(Item::query('produto')));
});

test('INVARIANTE PRÉ-SEMENTE — items(): com a flag ligada e tudo em sort_order=0, a ordem é idêntica à de sem a flag', function () {
    // O bloqueador (item 1 da revisão final): entre ligar `manual_order` no
    // site.json e a primeira abertura da listagem do painel (que semeia),
    // sort_order é 0 em toda linha. Sem a cauda de fallback, `ORDER BY
    // sort_order ASC, id ASC` degenera pra `id ASC` — cronológico crescente,
    // o INVERSO do padrão de items — e o site troca de ordem sozinho no ato
    // de ligar a flag, sem ninguém tocar em nada.
    mor_preseed_boot();
    $semFlag = mor_ids(Item::query('produto'));

    mor_preseed_boot(['manual_order' => true]);
    $comFlagPreSemente = mor_ids(Item::query('produto'));

    check_same([5, 3, 6, 1, 4, 2], $semFlag, 'fixture: published_at NÃO acompanha id — ver mor_preseed_boot');
    check_same($semFlag, $comFlagPreSemente, 'ligar a flag não pode reordenar o site antes da primeira semeadura');
});

test('items(): o order do tema continua ganhando, com e sem a flag', function () {
    mor_boot(['produto' => ['label' => 'Produtos', 'manual_order' => true]], []);
    check_same([3, 2, 1], mor_ids(Item::query('produto', ['order' => 'id DESC'])));

    mor_boot(['produto' => ['label' => 'Produtos']], []);
    check_same([1, 2, 3], mor_ids(Item::query('produto', ['order' => 'id ASC'])));
});

test('items(): o tema pode pedir sort_order mesmo sem a flag', function () {
    mor_boot(['produto' => ['label' => 'Produtos']], []);

    check_same([1, 2, 3], mor_ids(Item::query('produto', ['order' => 'sort_order ASC'])));
});

test('items(): coluna inventada continua sendo rejeitada', function () {
    mor_boot(['produto' => ['label' => 'Produtos']], []);

    // Cai no default (published_at DESC), não explode nem injeta.
    check_same([3, 2, 1], mor_ids(Item::query('produto', ['order' => 'senha ASC'])));
});

test('INVARIANTE — listAdmin(): tipo sem a flag mantém created_at DESC', function () {
    mor_boot(['produto' => ['label' => 'Produtos']], []);

    check_same([3, 2, 1], mor_ids(Item::listAdmin('produto')));
});

test('listAdmin(): tipo com a flag ordena por sort_order ASC', function () {
    // Sem isto, o painel mostra uma ordem e o site outra.
    mor_boot(['produto' => ['label' => 'Produtos', 'manual_order' => true]], []);

    check_same([1, 2, 3], mor_ids(Item::listAdmin('produto')));
});

test('listAdmin(): sort_order empatado desempata por id ASC de forma determinística', function () {
    // Mesmo mecanismo do teste equivalente de query() acima — id=0 empata
    // com id=1 em sort_order E created_at (a cauda de listAdmin, sem
    // published_at) e é inserido DEPOIS dele, só pra `id ASC` decidir.
    mor_boot(['produto' => ['label' => 'Produtos', 'manual_order' => true]], []);
    App::instance()->db->query(
        "INSERT INTO items (id, type, slug, title, status, fields, sort_order, published_at, created_at, updated_at)
         VALUES (0, 'produto', 'zero', 'Zero', 'published', '[]', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
    );

    check_same([0, 1, 2, 3], mor_ids(Item::listAdmin('produto')));
});

test('INVARIANTE PRÉ-SEMENTE — listAdmin(): com a flag ligada e tudo em sort_order=0, a ordem é idêntica à de sem a flag', function () {
    // Mesmo bloqueador do teste equivalente de query() acima, aplicado ao
    // painel: listAdmin() tem que continuar em created_at DESC enquanto
    // sort_order não foi semeado, senão o painel também reordena sozinho.
    mor_preseed_boot();
    $semFlag = mor_ids(Item::listAdmin('produto'));

    mor_preseed_boot(['manual_order' => true]);
    $comFlagPreSemente = mor_ids(Item::listAdmin('produto'));

    check_same([5, 3, 6, 1, 4, 2], $semFlag, 'fixture: created_at NÃO acompanha id — ver mor_preseed_boot');
    check_same($semFlag, $comFlagPreSemente, 'ligar a flag não pode reordenar o PAINEL antes da primeira semeadura');
});

test('INVARIANTE — terms(): taxonomia sem a flag mantém name ASC', function () {
    mor_boot([], ['categoria' => ['label' => 'Categorias']]);

    // Alfa (id 2) antes de Zebra (id 1).
    check_same([2, 1], mor_ids(Term::all('categoria')));
});

test('terms(): taxonomia com a flag ordena por sort_order ASC', function () {
    mor_boot([], ['categoria' => ['label' => 'Categorias', 'manual_order' => true]]);

    check_same([1, 2], mor_ids(Term::all('categoria')));
});

test('terms(): sort_order empatado desempata por name ASC de forma determinística', function () {
    mor_boot([], ['categoria' => ['label' => 'Categorias', 'manual_order' => true]]);
    // id 1 (Zebra) e id 2 (Alfa) empatam em sort_order — sem `name ASC` a
    // ordem entre eles fica indefinida no MySQL.
    App::instance()->db->query('UPDATE terms SET sort_order = 1 WHERE id IN (1, 2)');

    // Empate total em sort_order: cai pra name ASC — Alfa (id 2) antes de Zebra (id 1).
    check_same([2, 1], mor_ids(Term::all('categoria')));
});

test('terms(): sort_order E name empatados desempata por id ASC de forma determinística', function () {
    mor_boot([], ['categoria' => ['label' => 'Categorias', 'manual_order' => true]]);
    // id=0, mesmo name do id 1 (Zebra) e mesmo sort_order — name não é
    // único, então esse segundo empate só se resolve com o id no final.
    // id=0 é inserido DEPOIS de id=1 (mor_boot usa `id INTEGER NOT NULL`,
    // não mais PRIMARY KEY — ver o comentário lá): ordem física invertida em
    // relação à ordem de id, então só o `id ASC` explícito garante id=0
    // antes de id=1 no resultado.
    App::instance()->db->query(
        "INSERT INTO terms (id, taxonomy, slug, name, parent_id, fields, sort_order, created_at, updated_at)
         VALUES (0, 'categoria', 'zebra-0', 'Zebra', NULL, '[]', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
    );

    // so=1 empata id 0 e id 1 (ambos Zebra) — desempate final por id ASC: 0, 1.
    // id 2 (Alfa, so=2) vem depois.
    check_same([0, 1, 2], mor_ids(Term::all('categoria')));
});

test('byTerm(): taxonomia de um tipo só, com a flag — ordena por sort_order', function () {
    mor_boot(
        ['produto' => ['label' => 'Produtos', 'manual_order' => true, 'taxonomies' => ['categoria']]],
        ['categoria' => ['label' => 'Categorias']],
        [[1, 1], [2, 1], [3, 1]]
    );

    check_same([1, 2, 3], mor_ids(Item::byTerm(1)));
});

test('byTerm(): taxonomia de um tipo só, sem a flag — ordem por data, inalterada', function () {
    mor_boot(
        ['produto' => ['label' => 'Produtos', 'taxonomies' => ['categoria']]],
        ['categoria' => ['label' => 'Categorias']],
        [[1, 1], [2, 1], [3, 1]]
    );

    check_same([3, 2, 1], mor_ids(Item::byTerm(1)));
});

test('byTerm(): taxonomia declarada por dois tipos, ambos com a flag — ordem por data (regra do tipo único)', function () {
    // Dois tipos declarando a MESMA taxonomia, cada um com sua própria
    // sequência de sort_order — misturar as duas não teria sentido, então
    // byTerm() recua pra data mesmo com as duas flags ligadas.
    mor_boot(
        [
            'produto' => ['label' => 'Produtos', 'manual_order' => true, 'taxonomies' => ['categoria']],
            'servico' => ['label' => 'Serviços', 'manual_order' => true, 'taxonomies' => ['categoria']],
        ],
        ['categoria' => ['label' => 'Categorias']],
        [[1, 1], [2, 1], [3, 1]]
    );

    check_same([3, 2, 1], mor_ids(Item::byTerm(1)));
});

test('byTerm(): termo inexistente não quebra', function () {
    mor_boot(
        ['produto' => ['label' => 'Produtos', 'manual_order' => true, 'taxonomies' => ['categoria']]],
        ['categoria' => ['label' => 'Categorias']]
    );

    check_same([], mor_ids(Item::byTerm(999)));
});

test('byTerm(): sort_order empatado desempata por id ASC de forma determinística', function () {
    // byTerm() não tinha NENHUM teste de empate — mesmo mecanismo dos
    // equivalentes de query()/listAdmin() acima: id=0 empata com id=1 em
    // sort_order, published_at E created_at, é inserido DEPOIS dele (e
    // vinculado ao mesmo termo), só pra `i.id ASC` decidir.
    mor_boot(
        ['produto' => ['label' => 'Produtos', 'manual_order' => true, 'taxonomies' => ['categoria']]],
        ['categoria' => ['label' => 'Categorias']],
        [[1, 1], [2, 1], [3, 1]]
    );
    $db = App::instance()->db;
    $db->query(
        "INSERT INTO items (id, type, slug, title, status, fields, sort_order, published_at, created_at, updated_at)
         VALUES (0, 'produto', 'zero', 'Zero', 'published', '[]', 1, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
    );
    $db->query('INSERT INTO item_term (item_id, term_id) VALUES (0, 1)');

    check_same([0, 1, 2, 3], mor_ids(Item::byTerm(1)));
});

test('INVARIANTE PRÉ-SEMENTE — byTerm(): com a flag ligada e tudo em sort_order=0, a ordem é idêntica à de sem a flag', function () {
    // Mesmo bloqueador dos dois testes acima, aplicado à listagem por
    // taxonomia (byTerm() é o que o TEMA usa pra imprimir "produtos desta
    // categoria" — a mesma degeneração pra `id ASC` reordenaria isso).
    mor_preseed_boot();
    $semFlag = mor_ids(Item::byTerm(1));

    mor_preseed_boot(['manual_order' => true, 'taxonomies' => ['categoria']]);
    $comFlagPreSemente = mor_ids(Item::byTerm(1));

    check_same([5, 3, 6, 1, 4, 2], $semFlag, 'fixture: published_at NÃO acompanha id — ver mor_preseed_boot');
    check_same($semFlag, $comFlagPreSemente, 'ligar a flag não pode reordenar a taxonomia antes da primeira semeadura');
});

if (!class_exists('MorCountingPdo')) {
    /**
     * PDO que conta toda chamada a prepare() — é por prepare() que
     * Database::query() (e por baixo fetch/fetchAll/fetchColumn) sempre
     * passa. exec() direto (usado só pra montar o fixture: CREATE TABLE,
     * seed) não conta — só query feita através da app entra na conta.
     */
    class MorCountingPdo extends PDO
    {
        public int $queries = 0;

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->queries++;
            return parent::prepare($query, $options);
        }
    }
}

test('byTerm(): site sem NENHUM tipo com manual_order não consulta o banco pra decidir a ordem (item 5 da revisão final)', function () {
    // Antes do item 5, byTermManualOrder() sempre rodava `SELECT taxonomy
    // FROM terms WHERE id = ?` pra decidir, mesmo quando NENHUM tipo do
    // site.json tem `manual_order` — o que torna a regra (count($types) ===
    // 1 && manualOrder($types[0])) impossível de dar true. Essa query saía
    // em todo arquivo de taxonomia de todo site Velk sem a flag.
    $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => []]);
    (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
        'item_types' => ['produto' => ['label' => 'Produtos', 'taxonomies' => ['categoria']]], // sem manual_order
        'taxonomies' => ['categoria' => ['label' => 'Categorias']],
    ]);
    $app = new App($config);
    $app->basePath = '';

    $pdo = new MorCountingPdo('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec(
        'CREATE TABLE items (
            id INTEGER NOT NULL, type TEXT, slug TEXT, title TEXT,
            template TEXT, status TEXT, fields TEXT, sort_order INTEGER NOT NULL DEFAULT 0,
            published_at TEXT, created_at TEXT, updated_at TEXT
        )'
    );
    $pdo->exec('CREATE TABLE terms (id INTEGER NOT NULL, taxonomy TEXT, slug TEXT, name TEXT, sort_order INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE item_term (item_id INTEGER NOT NULL, term_id INTEGER NOT NULL)');
    $pdo->exec(
        "INSERT INTO items (id, type, slug, title, status, fields, sort_order, published_at, created_at, updated_at)
         VALUES (1, 'produto', 'a', 'A', 'published', '[]', 0, '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
    );
    $pdo->exec("INSERT INTO terms (id, taxonomy, slug, name, sort_order) VALUES (1, 'categoria', 'cat', 'Cat', 0)");
    $pdo->exec('INSERT INTO item_term (item_id, term_id) VALUES (1, 1)');

    $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
    $app->db = $db;

    check_same(0, $pdo->queries, 'sanity: nada rodou ainda');

    Item::byTerm(1);

    // 1 query = só a busca principal (items JOIN item_term). Antes do item
    // 5 eram 2 — a extra (SELECT taxonomy FROM terms) não pode mais rodar
    // quando nenhum tipo do site tem a flag.
    check_same(1, $pdo->queries, 'byTerm() rodou mais query do que a busca principal — a guarda pura-PHP não entrou');
});

test('manualOrder(): ausente, false e truthy que não é true literal são todos false', function () {
    mor_boot(
        [
            'produto' => ['label' => 'P'],
            'servico' => ['label' => 'S', 'manual_order' => false],
            'string_verdadeiro' => ['label' => 'SV', 'manual_order' => 'true'],
            'numero_um' => ['label' => 'N1', 'manual_order' => 1],
            'string_sim' => ['label' => 'SS', 'manual_order' => 'sim'],
        ],
        [
            'categoria' => ['label' => 'C'],
            'tax_string_verdadeiro' => ['label' => 'TSV', 'manual_order' => 'true'],
            'tax_numero_um' => ['label' => 'TN1', 'manual_order' => 1],
            'tax_string_sim' => ['label' => 'TSS', 'manual_order' => 'sim'],
        ]
    );

    check_same(false, Item::manualOrder('produto'));
    check_same(false, Item::manualOrder('servico'));
    check_same(false, Item::manualOrder('inexistente'));
    // Truthy que não é `true` literal — a checagem é `=== true`, não `== true`.
    check_same(false, Item::manualOrder('string_verdadeiro'));
    check_same(false, Item::manualOrder('numero_um'));
    check_same(false, Item::manualOrder('string_sim'));

    check_same(false, Term::manualOrder('categoria'));
    check_same(false, Term::manualOrder('inexistente'));
    check_same(false, Term::manualOrder('tax_string_verdadeiro'));
    check_same(false, Term::manualOrder('tax_numero_um'));
    check_same(false, Term::manualOrder('tax_string_sim'));
});
