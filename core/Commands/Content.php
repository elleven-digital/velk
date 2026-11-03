<?php

declare(strict_types=1);

namespace Velk\Commands;

use Velk\App;
use Velk\Models\Item;
use Velk\Models\Page;
use Velk\RichtextBlocks;

/**
 * CLI handlers for content:* commands.
 *
 * These exist to give external tools (the velk:ssh-content skill, scripts,
 * import jobs) a stable, scriptable interface to read and write content
 * without driving the admin web UI. They reuse the same Models, Config,
 * and validation paths the admin already uses, so behavior stays consistent.
 *
 * Output format:
 *   Every method takes $format ('text'|'json'). 'text' is human-friendly for
 *   one-off CLI inspection. 'json' is the machine-readable wire format —
 *   one JSON object on stdout, no decorations, no log spam — designed for
 *   parsing by callers.
 *
 * On error: 'text' prints to stderr and exits non-zero; 'json' emits
 *   {"ok": false, "error": "...", "details": [...]} on stdout and exits 0,
 *   so callers can parse errors uniformly.
 */
final class Content
{
    // ─────────────────────────────────────────────────────────────────────
    // Public entrypoints — items
    // ─────────────────────────────────────────────────────────────────────

    public static function itemTypes(App $app, string $format): void
    {
        $types = $app->config->itemTypes();
        $rows = [];
        foreach ($types as $type => $def) {
            $rows[] = [
                'type'     => (string) $type,
                'label'    => (string) ($def['label'] ?? $type),
                'has_page' => (bool) ($def['has_page'] ?? true),
                'slug'     => (string) ($def['slug'] ?? $type),
                'template' => $def['template'] ?? null,
            ];
        }
        self::emit($format, ['ok' => true, 'types' => $rows], function () use ($rows) {
            if ($rows === []) { echo "No item types defined in site.json.\n"; return; }
            foreach ($rows as $r) {
                printf("%-20s %s%s\n", $r['type'], $r['label'], $r['has_page'] ? '' : '  (embed-only)');
            }
        });
    }

    public static function itemSchema(App $app, string $type, string $format): void
    {
        $def = $app->config->itemType($type);
        if ($def === null) {
            self::fail($format, "Unknown item type: {$type}", self::knownTypes($app));
            return;
        }
        $fields = self::expandFields($app, (array) ($def['fields'] ?? []), $def['has_page'] ?? true);
        self::emit($format, [
            'ok'       => true,
            'type'     => $type,
            'label'    => (string) ($def['label'] ?? $type),
            'has_page' => (bool) ($def['has_page'] ?? true),
            'slug'     => (string) ($def['slug'] ?? $type),
            'fields'   => $fields,
        ], function () use ($type, $def, $fields) {
            echo "Type: {$type}  ({$def['label']})\n";
            echo "Fields:\n";
            foreach ($fields as $f) {
                $req = !empty($f['required']) ? ' (required)' : '';
                $label = (string) ($f['label'] ?? $f['name']);
                printf("  %-25s %-12s %s%s\n", $f['name'], $f['type'], $label, $req);
            }
        });
    }

    public static function itemList(
        App $app,
        string $type,
        ?string $status,
        ?string $search,
        int $limit,
        string $format
    ): void {
        if ($app->config->itemType($type) === null) {
            self::fail($format, "Unknown item type: {$type}", self::knownTypes($app));
            return;
        }
        $items = Item::listAdmin($type, $status, $search);
        // Cap the list locally; we don't need SQL-level limits here (and we don't
        // want to break its signature for a CLI nicety).
        $items = array_slice($items, 0, max(1, $limit));

        $rows = array_map(fn(Item $i) => [
            'id'           => $i->id,
            'slug'         => $i->slug,
            'title'        => $i->title,
            'status'       => $i->status,
            'published_at' => $i->publishedAt,
            'updated_at'   => $i->updatedAt,
        ], $items);

        self::emit($format, ['ok' => true, 'type' => $type, 'count' => count($rows), 'items' => $rows], function () use ($rows) {
            if ($rows === []) { echo "No items.\n"; return; }
            printf("%-6s %-30s %-12s %s\n", 'ID', 'SLUG', 'STATUS', 'TITLE');
            foreach ($rows as $r) {
                printf("%-6d %-30s %-12s %s\n", $r['id'], $r['slug'], $r['status'], $r['title']);
            }
        });
    }

    public static function itemGet(App $app, string $type, string $slugOrId, string $format): void
    {
        $item = self::resolveItem($type, $slugOrId);
        if ($item === null) {
            self::fail($format, "Item not found: {$type}/{$slugOrId}");
            return;
        }
        $payload = self::serializeItem($item);
        self::emit($format, ['ok' => true, 'item' => $payload], function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        });
    }

    public static function itemCreate(
        App $app,
        string $type,
        string $jsonInput,
        bool $dryRun,
        string $format
    ): void {
        $def = $app->config->itemType($type);
        if ($def === null) {
            self::fail($format, "Unknown item type: {$type}", self::knownTypes($app));
            return;
        }
        $input = self::parseJson($jsonInput, $format);
        if ($input === null) return;

        // Required: title (always). Slug auto-generated if missing.
        if (!isset($input['title']) || trim((string) $input['title']) === '') {
            self::fail($format, "Field 'title' is required to create an item.");
            return;
        }
        $title = (string) $input['title'];
        $slug  = isset($input['slug']) && trim((string) $input['slug']) !== ''
            ? self::slugify((string) $input['slug'])
            : self::slugify($title);

        // Slug uniqueness within type
        if (Item::findBySlug($type, $slug) !== null) {
            self::fail($format, "Slug already exists for type '{$type}': {$slug}. Pass a different 'slug' in the JSON.");
            return;
        }

        $status = (string) ($input['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published'], true)) {
            self::fail($format, "Invalid status '{$status}'. Use 'draft' or 'published'.");
            return;
        }

        // Validate fields against schema. Unknown fields are kept (forward-
        // compat with site.json evolution); known fields get type-checked.
        $schemaFields = self::expandFields($app, (array) ($def['fields'] ?? []), $def['has_page'] ?? true);
        $rawFields    = (array) ($input['fields'] ?? []);
        $errors       = [...self::validateFields($schemaFields, $rawFields), ...self::blockMarkerErrors($rawFields)];
        if ($errors !== []) {
            self::fail($format, "Validation failed", $errors);
            return;
        }

        if ($dryRun) {
            self::emit($format, [
                'ok'       => true,
                'dry_run'  => true,
                'preview'  => [
                    'type'   => $type,
                    'slug'   => $slug,
                    'title'  => $title,
                    'status' => $status,
                    'fields' => $rawFields,
                ],
            ], function () use ($type, $slug, $title, $status) {
                echo "[dry-run] Would create {$type}/{$slug}  title='{$title}'  status={$status}\n";
            });
            return;
        }

        $itemData = [
            'type'     => $type,
            'slug'     => $slug,
            'title'    => $title,
            'template' => $input['template'] ?? null,
            'status'   => $status,
            'fields'   => $rawFields,
            'published_at' => $status === 'published' ? date('Y-m-d H:i:s') : null,
        ];
        // Item novo entra no fim da lista quando o tipo é de ordem manual —
        // mesmo tratamento do painel (ItemController::save). Sem a flag, não
        // passamos a chave: o `?? 0` de Item::create cuida, e o comportamento
        // fica byte a byte igual ao de antes desta mudança.
        if (Item::manualOrder($type)) {
            $itemData['sort_order'] = Item::nextSortOrder($type);
        }

        $item = Item::create($itemData);

        $payload = self::serializeItem($item);
        self::emit($format, ['ok' => true, 'item' => $payload], function () use ($item) {
            echo "Created {$item->type}/{$item->slug}  (id={$item->id})\n";
        });
    }

    public static function itemUpdate(
        App $app,
        string $type,
        string $slugOrId,
        string $jsonInput,
        bool $dryRun,
        string $format
    ): void {
        $def = $app->config->itemType($type);
        if ($def === null) {
            self::fail($format, "Unknown item type: {$type}", self::knownTypes($app));
            return;
        }
        $item = self::resolveItem($type, $slugOrId);
        if ($item === null) {
            self::fail($format, "Item not found: {$type}/{$slugOrId}");
            return;
        }
        $input = self::parseJson($jsonInput, $format);
        if ($input === null) return;

        // Partial update — every key is optional. Validate types if provided.
        $update = [];

        if (array_key_exists('title', $input)) {
            $update['title'] = (string) $input['title'];
        }
        if (array_key_exists('slug', $input)) {
            $newSlug = self::slugify((string) $input['slug']);
            // Don't conflict with another item's slug.
            $other = Item::findBySlug($type, $newSlug);
            if ($other !== null && $other->id !== $item->id) {
                self::fail($format, "Slug already in use by another item: {$newSlug}");
                return;
            }
            $update['slug'] = $newSlug;
        }
        if (array_key_exists('template', $input)) {
            $update['template'] = $input['template'] !== null ? (string) $input['template'] : null;
        }
        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (!in_array($status, ['draft', 'published'], true)) {
                self::fail($format, "Invalid status '{$status}'. Use 'draft' or 'published'.");
                return;
            }
            $update['status'] = $status;
            // First publish: stamp published_at. Re-publish keeps the original.
            if ($status === 'published' && $item->publishedAt === null) {
                $update['published_at'] = date('Y-m-d H:i:s');
            }
        }
        if (array_key_exists('fields', $input)) {
            // Field updates merge with existing — partial field updates are
            // common ("just change the body"). To replace the whole fields
            // map, callers should pass the complete object.
            $schemaFields = self::expandFields($app, (array) ($def['fields'] ?? []), $def['has_page'] ?? true);
            $newFields    = array_merge($item->fields, (array) $input['fields']);
            $errors       = [...self::validateFields($schemaFields, $newFields), ...self::blockMarkerErrors((array) $input['fields'])];
            if ($errors !== []) {
                self::fail($format, "Validation failed", $errors);
                return;
            }
            $update['fields'] = $newFields;
        }

        if ($update === []) {
            self::fail($format, "Nothing to update — input had no recognized keys.");
            return;
        }

        if ($dryRun) {
            self::emit($format, [
                'ok'       => true,
                'dry_run'  => true,
                'preview'  => $update,
            ], function () use ($item, $update) {
                echo "[dry-run] Would update {$item->type}/{$item->slug}: " . json_encode(array_keys($update)) . "\n";
            });
            return;
        }

        $item->save($update);
        $fresh = Item::find($item->id);
        $payload = $fresh !== null ? self::serializeItem($fresh) : null;
        self::emit($format, ['ok' => true, 'item' => $payload], function () use ($item) {
            echo "Updated {$item->type}/{$item->slug}  (id={$item->id})\n";
        });
    }

    public static function itemPublish(App $app, string $type, string $slugOrId, string $format): void
    {
        // Implemented as an itemUpdate with status=published. Keeping a thin
        // wrapper so the verb maps directly to the conceptual action and the
        // skill doesn't have to construct status-update JSON for the common
        // case.
        self::itemUpdate($app, $type, $slugOrId, json_encode(['status' => 'published']), false, $format);
    }

    public static function itemUnpublish(App $app, string $type, string $slugOrId, string $format): void
    {
        self::itemUpdate($app, $type, $slugOrId, json_encode(['status' => 'draft']), false, $format);
    }

    public static function itemDelete(
        App $app,
        string $type,
        string $slugOrId,
        bool $confirmed,
        string $format
    ): void {
        $item = self::resolveItem($type, $slugOrId);
        if ($item === null) {
            self::fail($format, "Item not found: {$type}/{$slugOrId}");
            return;
        }
        if (!$confirmed) {
            self::fail(
                $format,
                "Refusing to delete without confirmation. Pass --confirm to proceed.",
                ['hint' => 'This is destructive — the item is removed from the items table.']
            );
            return;
        }
        $snapshot = self::serializeItem($item);
        $item->delete();
        self::emit($format, ['ok' => true, 'deleted' => $snapshot], function () use ($snapshot) {
            echo "Deleted {$snapshot['type']}/{$snapshot['slug']}  (id={$snapshot['id']})\n";
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Public entrypoints — pages
    // ─────────────────────────────────────────────────────────────────────

    public static function pageList(App $app, string $format): void
    {
        $configured = $app->config->pages();
        $pages = Page::all();
        $byKey = [];
        foreach ($pages as $p) { $byKey[$p->key] = $p; }

        $rows = [];
        foreach ($configured as $key => $def) {
            $existing = $byKey[(string) $key] ?? null;
            $rows[] = [
                'key'        => (string) $key,
                'label'      => (string) ($def['label'] ?? $key),
                'template'   => (string) ($def['template'] ?? ''),
                'in_db'      => $existing !== null,
                'updated_at' => $existing?->updatedAt,
            ];
        }
        self::emit($format, ['ok' => true, 'pages' => $rows], function () use ($rows) {
            if ($rows === []) { echo "No pages defined in site.json.\n"; return; }
            printf("%-15s %-30s %-10s %s\n", 'KEY', 'LABEL', 'IN DB', 'TEMPLATE');
            foreach ($rows as $r) {
                printf("%-15s %-30s %-10s %s\n",
                    $r['key'], $r['label'], $r['in_db'] ? 'yes' : 'no', $r['template']);
            }
        });
    }

    public static function pageSchema(App $app, string $key, string $format): void
    {
        $def = $app->config->page($key);
        if ($def === null) {
            self::fail($format, "Unknown page key: {$key}", self::knownPages($app));
            return;
        }
        // Pages always have a public URL → SEO fields auto-attached.
        $fields = self::expandFields($app, (array) ($def['fields'] ?? []), true);
        self::emit($format, [
            'ok'       => true,
            'key'      => $key,
            'label'    => (string) ($def['label'] ?? $key),
            'template' => (string) ($def['template'] ?? ''),
            'fields'   => $fields,
        ], function () use ($key, $def, $fields) {
            echo "Page: {$key}  ({$def['label']})\n";
            echo "Fields:\n";
            foreach ($fields as $f) {
                $label = (string) ($f['label'] ?? $f['name']);
                printf("  %-25s %-12s %s\n", $f['name'], $f['type'], $label);
            }
        });
    }

    public static function pageGet(App $app, string $key, string $format): void
    {
        $def = $app->config->page($key);
        if ($def === null) {
            self::fail($format, "Unknown page key: {$key}", self::knownPages($app));
            return;
        }
        $page = Page::findByKey($key);
        $payload = [
            'key'        => $key,
            'label'      => (string) ($def['label'] ?? $key),
            'template'   => (string) ($def['template'] ?? ''),
            'in_db'      => $page !== null,
            'fields'     => $page?->fields ?? [],
            'updated_at' => $page?->updatedAt,
        ];
        self::emit($format, ['ok' => true, 'page' => $payload], function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        });
    }

    public static function pageUpdate(
        App $app,
        string $key,
        string $jsonInput,
        bool $dryRun,
        string $format
    ): void {
        $def = $app->config->page($key);
        if ($def === null) {
            self::fail($format, "Unknown page key: {$key}", self::knownPages($app));
            return;
        }
        $input = self::parseJson($jsonInput, $format);
        if ($input === null) return;

        $newFields = (array) ($input['fields'] ?? []);
        if ($newFields === [] && !array_key_exists('title', $input)) {
            self::fail($format, "Nothing to update — pass {\"fields\": {...}} or {\"title\": \"...\"}.");
            return;
        }

        $schemaFields = self::expandFields($app, (array) ($def['fields'] ?? []), true);
        $existing     = Page::findByKey($key);
        $merged       = array_merge($existing?->fields ?? [], $newFields);
        $errors       = [...self::validateFields($schemaFields, $merged), ...self::blockMarkerErrors($newFields)];
        if ($errors !== []) {
            self::fail($format, "Validation failed", $errors);
            return;
        }

        $title = (string) ($input['title'] ?? $existing?->title ?? ($def['label'] ?? $key));

        if ($dryRun) {
            self::emit($format, [
                'ok'      => true,
                'dry_run' => true,
                'preview' => ['key' => $key, 'title' => $title, 'fields_changed' => array_keys($newFields)],
            ], function () use ($key, $newFields) {
                echo "[dry-run] Would update page '{$key}': fields=" . json_encode(array_keys($newFields)) . "\n";
            });
            return;
        }

        Page::upsert($key, $title, $merged);
        $fresh = Page::findByKey($key);
        self::emit($format, ['ok' => true, 'page' => [
            'key'        => $key,
            'title'      => $fresh?->title,
            'fields'     => $fresh?->fields ?? [],
            'updated_at' => $fresh?->updatedAt,
        ]], function () use ($key) {
            echo "Updated page '{$key}'.\n";
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Resolve a slug-or-numeric-id reference to an Item. Numeric inputs are
     * treated as IDs; everything else as a slug. This is convenient for the
     * CLI ("velk content:get posts 42" or "velk content:get posts hello-world")
     * but it does mean a slug like "42" would be mistaken for an ID — site
     * conventions discourage purely-numeric slugs anyway.
     */
    private static function resolveItem(string $type, string $slugOrId): ?Item
    {
        if (ctype_digit($slugOrId)) {
            $found = Item::find((int) $slugOrId);
            if ($found !== null && $found->type === $type) return $found;
        }
        return Item::findBySlug($type, $slugOrId);
    }

    /**
     * @return array<string,mixed>
     */
    private static function serializeItem(Item $i): array
    {
        return [
            'id'           => $i->id,
            'type'         => $i->type,
            'slug'         => $i->slug,
            'title'        => $i->title,
            'template'     => $i->template,
            'status'       => $i->status,
            'fields'       => $i->fields,
            'published_at' => $i->publishedAt,
            'created_at'   => $i->createdAt,
            'updated_at'   => $i->updatedAt,
        ];
    }

    /**
     * Resolve {group: "X"} placeholders against site.json field_groups, then
     * (for content with public pages) append the SEO field set. This mirrors
     * what the admin does when rendering forms.
     *
     * @return list<array<string,mixed>>
     */
    private static function expandFields(App $app, array $fields, bool $hasPage): array
    {
        $resolved = $app->config->resolveFields($fields);
        if ($hasPage) {
            $resolved = array_merge($resolved, $app->config->seoFields());
        }
        return array_values($resolved);
    }

    /**
     * Sanity-check field values against the resolved schema. Returns a list
     * of error strings (empty = OK).
     *
     * Validation philosophy: we're permissive on purpose. The admin enforces
     * tighter rules at form-render time; this layer is for programmatic
     * writes where the caller already knows the schema. We catch the bigs
     * (missing required, blatantly wrong types) and let everything else
     * through. Unknown keys are allowed so site.json can grow without
     * breaking integrations.
     */
    private static function validateFields(array $schema, array $values): array
    {
        $errors = [];
        $byName = [];
        foreach ($schema as $field) {
            if (!isset($field['name'])) continue;
            $byName[(string) $field['name']] = $field;
        }

        // Required check
        foreach ($byName as $name => $field) {
            if (!empty($field['required']) && (!array_key_exists($name, $values) || self::isEmptyValue($values[$name], (string) ($field['type'] ?? 'text')))) {
                $errors[] = "Field '{$name}' is required.";
            }
        }

        // Type sanity check
        foreach ($values as $name => $value) {
            $field = $byName[(string) $name] ?? null;
            if ($field === null) continue; // unknown fields allowed
            $type = (string) ($field['type'] ?? 'text');
            if (!self::valueMatchesType($value, $type)) {
                $errors[] = "Field '{$name}' has wrong type for '{$type}'.";
            }
        }
        return $errors;
    }

    /**
     * Marcadores de bloco que o editor do painel não conseguiria mostrar (ver
     * RichtextBlocks::markerProblems), em qualquer texto do que chegou —
     * inclusive dentro de repetidores e layouts. Só o que chega: um marcador
     * antigo já gravado não trava a atualização de outro campo.
     *
     * @return list<string>
     */
    private static function blockMarkerErrors(array $incoming): array
    {
        $errors = [];
        foreach ($incoming as $name => $value) {
            $texts = [];
            $wrapped = [$value];
            array_walk_recursive($wrapped, static function ($leaf) use (&$texts): void {
                if (is_string($leaf) && str_contains($leaf, 'data-velk-block')) $texts[] = $leaf;
            });
            foreach ($texts as $html) {
                foreach (RichtextBlocks::markerProblems($html) as $problem) {
                    $errors[] = "Field '{$name}': {$problem}";
                }
            }
        }
        return $errors;
    }

    private static function valueMatchesType(mixed $value, string $type): bool
    {
        // Null is always allowed (means "not set").
        if ($value === null) return true;
        return match ($type) {
            'text', 'textarea', 'richtext', 'url', 'email', 'select', 'color' => is_string($value),
            // I15: image/file hold a media id — not a URL. Accept the positive
            // int (JSON number, e.g. {"thumbnail":12}), the positive numeric
            // string (how the admin form stores a picked media) and the
            // field's own "no media" (see isEmptyValue).
            'image', 'file' => self::isEmptyValue($value, $type)
                || (is_int($value) && $value > 0)
                || (is_string($value) && ctype_digit($value)),
            'number'  => is_numeric($value),
            'boolean', 'checkbox', 'toggle' => is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1',
            'repeater', 'list', 'gallery', 'flexible' => is_array($value),
            default   => true, // unknown types: don't reject
        };
    }

    /**
     * "Not set", per field type. The admin saves whatever its form posts,
     * unvalidated, and the updates validate the stored fields together with
     * the incoming ones — so this has to recognize every empty the admin
     * writes. For image/file that is "0" (media never picked: the hidden
     * input is rendered with value="0") and "" (right after the Remove
     * button), not just null. Zero is only empty there: a text "0" or a
     * number 0 is content.
     */
    private static function isEmptyValue(mixed $value, string $type): bool
    {
        if ($value === null || $value === '') return true;
        return in_array($type, ['image', 'file'], true) && ($value === '0' || $value === 0);
    }

    private static function slugify(string $s): string
    {
        $s = trim($s);
        // Transliterate accents → ASCII when iconv is available; fall back to raw.
        if (function_exists('iconv')) {
            $tx = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($tx !== false) $s = $tx;
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s === '' ? 'item-' . substr((string) time(), -6) : $s;
    }

    /**
     * Decode a JSON input string. On failure, emit error and return null
     * (callers should `return` immediately when null comes back).
     */
    private static function parseJson(string $json, string $format): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            self::fail($format, "Invalid JSON input: " . (json_last_error_msg() ?: 'expected an object'));
            return null;
        }
        return $decoded;
    }

    private static function knownTypes(App $app): array
    {
        return ['known_types' => array_keys($app->config->itemTypes())];
    }

    private static function knownPages(App $app): array
    {
        return ['known_pages' => array_keys($app->config->pages())];
    }

    /**
     * Emit success output. JSON path emits the structured payload; text path
     * runs the provided closure for human-friendly output.
     */
    private static function emit(string $format, array $jsonPayload, callable $textRenderer): void
    {
        if ($format === 'json') {
            echo json_encode($jsonPayload, JSON_UNESCAPED_UNICODE) . "\n";
            return;
        }
        $textRenderer();
    }

    /**
     * Emit failure. Text path: stderr + exit(1). JSON path: stdout error
     * payload + exit(0) — that lets callers parse uniformly without
     * special-casing exit codes (the `ok` flag IS the signal).
     */
    private static function fail(string $format, string $message, array $details = []): void
    {
        if ($format === 'json') {
            $payload = ['ok' => false, 'error' => $message];
            if ($details !== []) $payload['details'] = $details;
            echo json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
            exit(0);
        }
        fwrite(STDERR, "Error: {$message}\n");
        if ($details !== []) {
            fwrite(STDERR, json_encode($details, JSON_PRETTY_PRINT) . "\n");
        }
        exit(1);
    }
}
