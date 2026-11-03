<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;
use Velk\Config;
use Velk\Richtext;
use Velk\TranslatedSlug;

final class Page
{
    public int $id;
    public string $key;
    public string $title;
    public array $fields = [];
    /**
     * For custom pages (admin-created), the page_template key declared
     * in site.json under `page_templates`. NULL for JSON-defined pages.
     */
    public ?string $template = null;
    /**
     * URL slug for custom pages. NULL for JSON pages (their URL comes
     * from the JSON `url` field or the page key itself).
     */
    public ?string $slug = null;
    /** page_key do pai (JSON ou custom); NULL = página-raiz. Subpáginas. */
    public ?string $parentKey = null;
    public ?string $updatedAt = null;

    // i18n Phase 3 — per-language overrides.
    /** @var array<string, string> */
    public array $titleTranslations = [];
    /** @var array<string, string> */
    public array $slugTranslations = [];
    /** @var array<string, array<string, mixed>> */
    public array $fieldsTranslations = [];

    public static function fromRow(array $row): self
    {
        $p = new self();
        $p->id = (int) $row['id'];
        $p->key = (string) $row['page_key'];
        $p->title = (string) $row['title'];
        $p->fields = is_string($row['fields'] ?? null) ? (array) (json_decode($row['fields'], true) ?? []) : [];
        $p->template = isset($row['template']) && $row['template'] !== null ? (string) $row['template'] : null;
        $p->slug = isset($row['slug']) && $row['slug'] !== null ? (string) $row['slug'] : null;
        $p->parentKey = isset($row['parent_key']) && $row['parent_key'] !== null ? (string) $row['parent_key'] : null;
        $p->updatedAt = $row['updated_at'] ?? null;

        $p->titleTranslations  = self::decodeJsonArray($row['title_translations'] ?? null);
        $p->slugTranslations   = self::decodeJsonArray($row['slug_translations'] ?? null);
        $p->fieldsTranslations = self::decodeJsonArray($row['fields_translations'] ?? null);

        return $p;
    }

    private static function decodeJsonArray(mixed $value): array
    {
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @var array<string, self>|null as linhas de `pages` por page_key, enquanto withAllRows() roda */
    private static ?array $allRows = null;

    /**
     * Roda $fn com todas as linhas de `pages` lidas de uma vez: findByKey() —
     * e com ele fullPath(), ownsUrlIn(), pageUrl() — lê da memória, em vez de
     * uma consulta por página e por nível da cadeia de pais. Para quem
     * percorre todas as páginas: o sitemap, a lista do painel, o aviso de
     * endereço disputado. Nada dentro de $fn pode gravar em `pages`.
     */
    public static function withAllRows(callable $fn): mixed
    {
        if (self::$allRows !== null) {
            return $fn();
        }
        self::$allRows = [];
        foreach (App::instance()->db->fetchAll('SELECT * FROM pages') as $row) {
            self::$allRows[(string) $row['page_key']] = self::fromRow($row);
        }
        try {
            return $fn();
        } finally {
            self::$allRows = null;
        }
    }

    public static function findByKey(string $key): ?self
    {
        if (self::$allRows !== null) {
            return self::$allRows[$key] ?? null;
        }
        $row = App::instance()->db->fetch('SELECT * FROM pages WHERE page_key = ? LIMIT 1', [$key]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findBySlug(string $slug): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM pages WHERE slug = ? LIMIT 1', [$slug]);
        return $row === null ? null : self::fromRow($row);
    }

    /** Todas as páginas com aquele slug (o slug repete entre ramos agora). */
    public static function findAllBySlug(string $slug): array
    {
        $rows = App::instance()->db->fetchAll('SELECT * FROM pages WHERE slug = ?', [$slug]);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /** Custom pages irmãs (mesmo parent_key) com aquele slug. */
    public static function siblingsWithSlug(string $parentKey, string $slug): array
    {
        $parentCond = $parentKey === '' ? 'parent_key IS NULL' : 'parent_key = ?';
        $sql = "SELECT * FROM pages WHERE template IS NOT NULL AND {$parentCond} AND slug = ?";
        $params = $parentKey === '' ? [$slug] : [$parentKey, $slug];
        $rows = App::instance()->db->fetchAll($sql, $params);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function all(): array
    {
        $rows = App::instance()->db->fetchAll('SELECT * FROM pages ORDER BY page_key ASC');
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Custom pages only — admin-created records (template IS NOT NULL).
     * JSON-defined pages live in the same table but always have
     * template=NULL, so this filter cleanly separates them.
     *
     * @param int $limit 0 = sem limite (compat com chamadas existentes)
     */
    public static function allCustom(int $limit = 0, int $offset = 0): array
    {
        $sql = 'SELECT * FROM pages WHERE template IS NOT NULL ORDER BY title ASC';
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $rows = App::instance()->db->fetchAll($sql);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function countCustom(): int
    {
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(*) FROM pages WHERE template IS NOT NULL'
        );
    }

    public function isCustom(): bool
    {
        return $this->template !== null;
    }

    /**
     * Resolve a URL path to a page. Checks JSON-defined pages first
     * (so JSON config can always override DB-defined custom pages),
     * then falls back to custom pages by slug.
     *
     * When `$language` is provided AND not equal to the default, each
     * level of the path is matched against the page's translated slug
     * (`slug_translations[$language]`) or, for a page with no translated
     * slug in that language, its default slug — e.g. /en/about resolves
     * to a page whose slug_translations.en is "about", and /en/sobre to
     * a page with no English slug. A page with a translated slug does
     * NOT answer at its default slug in that language (one URL per
     * language). When two pages land on the same path, the conflict is
     * decided level by level from the root: the page whose chain uses a
     * translated slug at the highest level wins (see preferred()).
     *
     * Returns ['key' => string, 'page' => array] or null.
     */
    public static function resolveByUrl(string $path, Config $config, ?string $language = null): ?array
    {
        $path = '/' . trim($path, '/');
        if ($path === '/') $path = '/';

        $defaultLang = $config->defaultLanguage();
        $useTranslations = $language !== null && $language !== $defaultLang;

        // 1. JSON-defined pages.
        //
        // Default language: match against the site.json `url` field
        // exactly as before.
        //
        // Non-default language: match the path built from each level's
        // slug in that language — fullPath() uses the translated slug, or
        // the default one for a level with no translation. A page with a
        // translated slug never answers at its default slug too, so each
        // language keeps a single canonical URL per page.
        //
        // Exception: the root URL "/" is universal — it always resolves
        // to the home page regardless of language, since there's no
        // meaningful translation of "/".
        //
        // A page apontada como 404 não tem URL própria — só aparece nos
        // misses (FrontRouter::renderNotFound). Vale mesmo se declararem
        // `url` nela por engano.
        $notFoundKey = $config->notFoundPageKey();
        // Idioma não-padrão: todas as páginas cujo path no idioma é o
        // pedido entram na disputa; preferred() escolhe a vencedora.
        $matches = [];

        foreach ($config->pages() as $key => $page) {
            $key = (string) $key;
            if ($key === $notFoundKey) {
                continue;
            }
            if (!$useTranslations) {
                if (self::fullPath($key, $config) === $path) {
                    return ['key' => $key, 'page' => $page];
                }
                continue;
            }

            // Idioma não-padrão: "/" é universal; senão casa a cadeia de
            // slugs no idioma (fullPath com language monta cada nível).
            if ($path === '/' && self::fullPath($key, $config) === '/') {
                return ['key' => $key, 'page' => $page];
            }
            if (self::fullPath($key, $config, $language) === $path) {
                $matches[] = ['key' => $key, 'page' => $page];
            }
        }

        // 2. Custom pages (DB). O ÚLTIMO segmento do path é o slug da
        //    página-alvo. Como o slug agora só é único ENTRE IRMÃOS, esse
        //    segmento pode casar mais de uma página (ex.: /a/contato e
        //    /b/contato); escolhemos a candidata cujo fullPath (cadeia de
        //    pais inclusa) bate exatamente com o path pedido — assim
        //    /pai-errado/slug ou /slug sem a cadeia caem em 404.
        if ($path === '/') return $useTranslations ? self::preferred($matches, $config, $language) : null;
        $segments = explode('/', trim($path, '/'));
        $lastSegment = (string) end($segments);

        if (!$useTranslations) {
            foreach (self::findAllBySlug($lastSegment) as $custom) {
                if ($custom->isCustom() && self::fullPath($custom->key, $config) === $path) {
                    return self::customMatch($custom, $config, $path);
                }
            }
            return null;
        }
        [$translated, $untranslated] = self::findAllBySlugIn($lastSegment, $language);
        foreach ([...$translated, ...$untranslated] as $custom) {
            if ($custom->isCustom() && self::fullPath($custom->key, $config, $language) === $path) {
                $matches[] = ['key' => $custom->key, 'custom' => $custom];
            }
        }
        $winner = self::preferred($matches, $config, $language);
        return isset($winner['custom']) ? self::customMatch($winner['custom'], $config, $path) : $winner;
    }

    /**
     * A página $key é quem responde pelo próprio endereço em $language? Num
     * conflito de slug, fullPath() ainda monta o endereço de quem perdeu, mas
     * nele abre a outra. Mesma regra de Item::ownsUrlIn() — toca o DB.
     */
    public static function ownsUrlIn(string $key, Config $config, string $language): bool
    {
        $resolved = self::resolveByUrl(self::fullPath($key, $config, $language), $config, $language);
        return ($resolved['key'] ?? null) === $key;
    }

    /**
     * Entre as páginas que dão o mesmo path num idioma não-padrão, a que
     * responde por ele: desce o path da raiz à folha e, no primeiro nível em
     * que as cadeias divergem, ganha a página cujo slug daquele nível é
     * traduzido — o mesmo que Item/Term::findByPath fazem descendo a
     * hierarquia. Empate: a primeira da lista (site.json antes do painel).
     *
     * @param list<array{key: string}> $matches
     */
    private static function preferred(array $matches, Config $config, string $language): ?array
    {
        $winner = null;
        $winnerLevels = [];
        foreach ($matches as $match) {
            $levels = self::translatedLevels($match['key'], $config, $language);
            if ($winner === null || self::translatedEarlier($levels, $winnerLevels)) {
                $winner = $match;
                $winnerLevels = $levels;
            }
        }
        return $winner;
    }

    /** $a tem slug traduzido num nível mais alto que $b (os dois da raiz à folha)? */
    private static function translatedEarlier(array $a, array $b): bool
    {
        foreach ($a as $i => $translated) {
            if ($translated !== ($b[$i] ?? false)) {
                return $translated;
            }
        }
        return false;
    }

    /**
     * Por nível do path da página, da raiz à folha (só os níveis que têm
     * segmento), se aquele nível usa um slug traduzido no idioma.
     *
     * @return list<bool>
     */
    private static function translatedLevels(string $key, Config $config, string $language): array
    {
        $levels = [];
        $seen = [];
        $current = $key;
        while ($current !== null && !isset($seen[$current]) && count($seen) < 25) {
            $seen[$current] = true;
            $level = self::level($current, $config, $language);
            if ($level === null) break;
            if ($level['segment'] !== '') {
                array_unshift($levels, $level['translated']);
            }
            $current = $level['parent'];
        }
        return $levels;
    }

    /** Uma custom page que casou o path, no formato de resolveByUrl(). */
    private static function customMatch(self $custom, Config $config, string $path): ?array
    {
        $tpl = $config->pageTemplate($custom->template ?? '');
        if ($tpl === null) {
            // Template removed from site.json — 404 on front
            // (admin still shows the page so it can be fixed).
            return null;
        }
        return [
            'key' => $custom->key,
            'page' => [
                'label' => $custom->title,
                'template' => (string) ($tpl['file'] ?? 'page.php'),
                'fields' => (array) ($tpl['fields'] ?? []),
                'url' => $path,
            ],
        ];
    }

    /**
     * As páginas que respondem por $slug em $language (não-padrão), em dois
     * grupos: [as que têm esse slug traduzido, as que têm esse slug padrão e
     * nenhum slug traduzido no idioma]. O primeiro grupo ganha um conflito
     * — mesma regra de TranslatedSlug.
     *
     * @return array{0: list<self>, 1: list<self>}
     */
    public static function findAllBySlugIn(string $slug, string $language): array
    {
        $db = App::instance()->db;
        $path = '$."' . $language . '"';
        $translated = $db->fetchAll(
            'SELECT * FROM pages WHERE JSON_UNQUOTE(JSON_EXTRACT(slug_translations, ?)) = ? AND NOT ' . TranslatedSlug::UNTRANSLATED,
            [$path, $slug, $path]
        );
        $untranslated = $db->fetchAll(
            'SELECT * FROM pages WHERE slug = ? AND ' . TranslatedSlug::UNTRANSLATED,
            [$slug, $path]
        );
        return [
            array_map(fn($r) => self::fromRow($r), $translated),
            array_map(fn($r) => self::fromRow($r), $untranslated),
        ];
    }

    /**
     * Translated slug for this page in the given language. Returns
     * null when the page has no slug_translations or no entry for
     * that specific language — the page then answers at its
     * default-language slug in that language.
     */
    public function slugTranslationFor(string $language): ?string
    {
        $value = $this->slugTranslations[$language] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Title for a specific language, with default-language fallback. */
    public function titleIn(string $language): string
    {
        $value = $this->titleTranslations[$language] ?? '';
        return $value !== '' ? $value : $this->title;
    }

    public function fieldIn(string $name, string $language, mixed $default = null): mixed
    {
        $translated = $this->fieldsTranslations[$language][$name] ?? null;
        // Um richtext sem nada escrito (`<p></p>`) conta como não traduzido:
        // o painel gravava isso para toda aba de tradução em branco.
        if ($translated !== null && $translated !== '' && !Richtext::isEmptyDocument($translated)) {
            return $translated;
        }
        return $this->fields[$name] ?? $default;
    }

    public function fieldsIn(string $language): array
    {
        $translated = $this->fieldsTranslations[$language] ?? [];
        if ($translated === []) return $this->fields;
        // Mesma regra de fieldIn(): um richtext sem nada escrito não cobre o
        // valor do idioma padrão.
        $translated = array_filter($translated, static fn($value) => !Richtext::isEmptyDocument($value));
        return array_merge($this->fields, $translated);
    }

    /**
     * Routing path (no install base prefix). Used by the front router to
     * compare against the incoming request.
     */
    public static function pagePath(string $key, array $page): string
    {
        $seg = self::localSegment($key, $page);
        return $seg === '' ? '/' : '/' . $seg;
    }

    /**
     * Segmento local de UMA página na URL (sem barras). '' para a home.
     * JSON pages usam o campo `url` (ou a key); o pedaço que a hierarquia
     * concatena para formar a URL completa via fullPath().
     */
    private static function localSegment(string $key, array $page): string
    {
        if (isset($page['url'])) {
            return trim((string) $page['url'], '/');
        }
        if ($key === 'home') {
            return '';
        }
        return trim($key, '/');
    }

    /**
     * URL de rota completa: combina os slugs da cadeia de pais (raiz →
     * folha). Núcleo puro em buildPath()/PathChain; aqui montamos o
     * resolvedor que lê JSON pages do Config e custom pages do DB.
     *
     * Idioma padrão (ou null): cada nível usa seu slug default, sem tocar
     * tradução.
     *
     * Idioma não-padrão: cada nível usa o seu slug traduzido, ou o default
     * quando aquele nível não tem tradução — a mesma regra que
     * resolveByUrl() casa. Um nível cujo segmento default é vazio (a home,
     * ou uma página com `"url": ""`) não tem slug para traduzir e continua
     * vazio; PathChain ignora segmentos vazios na montagem do path.
     */
    public static function fullPath(string $key, Config $config, ?string $language = null): string
    {
        return self::buildPath($key, fn(string $k): ?array => self::level($k, $config, $language));
    }

    /**
     * Um nível da cadeia de uma página: o segmento dela no idioma (o slug
     * traduzido, ou o default), a key do pai e se o segmento é traduzido.
     * Null quando a key não é página do site.json nem custom page.
     *
     * @return array{segment: string, parent: ?string, translated: bool}|null
     */
    private static function level(string $key, Config $config, ?string $language): ?array
    {
        $translating = $language !== null && $language !== $config->defaultLanguage();
        $jsonPage = $config->page($key);
        if ($jsonPage !== null) {
            $segment = self::localSegment($key, $jsonPage);
            $parent = isset($jsonPage['parent']) ? (string) $jsonPage['parent'] : null;
            $row = $translating && $segment !== '' ? self::findByKey($key) : null;
        } else {
            $row = self::findByKey($key);
            if ($row === null || !$row->isCustom()) return null;
            $segment = (string) ($row->slug ?? $row->key);
            $parent = $row->parentKey;
        }
        $t = $translating && $segment !== '' ? $row?->slugTranslationFor((string) $language) : null;
        return [
            'segment' => $t !== null ? trim($t, '/') : $segment,
            'parent' => $parent,
            'translated' => $t !== null,
        ];
    }

    /**
     * Public URL (includes the install base prefix), in the default
     * language. Use this in templates and admin links — never for routing
     * comparisons. A known page (site.json or created in the admin) gets
     * its full path, parents included; `$page` only matters for a key that
     * is neither.
     */
    public static function pageUrl(string $key, array $page): string
    {
        $app = App::instance();
        $base = $app->basePath;
        $known = $app->config->page($key) !== null || self::findByKey($key)?->isCustom() === true;
        $path = $known ? self::fullPath($key, $app->config) : self::pagePath($key, $page);
        if ($path === '/') {
            return $base === '' ? '/' : $base . '/';
        }
        return $base . $path;
    }

    /**
     * Monta um path de rota a partir de uma cadeia de segmentos, subindo
     * de filho para pai via a closure $resolve. Lógica pura (sem DB nem
     * Config) — o núcleo testável da hierarquia de subpáginas.
     *
     * $resolve(string $key): ?array — devolve
     *   ['segment' => string, 'parent' => ?string] para a página $key, ou
     *   null se ela não existe. `segment` é o pedaço local da URL (sem
     *   barras; '' para a home). `parent` é a key do pai, ou null na raiz.
     *
     * Guarda anti-ciclo: uma key já visitada encerra a subida; a
     * profundidade acima de $maxDepth idem. Degrada num path finito em
     * vez de travar num loop.
     */
    public static function buildPath(string $key, callable $resolve, int $maxDepth = 25): string
    {
        return \Velk\PathChain::build($key, $resolve, $maxDepth);
    }

    /**
     * Coleta as keys descendentes de $key (sem incluir $key), dado um
     * provedor de filhos $childrenOf(key): list<string>. Guarda anti-ciclo
     * (keys já vistas não reexpandem). Lógica pura — testável isolada.
     */
    public static function collectDescendants(string $key, callable $childrenOf): array
    {
        return \Velk\PathChain::descendants($key, $childrenOf);
    }

    /**
     * Keys descendentes de uma página, olhando filhos JSON (Config) e
     * custom (DB). Usada para barrar escolher um descendente como pai
     * e para filtrar o dropdown de "página pai" no admin.
     */
    public static function descendantKeys(string $key, Config $config): array
    {
        $childrenOf = function (string $k) use ($config): array {
            $children = [];
            foreach ($config->pages() as $pk => $pdef) {
                if ((($pdef['parent'] ?? null)) === $k) {
                    $children[] = (string) $pk;
                }
            }
            foreach (self::childrenInDb($k) as $ck) {
                $children[] = $ck;
            }
            return $children;
        };
        return self::collectDescendants($key, $childrenOf);
    }

    /** page_keys das custom pages cujo parent_key é $parentKey. */
    private static function childrenInDb(string $parentKey): array
    {
        $rows = App::instance()->db->fetchAll(
            'SELECT page_key FROM pages WHERE parent_key = ? AND template IS NOT NULL',
            [$parentKey]
        );
        return array_map(fn($r) => (string) $r['page_key'], $rows);
    }

    /**
     * Valor de um campo NO IDIOMA ATIVO da requisição.
     *
     * Delega para `fieldIn()`, que já cai no valor do idioma padrão quando
     * não há tradução — então num site mono-idioma, ou num campo que ninguém
     * traduz, o resultado é idêntico ao de ler `$this->fields` direto.
     *
     * Resolver o idioma AQUI é o que permite escrever um tema só com
     * objetos, sem `with_context()`. O acoplamento a `App::instance()` já é
     * o padrão desta classe.
     */
    public function field(string $name, mixed $default = null): mixed
    {
        return $this->fieldIn($name, App::instance()->i18n->current(), $default);
    }

    /**
     * URL pública desta página, no idioma ativo.
     *
     * Não existia: `the_url()` tratava Item e Term e devolvia '/' para Page,
     * e quem montava URL de página usava o estático `fullPath($key, $config)`.
     * Isso deixava a forma orientada a objeto incompleta — dava para escrever
     * um tema só com objetos até precisar linkar uma página.
     *
     * Sem slug traduzido no idioma ativo, o link usa o slug padrão — o
     * mesmo endereço em que o roteador responde.
     */
    public function url(): string
    {
        $app = App::instance();
        $lang = $app->i18n->current();
        // \url() cuida do base path E do prefixo de idioma — o mesmo que
        // Item::url() usa. Concatenar basePath à mão perderia o /en, /es.
        return \url(self::fullPath($this->key, $app->config, $lang), $lang);
    }

    /** Título no idioma ativo — par de `field()` para a forma orientada a objeto. */
    public function title(): string
    {
        return $this->titleIn(App::instance()->i18n->current());
    }

    /**
     * Upsert a JSON-defined page (template + slug stay NULL).
     * Called by the Installer when syncing site.json into the DB.
     */
    public static function upsert(string $key, string $title, array $fields): self
    {
        $db = App::instance()->db;
        $existing = self::findByKey($key);
        if ($existing !== null) {
            $db->update('pages', [
                'title' => $title,
                'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ], ['id' => $existing->id]);
            return self::findByKey($key) ?? throw new \RuntimeException('Failed to update page.');
        }
        $db->insert('pages', [
            'page_key' => $key,
            'title' => $title,
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
        ]);
        return self::findByKey($key) ?? throw new \RuntimeException('Failed to create page.');
    }

    /**
     * Create a custom page (admin-created). `page_key` is a STABLE, globally
     * unique identity generated from the slug with a collision suffix — it is
     * decoupled from the slug (which is only unique among siblings) and never
     * changes when the slug is later renamed via saveCustom().
     */
    public static function createCustom(string $slug, string $title, string $templateKey, array $fields = [], ?string $parentKey = null): self
    {
        // page_key é a identidade estável e ÚNICA — desacoplada do slug (que
        // agora só é único entre irmãos). Gera com sufixo -2, -3… em colisão.
        $pageKey = self::uniquePageKey($slug);
        App::instance()->db->insert('pages', [
            'page_key' => $pageKey,
            'title' => $title,
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            'template' => $templateKey,
            'slug' => $slug,
            'parent_key' => $parentKey,
        ]);
        return self::findByKey($pageKey) ?? throw new \RuntimeException('Failed to create custom page.');
    }

    /**
     * page_key único e estável a partir de uma base (o slug inicial). Evita
     * colisão com outras page_keys (DB), com keys de páginas JSON, e com as
     * rotas reservadas do admin (new/delete). Sufixo -2, -3… em colisão. Não
     * muda depois — renomear o slug não altera o page_key.
     */
    private static function uniquePageKey(string $base): string
    {
        $reserved = ['new', 'delete'];
        $config = App::instance()->config;
        $root = $base !== '' ? $base : 'pagina';
        $key = $root;
        $n = 1;
        while (
            in_array($key, $reserved, true)
            || self::findByKey($key) !== null
            || $config->page($key) !== null
        ) {
            $n++;
            $key = $root . '-' . $n;
        }
        return $key;
    }

    /**
     * Update an existing custom page. Title, slug, template, parent, and
     * fields can all change. `page_key` stays FIXED — it is the stable
     * identity, so renaming the slug does not touch it (nor the admin URL).
     *
     * Optional i18n keys passed in $extra: title_translations,
     * slug_translations, fields_translations — each a map keyed by
     * language code. Pass null/empty to leave existing translations
     * untouched, [] to clear.
     */
    public function saveCustom(string $title, string $slug, string $templateKey, array $fields, ?string $parentKey = null, array $extra = []): self
    {
        if (!$this->isCustom()) {
            throw new \RuntimeException('saveCustom() called on a non-custom page.');
        }
        // page_key NÃO muda — é a identidade estável. Só slug/título/pai/etc.
        $update = [
            'title' => $title,
            'slug' => $slug,
            'template' => $templateKey,
            'parent_key' => $parentKey,
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
        ];
        self::applyTranslationKeys($update, $extra);
        App::instance()->db->update('pages', $update, ['id' => $this->id]);
        return self::findByKey($this->key) ?? throw new \RuntimeException('Failed to update custom page.');
    }

    /**
     * Update translation columns on the page row WITHOUT changing
     * the default-language data. Used by the JSON-page editor (where
     * title/url stay in site.json) to save admin-edited translations.
     */
    public function saveTranslations(array $titleTranslations, array $slugTranslations, array $fieldsTranslations): void
    {
        $update = [];
        self::applyTranslationKeys($update, [
            'title_translations'  => $titleTranslations,
            'slug_translations'   => $slugTranslations,
            'fields_translations' => $fieldsTranslations,
        ]);
        if ($update === []) return;
        App::instance()->db->update('pages', $update, ['id' => $this->id]);
    }

    /**
     * Helper: copy *_translations keys from $source into $update,
     * encoding arrays as JSON or NULL when empty. Shared by upsert
     * and saveCustom so the encoding logic lives in one place.
     */
    private static function applyTranslationKeys(array &$update, array $source): void
    {
        foreach (['title_translations', 'slug_translations', 'fields_translations'] as $key) {
            if (!array_key_exists($key, $source)) continue;
            $value = $source[$key];
            $update[$key] = $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE);
        }
    }

    public function delete(): void
    {
        if (!$this->isCustom()) {
            throw new \RuntimeException('Cannot delete a JSON-defined page from the DB.');
        }
        App::instance()->db->delete('pages', ['id' => $this->id]);
    }
}
