<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;
use Velk\Config;
use Velk\PathChain;
use Velk\Richtext;
use Velk\TranslatedSlug;

final class Item
{
    public int $id;
    public string $type;
    public string $slug;
    /** ID do item pai (mesmo tipo); NULL = item-raiz. Itens hierárquicos. */
    public ?int $parentId = null;
    public string $title;
    public ?string $template = null;
    public string $status = 'draft';
    public array $fields = [];
    public ?string $publishedAt = null;
    public string $createdAt;
    public string $updatedAt;

    // i18n Phase 3 — per-language overrides. Empty arrays when the
    // item has no translations. Default-language values stay in the
    // `title`/`slug`/`fields` columns above (zero-impact on existing
    // queries and code).
    /** @var array<string, string> */
    public array $titleTranslations = [];
    /** @var array<string, string> */
    public array $slugTranslations = [];
    /** @var array<string, array<string, mixed>> */
    public array $fieldsTranslations = [];

    public static function fromRow(array $row): self
    {
        $i = new self();
        $i->id = (int) $row['id'];
        $i->type = (string) $row['type'];
        $i->slug = (string) $row['slug'];
        $i->parentId = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $i->title = (string) $row['title'];
        $i->template = $row['template'] !== null ? (string) $row['template'] : null;
        $i->status = (string) $row['status'];
        $i->fields = is_string($row['fields'] ?? null) ? (array) (json_decode($row['fields'], true) ?? []) : [];
        $i->publishedAt = $row['published_at'] ?? null;
        $i->createdAt = (string) $row['created_at'];
        $i->updatedAt = (string) $row['updated_at'];

        $i->titleTranslations = self::decodeJson($row['title_translations'] ?? null);
        $i->slugTranslations  = self::decodeJson($row['slug_translations'] ?? null);
        $i->fieldsTranslations = self::decodeJson($row['fields_translations'] ?? null);

        return $i;
    }

    private static function decodeJson(mixed $value): array
    {
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM items WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findBySlug(string $type, string $slug): ?self
    {
        $row = App::instance()->db->fetch(
            'SELECT * FROM items WHERE type = ? AND slug = ? LIMIT 1',
            [$type, $slug]
        );
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Find an item by its slug in a specific language. When the
     * language is the site default, this is just a regular findBySlug
     * against the `slug` column. For other languages, it matches the
     * translated slug (`slug_translations.<lang>`) or, for an item with
     * no translated slug in that language, the default slug — the
     * translated one wins a conflict. See TranslatedSlug.
     */
    public static function findBySlugIn(string $type, string $slug, string $language, string $defaultLanguage): ?self
    {
        if ($language === $defaultLanguage) {
            return self::findBySlug($type, $slug);
        }
        $row = TranslatedSlug::fetch('items', 'type = ?', [$type], $slug, $language, publishedFirst: true);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * O registro no fim de um caminho hierárquico (os segmentos depois do
     * prefixo, da raiz à folha), no idioma — ou null. Num conflito, o slug
     * traduzido ganha cada nível, sem esconder a filha que só existe no
     * outro ramo (TranslatedSlug::descend).
     *
     * @param list<string> $segments
     */
    public static function findByPath(string $type, array $segments, string $language, string $defaultLanguage): ?self
    {
        $row = TranslatedSlug::descend('items', 'type = ?', [$type], array_values(array_map('strval', $segments)), $language, $defaultLanguage, publishedFirst: true);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * O item do tipo com aquele slug sob aquele pai (parentId NULL = raiz).
     * Usado pela resolução hierárquica, que desce a cadeia segmento a
     * segmento — como slugs agora só são únicos entre irmãos, casar (pai,
     * slug) por nível é o que distingue /docs/guia-a/intro de
     * /docs/guia-b/intro. Idioma não-padrão casa o slug traduzido, ou o padrão
     * de quem não tem tradução (TranslatedSlug).
     */
    public static function findChild(string $type, ?int $parentId, string $slug, string $language, string $defaultLanguage): ?self
    {
        $parentCond = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';
        if ($language === $defaultLanguage) {
            $sql = "SELECT * FROM items WHERE type = ? AND {$parentCond} AND slug = ? LIMIT 1";
            $params = $parentId === null ? [$type, $slug] : [$type, $parentId, $slug];
            $row = App::instance()->db->fetch($sql, $params);
        } else {
            $row = TranslatedSlug::fetch('items', "type = ? AND {$parentCond}",
                $parentId === null ? [$type] : [$type, $parentId], $slug, $language, publishedFirst: true);
        }
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * @return list<self>
     */
    public static function published(string $type, int $limit = 100): array
    {
        $rows = App::instance()->db->fetchAll(
            'SELECT * FROM items WHERE type = ? AND status = ? ORDER BY published_at DESC, created_at DESC LIMIT ' . (int) $limit,
            [$type, 'published']
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Whitelist of columns that may appear in `order` clauses passed to
     * query(). Anything else is silently dropped — keeps SQL safe even if
     * `order` ever ends up populated from user input (today it's only set
     * by theme code, but we don't want a footgun).
     */
    private const SORTABLE_COLUMNS = [
        'id', 'slug', 'title', 'status',
        'published_at', 'created_at', 'updated_at',
        // Entra mesmo em tipo sem `manual_order`: a coluna existe em todos, e
        // um tema pode ter razão para pedi-la explicitamente.
        'sort_order',
    ];

    /**
     * Parse a free-form ORDER BY string ("col1 ASC, col2 DESC") into a safe
     * SQL fragment using the column whitelist. Returns the default when the
     * input is empty or every clause fails validation.
     */
    private static function sanitizeOrder(string $order, string $default): string
    {
        $clauses = array_filter(array_map('trim', explode(',', $order)));
        $safe = [];
        foreach ($clauses as $clause) {
            $parts = preg_split('/\s+/', $clause) ?: [];
            $col = strtolower((string) ($parts[0] ?? ''));
            $dir = strtoupper((string) ($parts[1] ?? 'ASC'));
            if (!in_array($col, self::SORTABLE_COLUMNS, true)) continue;
            if ($dir !== 'ASC' && $dir !== 'DESC') $dir = 'ASC';
            $safe[] = "{$col} {$dir}";
        }
        return $safe === [] ? $default : implode(', ', $safe);
    }

    /**
     * O tipo declarou ordem manual no site.json?
     *
     * Tipo inexistente devolve false — o chamador não precisa checar antes.
     */
    public static function manualOrder(string $type): bool
    {
        $config = App::instance()->config->itemType($type);

        return ($config['manual_order'] ?? false) === true;
    }

    /**
     * Reordena os ids recebidos entre si, dentro do tipo. Ver ManualOrder.
     *
     * @param list<int|string> $ids ids na nova ordem visual
     */
    public static function applyOrder(string $type, array $ids): void
    {
        ManualOrder::apply(App::instance()->db, 'items', 'type', $type, $ids);
    }

    /**
     * Materializa a ordem visível em sort_order sempre que o tipo tem
     * posição repetida — não só uma vez na vida do tipo: a detecção em
     * ManualOrder::ensureSeeded é "existe posição repetida no escopo", que
     * pode disparar de novo depois da primeira semeadura (ver o docblock lá).
     *
     * A semente é a ordem PADRÃO de items, não `id`. Isso alinha o PAINEL
     * com o site assim que este método roda — mas quem garante que ligar a
     * flag não reordena o site NO ATO, antes deste método rodar pela
     * primeira vez, é o fallback dentro do próprio ORDER BY de query() e
     * byTerm() (sort_order ASC, <ordem padrão>, id ASC): com tudo em
     * sort_order=0 a cauda decide sozinha, então a leitura já sai idêntica à
     * de antes da flag mesmo sem nenhuma semeadura ter acontecido ainda.
     */
    public static function ensureOrderSeeded(string $type): void
    {
        ManualOrder::ensureSeeded(
            App::instance()->db,
            'items',
            'type',
            $type,
            'published_at DESC, created_at DESC'
        );
    }

    /** A posição de um item novo: no fim da lista do tipo. */
    public static function nextSortOrder(string $type): int
    {
        return ManualOrder::next(App::instance()->db, 'items', 'type', $type);
    }

    /**
     * @param array{limit?:int, offset?:int, status?:string, term?:int, order?:string} $args
     * @return list<self>
     */
    public static function query(string $type, array $args = []): array
    {
        $db = App::instance()->db;
        // Cap limit/offset to sensible bounds — defends against accidental
        // huge queries and cleanly casts non-numeric input to 0/100.
        $limit = max(0, min(1000, (int) ($args['limit'] ?? 100)));
        $offset = max(0, (int) ($args['offset'] ?? 0));
        $status = (string) ($args['status'] ?? 'published');
        // `published_at DESC, created_at DESC` antes do `id ASC`: é a ordem
        // PADRÃO de items (a mesma do ramo sem a flag, logo abaixo). Com
        // tudo em sort_order=0 — a janela entre ligar a flag e a primeira
        // abertura da listagem do painel, que é quem semeia — essa cauda
        // decide sozinha e a leitura do site sai idêntica à de antes da
        // flag; depois da semeadura os valores de sort_order são distintos
        // e ela nunca entra. `id ASC` no fim continua sendo o desempate
        // estável final: sem ele, duas linhas que ainda colidirem em tudo o
        // resto saem em ordem indefinida e a lista "pula" entre carregamentos.
        $order = self::sanitizeOrder(
            (string) ($args['order'] ?? ''),
            self::manualOrder($type)
                ? 'sort_order ASC, published_at DESC, created_at DESC, id ASC'
                : 'published_at DESC, created_at DESC'
        );

        $params = [$type];
        $where = ['type = ?'];

        if ($status !== 'any') {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        if (isset($args['term'])) {
            $where[] = 'id IN (SELECT item_id FROM item_term WHERE term_id = ?)';
            $params[] = (int) $args['term'];
        }

        // $order, $limit, $offset are now sanitized — safe to interpolate.
        // (MySQL prepared statements don't bind LIMIT/OFFSET on every driver
        // setup, so we cast and inline rather than parameterize them.)
        $sql = sprintf(
            'SELECT * FROM items WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
            implode(' AND ', $where),
            $order,
            $limit,
            $offset
        );

        $rows = $db->fetchAll($sql, $params);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * @return list<self>
     */
    public static function byTerm(int $termId, int $limit = 100): array
    {
        // Mesma cauda de fallback de query() (ver o comentário lá): com tudo
        // em sort_order=0 — antes da primeira semeadura do tipo declarante —
        // `published_at DESC, created_at DESC` decide sozinha e a ordem sai
        // idêntica à de antes da flag; `id ASC` no fim é só o desempate final.
        $order = self::byTermManualOrder($termId)
            ? 'i.sort_order ASC, i.published_at DESC, i.created_at DESC, i.id ASC'
            : 'i.published_at DESC, i.created_at DESC';

        $rows = App::instance()->db->fetchAll(
            'SELECT i.* FROM items i INNER JOIN item_term it ON it.item_id = i.id
             WHERE it.term_id = ? AND i.status = ?
             ORDER BY ' . $order . ' LIMIT ' . (int) $limit,
            [$termId, 'published']
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * `byTerm` recebe um id de TERMO, não de tipo — e uma taxonomia pode ser
     * declarada por vários tipos de item ao mesmo tempo, cada um com sua
     * própria sequência de `sort_order` (que começa em 1 a cada tipo). Se
     * dois tipos compartilham a taxonomia, ordenar por `sort_order`
     * intercalaria as duas sequências independentes — o item 1 do tipo A e
     * o item 1 do tipo B empatariam, e o resultado não corresponderia à
     * ordem que ninguém de fato arrastou. Por isso a ordem manual só entra
     * aqui quando EXATAMENTE UM tipo declara a taxonomia do termo, e esse
     * tipo tem `manual_order` ligada; qualquer outro caso — dois ou mais
     * tipos, tipo sem a flag, termo inexistente — cai no padrão por data.
     */
    private static function byTermManualOrder(int $termId): bool
    {
        // Guarda pura-PHP, ANTES de tocar o banco: a regra abaixo só pode dar
        // true quando o tipo declarante da taxonomia tem `manual_order`. Se
        // nenhum item type do site.json tem a flag, o resultado já está
        // decidido — e a query de baixo (SELECT taxonomy FROM terms) seria
        // desperdiçada em todo site sem a flag, em toda página de taxonomia.
        $algumTipoComFlag = false;
        foreach (App::instance()->config->itemTypes() as $def) {
            if (($def['manual_order'] ?? false) === true) {
                $algumTipoComFlag = true;
                break;
            }
        }
        if (!$algumTipoComFlag) {
            return false;
        }

        $taxonomy = App::instance()->db->fetchColumn(
            'SELECT taxonomy FROM terms WHERE id = ? LIMIT 1',
            [$termId]
        );
        if (!is_string($taxonomy) || $taxonomy === '') {
            return false;
        }

        $types = [];
        foreach (App::instance()->config->itemTypes() as $type => $def) {
            if (in_array($taxonomy, (array) ($def['taxonomies'] ?? []), true)) {
                $types[] = (string) $type;
            }
        }

        return count($types) === 1 && self::manualOrder($types[0]);
    }

    /**
     * WHERE da listagem/contagem do admin — compartilhado entre listAdmin
     * e countAdmin pra nunca divergirem. Pura — testável isolada.
     *
     * @return array{0: string, 1: array}
     */
    public static function adminWhere(string $type, ?string $status, ?string $search): array
    {
        $params = [$type];
        $where = ['type = ?'];
        if ($status !== null && $status !== '' && $status !== 'all') {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        $search = $search !== null ? trim($search) : '';
        if ($search !== '') {
            $where[] = '(title LIKE ? OR slug LIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
        }
        return [implode(' AND ', $where), $params];
    }

    /** @param int $limit 0 = sem limite (compat com chamadas existentes) */
    public static function listAdmin(string $type, ?string $status = null, ?string $search = null, int $limit = 0, int $offset = 0): array
    {
        [$where, $params] = self::adminWhere($type, $status, $search);
        // A listagem do painel PRECISA seguir a mesma ordem do site quando o
        // tipo é de ordem manual — é o que a pessoa arrasta. `created_at
        // DESC` antes do `id ASC`: é a ordem PADRÃO do painel (o ramo sem a
        // flag, à direita). Com tudo em sort_order=0 — antes da primeira
        // abertura desta listagem, que é quem semeia — essa cauda decide
        // sozinha e o painel sai idêntico ao de antes da flag; depois da
        // semeadura ela nunca entra, porque os valores já são distintos.
        $sql = 'SELECT * FROM items WHERE ' . $where . ' ORDER BY '
            . (self::manualOrder($type) ? 'sort_order ASC, created_at DESC, id ASC' : 'created_at DESC');
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $rows = App::instance()->db->fetchAll($sql, $params);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function countAdmin(string $type, ?string $status = null, ?string $search = null): int
    {
        [$where, $params] = self::adminWhere($type, $status, $search);
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(*) FROM items WHERE ' . $where,
            $params
        );
    }

    /**
     * Title in a specific language. Falls back to the default-language
     * value (column `title`) when no translation exists for the
     * requested language. Callers pass the request's active language;
     * the default-language case is the hot path (no JSON lookup).
     */
    public function titleIn(string $language): string
    {
        $value = $this->titleTranslations[$language] ?? '';
        return $value !== '' ? $value : $this->title;
    }

    /**
     * Slug in a specific language. Same fallback rule as titleIn — and
     * the same rule the router follows: without a translated slug, the
     * item answers in that language at its default slug.
     */
    public function slugIn(string $language): string
    {
        $value = $this->slugTranslations[$language] ?? '';
        return $value !== '' ? $value : $this->slug;
    }

    public function hasSlugIn(string $language): bool
    {
        return isset($this->slugTranslations[$language]) && $this->slugTranslations[$language] !== '';
    }

    /**
     * Get a single field value in a specific language. Returns the
     * translated value when present, otherwise falls back to the
     * default-language value, otherwise the supplied default.
     */
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

    /**
     * Whole fields array for one language. Default-language values
     * are merged underneath so missing translations gracefully fall
     * back. The merge is shallow — repeaters and flexible blocks
     * fall back as a whole, not per-row.
     */
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
     * Default-language field lookup. Theme code that wants the
     * translated value should use `field()` helper (which respects
     * the active language) instead of calling this directly.
     */
    /**
     * Valor de um campo NO IDIOMA ATIVO da requisição.
     *
     * Delega para `fieldIn()`, que já cai no valor do idioma padrão quando
     * não há tradução — então num site mono-idioma, ou num campo que ninguém
     * traduz (preço, por exemplo), o resultado é idêntico ao de ler
     * `$this->fields` direto.
     *
     * Resolver o idioma AQUI, e não só no helper global, é o que permite
     * escrever um tema inteiro com objetos, sem `with_context()`. O
     * acoplamento a `App::instance()` já é o padrão desta classe — `url()`
     * faz o mesmo para alcançar a config.
     */
    public function field(string $name, mixed $default = null): mixed
    {
        return $this->fieldIn($name, App::instance()->i18n->current(), $default);
    }

    /**
     * Título no idioma ativo. Mesma razão de existir que `field()`: a forma
     * orientada a objeto precisa resolver idioma sozinha para ser uma
     * alternativa completa ao contexto ambiente.
     */
    public function title(): string
    {
        return $this->titleIn(\Velk\App::instance()->i18n->current());
    }


    public function url(): string
    {
        $config = App::instance()->config;
        $type = $config->itemType($this->type);
        // Embed-only types (has_page: false) have no public URL — return
        // empty so templates that accidentally call url() don't render
        // broken `<a href="">` chains. Callers should check before linking.
        if (($type['has_page'] ?? true) === false) {
            return '';
        }

        $app = App::instance();
        $lang = $app->config->isI18nEnabled() ? $app->i18n->current() : $app->config->defaultLanguage();

        // Tipos hierárquicos: a URL combina a cadeia de slugs dos pais.
        // fullPath já monta o prefixo do tipo e a cadeia no idioma.
        if ($config->isItemTypeHierarchical($this->type)) {
            return \url(self::fullPath($this, $config, $lang), $lang);
        }

        // Type prefix in current language (default-language: same as
        // site.json `slug`; non-default: from config_translations).
        $typePrefix = $app->config->itemTypeSlugIn($this->type, $lang);
        $slug = $lang === $app->config->defaultLanguage() ? $this->slug : $this->slugIn($lang);

        // url() helper handles base path + language URL prefix.
        return \url('/' . trim($typePrefix, '/') . '/' . $slug, $lang);
    }

    /**
     * Este item é quem responde pelo próprio endereço em $language? Num
     * conflito de slug (o slug padrão dele é o slug traduzido de outro, ver
     * TranslatedSlug), url() ainda monta o endereço de quem perdeu, mas nele
     * abre o outro. Refaz a busca do roteador: toca o DB — para o seletor de
     * idioma e o sitemap, não para cada link de uma listagem.
     */
    public function ownsUrlIn(string $language): bool
    {
        return $this->urlOwnerIn($language)?->id === $this->id;
    }

    /** Quem abre no endereço deste registro em $language — ele mesmo, outro (disputa de slug) ou ninguém. */
    public function urlOwnerIn(string $language): ?self
    {
        $config = App::instance()->config;
        $default = $config->defaultLanguage();
        if ($config->isItemTypeHierarchical($this->type)) {
            $prefix = '/' . trim($config->itemTypeSlugIn($this->type, $language), '/');
            $rest = substr(self::fullPath($this, $config, $language), strlen($prefix));
            return self::findByPath($this->type, explode('/', trim($rest, '/')), $language, $default);
        }
        $slug = $language === $default ? $this->slug : $this->slugIn($language);
        return self::findBySlugIn($this->type, $slug, $language, $default);
    }

    /**
     * URL de rota completa de um item hierárquico: o prefixo do tipo mais a
     * cadeia de slugs dos itens (raiz → folha). Sem o language prefix nem o
     * base path — o helper url() os aplica. Toca o DB (sobe a cadeia de
     * parent_id, com cache do próprio item no primeiro nível).
     *
     * Cada nível usa o seu slug no idioma: o traduzido, ou o padrão
     * quando aquele nível não tem tradução — a mesma regra que
     * Item::findChild segue ao descer a cadeia.
     */
    public static function fullPath(self $item, Config $config, ?string $language = null): string
    {
        $language ??= $config->defaultLanguage();
        $translated = $language !== $config->defaultLanguage();
        $typePrefix = '/' . trim($config->itemTypeSlugIn($item->type, $language), '/');

        $cache = [(string) $item->id => $item];
        $resolve = function (string $idStr) use (&$cache, $language, $translated): ?array {
            if (!array_key_exists($idStr, $cache)) {
                $cache[$idStr] = self::find((int) $idStr);
            }
            $it = $cache[$idStr];
            if ($it === null) return null;
            $parent = $it->parentId !== null ? (string) $it->parentId : null;
            return ['segment' => $translated ? $it->slugIn($language) : $it->slug, 'parent' => $parent];
        };

        return $typePrefix . PathChain::build((string) $item->id, $resolve);
    }

    /** IDs descendentes deste item (BFS via parent_id), sem incluir $id. */
    public static function descendantIds(int $id): array
    {
        $childrenOf = fn(string $k): array => array_map('strval', self::childrenIds((int) $k));
        return array_map('intval', PathChain::descendants((string) $id, $childrenOf));
    }

    /** IDs dos filhos diretos (itens cujo parent_id é $parentId). */
    public static function childrenIds(int $parentId): array
    {
        $rows = App::instance()->db->fetchAll('SELECT id FROM items WHERE parent_id = ?', [$parentId]);
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    /**
     * @return list<Term>
     */
    public function terms(?string $taxonomy = null): array
    {
        $sql = 'SELECT t.* FROM terms t INNER JOIN item_term it ON it.term_id = t.id WHERE it.item_id = ?';
        $params = [$this->id];
        if ($taxonomy !== null) {
            $sql .= ' AND t.taxonomy = ?';
            $params[] = $taxonomy;
        }
        $sql .= ' ORDER BY t.name ASC';
        $rows = App::instance()->db->fetchAll($sql, $params);
        return array_map(fn($r) => Term::fromRow($r), $rows);
    }

    public function setTerms(string $taxonomy, array $termIds): void
    {
        $db = App::instance()->db;
        $db->transaction(function ($db) use ($taxonomy, $termIds) {
            $db->query(
                'DELETE it FROM item_term it INNER JOIN terms t ON t.id = it.term_id
                 WHERE it.item_id = ? AND t.taxonomy = ?',
                [$this->id, $taxonomy]
            );
            foreach ($termIds as $termId) {
                $db->query(
                    'INSERT IGNORE INTO item_term (item_id, term_id) VALUES (?, ?)',
                    [$this->id, (int) $termId]
                );
            }
        });
    }

    public static function create(array $data): self
    {
        $payload = [
            'type' => $data['type'],
            'slug' => $data['slug'],
            'parent_id' => $data['parent_id'] ?? null,
            'title' => $data['title'],
            'template' => $data['template'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'fields' => json_encode($data['fields'] ?? [], JSON_UNESCAPED_UNICODE),
            'published_at' => $data['published_at'] ?? null,
            // Repassa o sort_order que o controller já calculou (via
            // nextSortOrder) para tipo de ordem manual. Sem isso a coluna
            // ficaria no DEFAULT 0 da migration — e um item novo criado
            // depois da semeadura (já em 1..N distintos) empataria em 0 e
            // ensureSeeded() não teria motivo para corrigir, porque a
            // contagem de distintos continuaria batendo com o total.
            'sort_order' => $data['sort_order'] ?? 0,
        ];
        // i18n Phase 3 — as traduções que o painel já traz no primeiro save
        // (abas preenchidas antes de o item existir). Mesma regra do save()
        // e de Term::create: vazio vira NULL. Sem a chave no array — o
        // item:create do CLI — a coluna nem entra no INSERT.
        foreach (['title_translations', 'slug_translations', 'fields_translations'] as $column) {
            if (array_key_exists($column, $data)) {
                $payload[$column] = !empty($data[$column])
                    ? json_encode($data[$column], JSON_UNESCAPED_UNICODE)
                    : null;
            }
        }
        $id = App::instance()->db->insert('items', $payload);
        return self::find($id) ?? throw new \RuntimeException('Failed to create item.');
    }

    public function save(array $data): void
    {
        $update = [];
        if (array_key_exists('title', $data)) $update['title'] = $data['title'];
        if (array_key_exists('slug', $data)) $update['slug'] = $data['slug'];
        if (array_key_exists('parent_id', $data)) $update['parent_id'] = $data['parent_id'];
        if (array_key_exists('template', $data)) $update['template'] = $data['template'];
        if (array_key_exists('status', $data)) $update['status'] = $data['status'];
        if (array_key_exists('fields', $data)) $update['fields'] = json_encode($data['fields'], JSON_UNESCAPED_UNICODE);
        if (array_key_exists('published_at', $data)) $update['published_at'] = $data['published_at'];

        // i18n Phase 3 — per-language column updates. Callers pass
        // empty arrays to clear translations (translates as NULL in
        // DB) or full maps to replace.
        if (array_key_exists('title_translations', $data)) {
            $update['title_translations'] = $data['title_translations'] === []
                ? null
                : json_encode($data['title_translations'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('slug_translations', $data)) {
            $update['slug_translations'] = $data['slug_translations'] === []
                ? null
                : json_encode($data['slug_translations'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('fields_translations', $data)) {
            $update['fields_translations'] = $data['fields_translations'] === []
                ? null
                : json_encode($data['fields_translations'], JSON_UNESCAPED_UNICODE);
        }

        if ($update !== []) {
            App::instance()->db->update('items', $update, ['id' => $this->id]);
        }
    }

    public function delete(): void
    {
        App::instance()->db->delete('items', ['id' => $this->id]);
    }
}
