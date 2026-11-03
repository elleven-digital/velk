<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;
use Velk\Config;
use Velk\PathChain;
use Velk\Richtext;
use Velk\TranslatedSlug;

final class Term
{
    public int $id;
    public string $taxonomy;
    public string $slug;
    public ?string $template = null;
    public string $name;
    public ?int $parentId = null;
    public array $fields = [];

    // i18n Phase 3 — per-language overrides. Default-language values
    // stay in the columns above.
    /** @var array<string, string> */
    public array $nameTranslations = [];
    /** @var array<string, string> */
    public array $slugTranslations = [];
    /** @var array<string, array<string, mixed>> */
    public array $fieldsTranslations = [];

    public static function fromRow(array $row): self
    {
        $t = new self();
        $t->id = (int) $row['id'];
        $t->taxonomy = (string) $row['taxonomy'];
        $t->slug = (string) $row['slug'];
        $t->template = isset($row['template']) ? (string) $row['template'] : null;
        $t->name = (string) $row['name'];
        $t->parentId = isset($row['parent_id']) ? (int) $row['parent_id'] : null;
        $t->fields = is_string($row['fields'] ?? null) ? (array) (json_decode($row['fields'], true) ?? []) : [];

        $t->nameTranslations = self::decodeJson($row['name_translations'] ?? null);
        $t->slugTranslations = self::decodeJson($row['slug_translations'] ?? null);
        $t->fieldsTranslations = self::decodeJson($row['fields_translations'] ?? null);

        return $t;
    }

    private static function decodeJson(mixed $value): array
    {
        if (!is_string($value) || $value === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM terms WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findBySlug(string $taxonomy, string $slug): ?self
    {
        $row = App::instance()->db->fetch(
            'SELECT * FROM terms WHERE taxonomy = ? AND slug = ? LIMIT 1',
            [$taxonomy, $slug]
        );
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Find a term by its slug in a specific language. Same rule as
     * Item::findBySlugIn: the translated slug, or the default slug of a
     * term with no translation in that language (TranslatedSlug).
     */
    public static function findBySlugIn(string $taxonomy, string $slug, string $language, string $defaultLanguage): ?self
    {
        if ($language === $defaultLanguage) {
            return self::findBySlug($taxonomy, $slug);
        }
        $row = TranslatedSlug::fetch('terms', 'taxonomy = ?', [$taxonomy], $slug, $language);
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
    public static function findByPath(string $taxonomy, array $segments, string $language, string $defaultLanguage): ?self
    {
        $row = TranslatedSlug::descend('terms', 'taxonomy = ?', [$taxonomy], array_values(array_map('strval', $segments)), $language, $defaultLanguage);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Filho direto de um termo por (taxonomia, parent_id, slug). Usado na
     * resolução de URL aninhada em taxonomias hierárquicas — como o slug só
     * é único ENTRE IRMÃOS, casar o pai por nível é o que distingue ramos
     * com o mesmo slug de folha (/docs/guia-a/intro vs /docs/guia-b/intro).
     * $parentId null casa termos-raiz. Idioma não-padrão casa o slug traduzido,
     * ou o padrão de quem não tem tradução (TranslatedSlug).
     */
    public static function findChild(string $taxonomy, ?int $parentId, string $slug, string $language, string $defaultLanguage): ?self
    {
        $parentCond = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';
        if ($language === $defaultLanguage) {
            $sql = "SELECT * FROM terms WHERE taxonomy = ? AND {$parentCond} AND slug = ? LIMIT 1";
            $params = $parentId === null ? [$taxonomy, $slug] : [$taxonomy, $parentId, $slug];
            $row = App::instance()->db->fetch($sql, $params);
        } else {
            $row = TranslatedSlug::fetch('terms', "taxonomy = ? AND {$parentCond}",
                $parentId === null ? [$taxonomy] : [$taxonomy, $parentId], $slug, $language);
        }
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * A taxonomia declarou ordem manual no site.json?
     */
    public static function manualOrder(string $taxonomy): bool
    {
        $config = App::instance()->config->taxonomy($taxonomy);

        return ($config['manual_order'] ?? false) === true;
    }

    /**
     * Reordena os ids recebidos entre si, dentro da taxonomia. Ver ManualOrder.
     *
     * @param list<int|string> $ids ids na nova ordem visual
     */
    public static function applyOrder(string $taxonomy, array $ids): void
    {
        ManualOrder::apply(App::instance()->db, 'terms', 'taxonomy', $taxonomy, $ids);
    }

    /**
     * Materializa a ordem visível em sort_order sempre que a taxonomia tem
     * posição repetida — não só uma vez na vida da taxonomia: a detecção em
     * ManualOrder::ensureSeeded é "existe posição repetida no escopo", que
     * pode disparar de novo depois da primeira semeadura (ver o docblock lá).
     *
     * A semente é `name ASC`, que é a ordem padrão de termos.
     */
    public static function ensureOrderSeeded(string $taxonomy): void
    {
        ManualOrder::ensureSeeded(
            App::instance()->db,
            'terms',
            'taxonomy',
            $taxonomy,
            'name ASC'
        );
    }

    /** A posição de um termo novo: no fim da lista da taxonomia. */
    public static function nextSortOrder(string $taxonomy): int
    {
        return ManualOrder::next(App::instance()->db, 'terms', 'taxonomy', $taxonomy);
    }

    /**
     * @param int $limit 0 = sem limite (compat com chamadas existentes)
     * @return list<self>
     */
    public static function all(string $taxonomy, int $limit = 0, int $offset = 0): array
    {
        // Painel e site chamam este mesmo método, então a flag vale para os
        // dois de uma vez. Com a flag, `name ASC` desempata `sort_order` —
        // mas `name` não é único (dois termos podem ter o mesmo nome), então
        // um segundo empate ficaria em ordem indefinida no MySQL. `id ASC`
        // no fim fecha isso, igual ao que Item já faz com `id`. Sem a flag,
        // o ramo continua exatamente `name ASC` — intocado.
        $sql = 'SELECT * FROM terms WHERE taxonomy = ? ORDER BY '
            . (self::manualOrder($taxonomy) ? 'sort_order ASC, name ASC, id ASC' : 'name ASC');
        if ($limit > 0) {
            $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        }
        $rows = App::instance()->db->fetchAll($sql, [$taxonomy]);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function count(string $taxonomy): int
    {
        return (int) App::instance()->db->fetchColumn(
            'SELECT COUNT(*) FROM terms WHERE taxonomy = ?',
            [$taxonomy]
        );
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

    /** Nome no idioma ativo — par de `field()` para a forma orientada a objeto. */
    public function name(): string
    {
        return $this->nameIn(App::instance()->i18n->current());
    }

    /** Name (label) in a specific language with default fallback. */
    public function nameIn(string $language): string
    {
        $value = $this->nameTranslations[$language] ?? '';
        return $value !== '' ? $value : $this->name;
    }

    public function slugIn(string $language): string
    {
        $value = $this->slugTranslations[$language] ?? '';
        return $value !== '' ? $value : $this->slug;
    }

    public function hasSlugIn(string $language): bool
    {
        return isset($this->slugTranslations[$language]) && $this->slugTranslations[$language] !== '';
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

    public function url(): string
    {
        $app = App::instance();

        // has_page: false marks the taxonomy as embed-only — terms
        // have no public URL. Theme code that calls url() gets empty
        // string back so `<a href="">` never appears in the DOM.
        $taxDef = $app->config->taxonomy($this->taxonomy);
        if ($taxDef === null || ($taxDef['has_page'] ?? true) === false) {
            return '';
        }

        $lang = $app->config->isI18nEnabled() ? $app->i18n->current() : $app->config->defaultLanguage();

        // Hierárquica: a URL é a cadeia de pais (prefixo da taxonomia +
        // slug de cada nível). Flat: /taxonomia/slug direto.
        if ($app->config->isTaxonomyHierarchical($this->taxonomy)) {
            return \url(self::fullPath($this, $app->config, $lang), $lang);
        }

        $prefix = $app->config->taxonomySlugIn($this->taxonomy, $lang);
        $slug = $lang === $app->config->defaultLanguage() ? $this->slug : $this->slugIn($lang);
        return \url('/' . trim($prefix, '/') . '/' . $slug, $lang);
    }

    /**
     * Este termo é quem responde pelo próprio endereço em $language? Mesma
     * regra de Item::ownsUrlIn() — toca o DB.
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
        if ($config->isTaxonomyHierarchical($this->taxonomy)) {
            $prefix = '/' . trim($config->taxonomySlugIn($this->taxonomy, $language), '/');
            $rest = substr(self::fullPath($this, $config, $language), strlen($prefix));
            return self::findByPath($this->taxonomy, explode('/', trim($rest, '/')), $language, $default);
        }
        $slug = $language === $default ? $this->slug : $this->slugIn($language);
        return self::findBySlugIn($this->taxonomy, $slug, $language, $default);
    }

    /**
     * URL completa do termo: prefixo da taxonomia + a cadeia de slugs dos
     * pais (raiz → folha). Só faz sentido em taxonomias hierárquicas; para
     * flat, o caller usa /taxonomia/slug direto.
     *
     * Cada nível usa o seu slug no idioma: o traduzido, ou o padrão
     * quando aquele nível não tem tradução — mesma regra de Item::fullPath.
     */
    public static function fullPath(self $term, Config $config, ?string $language = null): string
    {
        $language ??= $config->defaultLanguage();
        $translated = $language !== $config->defaultLanguage();
        $taxPrefix = '/' . trim($config->taxonomySlugIn($term->taxonomy, $language), '/');

        $cache = [(string) $term->id => $term];
        $resolve = function (string $idStr) use (&$cache, $language, $translated): ?array {
            if (!array_key_exists($idStr, $cache)) {
                $cache[$idStr] = self::find((int) $idStr);
            }
            $t = $cache[$idStr];
            if ($t === null) return null;
            $parent = $t->parentId !== null ? (string) $t->parentId : null;
            return ['segment' => $translated ? $t->slugIn($language) : $t->slug, 'parent' => $parent];
        };

        return $taxPrefix . PathChain::build((string) $term->id, $resolve);
    }

    /** @return list<int> ids de todos os descendentes (qualquer profundidade). */
    public static function descendantIds(int $id): array
    {
        $childrenOf = fn(string $k): array => array_map('strval', self::childrenIds((int) $k));
        return array_map('intval', PathChain::descendants((string) $id, $childrenOf));
    }

    /** @return list<int> ids dos filhos diretos. */
    public static function childrenIds(int $parentId): array
    {
        $rows = App::instance()->db->fetchAll('SELECT id FROM terms WHERE parent_id = ?', [$parentId]);
        return array_map(fn($r) => (int) $r['id'], $rows);
    }

    public static function create(array $data): self
    {
        $id = App::instance()->db->insert('terms', [
            'taxonomy' => $data['taxonomy'],
            'slug' => $data['slug'],
            'name' => $data['name'],
            'parent_id' => $data['parent_id'] ?? null,
            'fields' => json_encode($data['fields'] ?? [], JSON_UNESCAPED_UNICODE),
            'name_translations' => !empty($data['name_translations']) ? json_encode($data['name_translations'], JSON_UNESCAPED_UNICODE) : null,
            'slug_translations' => !empty($data['slug_translations']) ? json_encode($data['slug_translations'], JSON_UNESCAPED_UNICODE) : null,
            'fields_translations' => !empty($data['fields_translations']) ? json_encode($data['fields_translations'], JSON_UNESCAPED_UNICODE) : null,
            'template' => $data['template'] ?? null,
            // Ver o comentário equivalente em Item::create — sem repassar,
            // um termo novo criado após a semeadura ficaria travado no
            // DEFAULT 0 da coluna e nunca seria corrigido por ensureSeeded.
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
        return self::find($id) ?? throw new \RuntimeException('Failed to create term.');
    }

    public function save(array $data): void
    {
        $update = [];
        if (array_key_exists('name', $data)) $update['name'] = $data['name'];
        if (array_key_exists('slug', $data)) $update['slug'] = $data['slug'];
        if (array_key_exists('parent_id', $data)) $update['parent_id'] = $data['parent_id'];
        if (array_key_exists('fields', $data)) $update['fields'] = json_encode($data['fields'], JSON_UNESCAPED_UNICODE);
        if (array_key_exists('template', $data)) $update['template'] = $data['template'];

        if (array_key_exists('name_translations', $data)) {
            $update['name_translations'] = $data['name_translations'] === []
                ? null
                : json_encode($data['name_translations'], JSON_UNESCAPED_UNICODE);
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
            App::instance()->db->update('terms', $update, ['id' => $this->id]);
        }
    }

    public function delete(): void
    {
        App::instance()->db->delete('terms', ['id' => $this->id]);
    }
}
