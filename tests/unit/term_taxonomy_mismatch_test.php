<?php

declare(strict_types=1);

use Velk\Admin\Controllers\TermController;
use Velk\App;
use Velk\Auth;
use Velk\Config;
use Velk\Database;
use Velk\Request;
use Velk\Session;

/**
 * Revisão de 2026-09: o termo precisa pertencer à taxonomia da URL.
 *
 * TermController::editOrCreate() não conferia isso — o ItemController
 * confere `type`, e delete()/bulkDelete() daqui já conferiam `taxonomy`.
 * Sem a checagem, /taxonomies/tag/{id} de um termo de `categoria` abria o
 * termo sob a tela de `tag`: validateTermParent() aceitava pai de OUTRA
 * taxonomia e ensureUniqueSlug() checava unicidade no lugar errado.
 *
 * O caso positivo (taxonomia certa) prova que a guarda deixa passar: ela
 * devolve 404 ANTES de qualquer render, então chegar ao render — mesmo que
 * o render falhe por faltar infraestrutura de view no harness — já é prova
 * de que a guarda não barrou. O teste aceita as duas formas.
 */

require_once dirname(__DIR__, 2) . '/core/helpers.php';

if (!function_exists('ttm_boot')) {
    /** @param list<array{0:int,1:string,2:string}> $terms [id, taxonomy, slug] */
    function ttm_boot(array $terms): App
    {
        $rootPath = dirname(__DIR__, 2);
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['paths' => ['core' => $rootPath . '/core']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, [
            'taxonomies' => [
                'categoria' => ['label' => 'Categorias', 'label_singular' => 'Categoria', 'template' => 't.php'],
                'tag'       => ['label' => 'Tags',       'label_singular' => 'Tag',       'template' => 't.php'],
            ],
            'item_types' => [],
        ]);
        $app = new App($config);
        $app->basePath = '';

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE terms (id INTEGER PRIMARY KEY, taxonomy TEXT, slug TEXT, name TEXT, parent_id INTEGER, template TEXT,
                    fields TEXT, sort_order INTEGER NOT NULL DEFAULT 0, name_translations TEXT, slug_translations TEXT,
                    fields_translations TEXT, created_at TEXT, updated_at TEXT)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT, password_hash TEXT, name TEXT, role TEXT,
                    two_factor_enabled INTEGER DEFAULT 0, locale TEXT, created_at TEXT)');
        $ins = $pdo->prepare("INSERT INTO terms (id, taxonomy, slug, name, fields, created_at, updated_at) VALUES (?, ?, ?, ?, '[]', '2026-01-01', '2026-01-01')");
        foreach ($terms as [$id, $tax, $slug]) $ins->execute([$id, $tax, $slug, ucfirst($slug)]);
        $pdo->exec("INSERT INTO users (id, email, password_hash, name, role) VALUES (1, 'a@t.dev', '', 'A', 'admin')");

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;
        $_SESSION = [];
        $app->session = new Session();
        $app->auth = new Auth($app->db, $app->session);
        $app->adminI18n = new \Velk\Admin\I18n($rootPath . '/core/Admin/lang', 'en', 'en');
        $app->session->put('user_id', 1);
        return $app;
    }
}

if (!function_exists('ttm_edit')) {
    /** @return int|string status, ou 'render' se a guarda deixou passar e o render falhou por infra */
    function ttm_edit(App $app, string $taxonomy, int $id): int|string
    {
        $req = new Request('GET', "/admin/taxonomies/{$taxonomy}/{$id}", [], [], [], ['HTTP_HOST' => 'site.com'], []);
        try {
            return (new TermController($app))->edit($req, ['taxonomy' => $taxonomy, 'id' => $id])->getStatus();
        } catch (\Throwable $e) {
            return 'render';
        }
    }
}

test('editar termo de OUTRA taxonomia pela URL devolve 404', function () {
    $app = ttm_boot([[42, 'categoria', 'juridico']]);
    check_same(404, ttm_edit($app, 'tag', 42), 'termo de categoria não pode abrir sob /taxonomies/tag/');
});

test('editar termo da taxonomia CERTA passa pela guarda', function () {
    $app = ttm_boot([[42, 'categoria', 'juridico']]);
    $r = ttm_edit($app, 'categoria', 42);
    check($r !== 404, "a guarda barrou um termo que pertence à taxonomia (resultado: {$r})");
});

test('termo inexistente continua 404 (não virou outro erro)', function () {
    $app = ttm_boot([[42, 'categoria', 'juridico']]);
    check_same(404, ttm_edit($app, 'categoria', 999));
});
