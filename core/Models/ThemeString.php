<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * A translatable string catalog entry. One row per unique source
 * string + context pair. The `translations` column is a JSON map of
 * language code → translated value.
 *
 * Why include the original in the table (not just a key):
 *   1. Admin UI shows the original next to the translation field —
 *      no second lookup needed.
 *   2. Source-string drift detection: when the theme changes a string
 *      from "Read more" to "Continue reading", the new string gets a
 *      new key_hash and is registered fresh. The old row still has
 *      its (now stale) translations — admin can spot orphans.
 *
 * key_hash is sha256(original + "\0" + context). Context lets two
 * uses of the same English word translate differently when meaning
 * differs ("Post" as noun vs verb, etc.).
 */
final class ThemeString
{
    public int $id;
    public string $keyHash;
    public string $original;
    public ?string $context = null;
    /** @var array<string,string> */
    public array $translations = [];
    public string $createdAt;
    public ?string $updatedAt = null;

    public static function fromRow(array $row): self
    {
        $s = new self();
        $s->id = (int) $row['id'];
        $s->keyHash = (string) $row['key_hash'];
        $s->original = (string) $row['original'];
        $s->context = $row['context'] !== null ? (string) $row['context'] : null;
        $s->translations = is_string($row['translations'] ?? null)
            ? (array) (json_decode($row['translations'], true) ?? [])
            : [];
        $s->createdAt = (string) $row['created_at'];
        $s->updatedAt = $row['updated_at'] ?? null;
        return $s;
    }

    public static function makeKey(string $original, ?string $context = null): string
    {
        return hash('sha256', $original . "\0" . ($context ?? ''));
    }

    public static function findByKey(string $keyHash): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM theme_strings WHERE key_hash = ? LIMIT 1', [$keyHash]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM theme_strings WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        $rows = App::instance()->db->fetchAll('SELECT * FROM theme_strings ORDER BY original ASC');
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Bulk-load translations for one language into an associative
     * array keyed by key_hash. Used at request start to populate the
     * in-memory cache so subsequent `t()` lookups are O(1).
     *
     * @return array<string,string>
     */
    public static function loadCatalog(string $languageCode): array
    {
        $rows = App::instance()->db->fetchAll(
            "SELECT key_hash, JSON_UNQUOTE(JSON_EXTRACT(translations, ?)) AS translation
             FROM theme_strings",
            ['$."' . $languageCode . '"']
        );
        $catalog = [];
        foreach ($rows as $row) {
            $t = $row['translation'] ?? null;
            // JSON_EXTRACT returns the string 'null' (literal) when the
            // key is absent — drop those so they don't shadow the
            // original via cache hit + empty value.
            if ($t !== null && $t !== '' && $t !== 'null') {
                $catalog[$row['key_hash']] = (string) $t;
            }
        }
        return $catalog;
    }

    /**
     * Register a source string if not already present. The caller
     * (admin "Scan theme" action, or `bin/velk i18n:scan` command)
     * passes strings discovered in the codebase; runtime `t()` calls
     * do NOT auto-register — user explicitly opted out of that.
     */
    public static function register(string $original, ?string $context = null): self
    {
        $keyHash = self::makeKey($original, $context);
        $existing = self::findByKey($keyHash);
        if ($existing !== null) return $existing;

        $id = App::instance()->db->insert('theme_strings', [
            'key_hash' => $keyHash,
            'original' => $original,
            'context' => $context,
            'translations' => '{}',
        ]);
        return self::find($id) ?? throw new \RuntimeException('Failed to register theme string.');
    }

    /**
     * Set or update one language's translation. Empty string deletes
     * the translation entry (so re-displaying the original after
     * blanking is straightforward).
     */
    public function setTranslation(string $languageCode, string $translation): void
    {
        $translations = $this->translations;
        if ($translation === '') {
            unset($translations[$languageCode]);
        } else {
            $translations[$languageCode] = $translation;
        }
        App::instance()->db->update(
            'theme_strings',
            ['translations' => json_encode($translations, JSON_UNESCAPED_UNICODE)],
            ['id' => $this->id]
        );
        $this->translations = $translations;
    }

    public function delete(): void
    {
        App::instance()->db->delete('theme_strings', ['id' => $this->id]);
    }

    /** Count of languages that have a non-empty translation for this string. */
    public function translationCount(): int
    {
        return count(array_filter($this->translations, fn($v) => $v !== ''));
    }
}
