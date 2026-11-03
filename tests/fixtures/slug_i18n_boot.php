<?php

declare(strict_types=1);

use Velk\App;
use Velk\Config;
use Velk\Database;
use Velk\I18n;

/**
 * Um site multilíngue de mentira, com banco SQLite em memória, para testar o
 * roteamento por slug traduzido sem MySQL.
 *
 * As buscas por slug traduzido usam `JSON_UNQUOTE(JSON_EXTRACT(...))`, do
 * MySQL. O `json_extract` do SQLite já devolve o texto sem aspas e devolve
 * NULL para um `null` do JSON — o MySQL devolve o JSON (`"my-post"`, `null`),
 * e `JSON_UNQUOTE` de um `null` é o texto 'null'. Para o teste pegar o que o
 * MySQL faz, as duas funções são registradas aqui com o comportamento dele
 * (só o caminho `$."chave"`, o único que o core usa). Idiomas: pt-BR (padrão,
 * sem prefixo), en (/en), es (/es).
 */

if (!function_exists('sib_boot')) {
    // Conta as consultas (prepare) — para testar que algo não cresce com o
    // número de registros. Pdo\Sqlite existe a partir do PHP 8.4.
    if (class_exists(\Pdo\Sqlite::class)) {
        class SibPdo extends \Pdo\Sqlite
        {
            public int $queries = 0;
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->queries++;
                return parent::prepare($query, $options);
            }
        }
    } else {
        class SibPdo extends PDO
        {
            public int $queries = 0;
            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                $this->queries++;
                return parent::prepare($query, $options);
            }
        }
    }

    /** Consultas feitas desde o sib_boot(). */
    function sib_queries(): int
    {
        return (new ReflectionProperty(Database::class, 'pdo'))->getValue(App::instance()->db)->queries;
    }

    /** @param string $current o idioma ativo do visitante */
    function sib_boot(string $current = 'pt-BR'): App
    {
        $site = [
            'i18n' => ['enabled' => true, 'default' => 'pt-BR'],
            'item_types' => [
                'post' => ['label' => 'Posts', 'slug' => 'post', 'template' => 'single-post.php'],
                'docs' => ['label' => 'Docs', 'slug' => 'docs', 'template' => 'single-docs.php', 'hierarchical' => true],
            ],
            'taxonomies' => [
                'categoria' => ['label' => 'Categorias', 'slug' => 'categoria'],
                'secao' => ['label' => 'Seções', 'slug' => 'secao', 'hierarchical' => true],
            ],
            'pages' => [
                'home' => ['label' => 'Home', 'template' => 'page-home.php'],
                'sobre' => ['label' => 'Sobre', 'url' => '/sobre', 'template' => 'page-sobre.php'],
                'servicos' => ['label' => 'Serviços', 'url' => '/servicos', 'template' => 'page.php'],
                'consultoria' => ['label' => 'Consultoria', 'url' => 'consultoria', 'parent' => 'servicos', 'template' => 'page.php'],
            ],
            'page_templates' => [
                'landing' => ['label' => 'Landing', 'file' => 'page-landing.php'],
            ],
        ];
        $config = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Config::class, 'env'))->setValue($config, ['app' => ['url' => 'https://site.test']]);
        (new ReflectionProperty(Config::class, 'site'))->setValue($config, $site);
        $app = new App($config);
        $app->basePath = '';

        // PHP 8.4+: SibPdo estende Pdo\Sqlite, cujo createFunction
        // substitui o sqliteCreateFunction (depreciado na 8.5).
        $pdo = new SibPdo('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $extract = static function (?string $json, string $path): ?string {
            $data = $json === null ? null : json_decode($json, true);
            $key = substr($path, 3, -1); // $."en" → en
            return is_array($data) && array_key_exists($key, $data) ? json_encode($data[$key]) : null;
        };
        $unquote = static fn(?string $v): ?string => $v !== null && str_starts_with($v, '"') ? json_decode($v) : $v;
        $define = method_exists($pdo, 'createFunction') ? 'createFunction' : 'sqliteCreateFunction';
        $pdo->{$define}('JSON_EXTRACT', $extract, 2);
        $pdo->{$define}('JSON_UNQUOTE', $unquote, 1);
        $pdo->exec("CREATE TABLE items (id INTEGER PRIMARY KEY, type TEXT, slug TEXT, parent_id INTEGER NULL, title TEXT,
            template TEXT NULL, status TEXT, fields TEXT, published_at TEXT NULL, created_at TEXT, updated_at TEXT,
            title_translations TEXT NULL, slug_translations TEXT NULL, fields_translations TEXT NULL, sort_order INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE terms (id INTEGER PRIMARY KEY, taxonomy TEXT, slug TEXT, template TEXT NULL, name TEXT,
            parent_id INTEGER NULL, fields TEXT, name_translations TEXT NULL, slug_translations TEXT NULL,
            fields_translations TEXT NULL, sort_order INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE pages (id INTEGER PRIMARY KEY, page_key TEXT, title TEXT, fields TEXT, template TEXT NULL,
            slug TEXT NULL, parent_key TEXT NULL, updated_at TEXT NULL, title_translations TEXT NULL,
            slug_translations TEXT NULL, fields_translations TEXT NULL)");
        $pdo->exec("CREATE TABLE item_term (item_id INTEGER, term_id INTEGER, sort_order INTEGER DEFAULT 0)");
        $pdo->exec("CREATE TABLE languages (code TEXT PRIMARY KEY, label TEXT, url_prefix TEXT NULL, sort_order INTEGER,
            enabled INTEGER, created_at TEXT)");
        $pdo->exec("CREATE TABLE config_translations (id INTEGER PRIMARY KEY, config_type TEXT, config_key TEXT,
            translations TEXT, updated_at TEXT NULL)");
        $insert = $pdo->prepare('INSERT INTO languages VALUES (?, ?, ?, ?, 1, ?)');
        foreach ([['pt-BR', 'Português', null, 0], ['en', 'English', '/en', 1], ['es', 'Español', '/es', 2]] as [$code, $label, $prefix, $order]) {
            $insert->execute([$code, $label, $prefix, $order, '2026-01-01']);
        }

        $db = (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(Database::class, 'pdo'))->setValue($db, $pdo);
        $app->db = $db;

        $i18n = (new ReflectionClass(I18n::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(I18n::class, 'config'))->setValue($i18n, $config);
        (new ReflectionProperty(I18n::class, 'catalog'))->setValue($i18n, []);
        (new ReflectionProperty(I18n::class, 'activeLanguage'))->setValue($i18n, $current);
        $app->i18n = $i18n;

        return $app;
    }

    /** Troca o idioma ativo do visitante no App montado por sib_boot(). */
    function sib_i18n_current(string $current): void
    {
        (new ReflectionProperty(I18n::class, 'activeLanguage'))->setValue(App::instance()->i18n, $current);
    }

    /** Insere um item e devolve o id. `slugs` = slug_translations. */
    function sib_item(string $type, string $slug, array $slugs = [], ?int $parent = null, string $status = 'published'): int
    {
        $db = App::instance()->db;
        $db->query(
            'INSERT INTO items (type, slug, parent_id, title, status, fields, created_at, updated_at, slug_translations)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$type, $slug, $parent, ucfirst($slug), $status, '{}', '2026-01-01', '2026-01-01', $slugs === [] ? null : json_encode($slugs)]
        );
        return (int) $db->fetchColumn('SELECT MAX(id) FROM items');
    }

    function sib_term(string $taxonomy, string $slug, array $slugs = [], ?int $parent = null): int
    {
        $db = App::instance()->db;
        $db->query(
            'INSERT INTO terms (taxonomy, slug, name, parent_id, fields, slug_translations) VALUES (?, ?, ?, ?, ?, ?)',
            [$taxonomy, $slug, ucfirst($slug), $parent, '{}', $slugs === [] ? null : json_encode($slugs)]
        );
        return (int) $db->fetchColumn('SELECT MAX(id) FROM terms');
    }

    /** Uma página do site.json (só a linha das traduções) ou uma página criada pelo painel (com `template`). */
    function sib_page(string $key, array $slugs = [], ?string $slug = null, ?string $template = null, ?string $parentKey = null): void
    {
        App::instance()->db->query(
            'INSERT INTO pages (page_key, title, fields, template, slug, parent_key, slug_translations) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$key, ucfirst($key), '{}', $template, $slug, $parentKey, $slugs === [] ? null : json_encode($slugs)]
        );
    }
}
