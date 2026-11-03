<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * Translation row for site.json-declared config (item types and
 * taxonomies). One row per (config_type, config_key) pair. The
 * `translations` JSON holds all translatable fields and all
 * languages for that entity.
 *
 * Shape inside `translations`:
 *   {
 *     "slug": {"en": "news", "es": "noticias"}
 *   }
 */
final class ConfigTranslation
{
    public int $id;
    public string $configType;
    public string $configKey;
    /** @var array<string, array<string, string>> */
    public array $translations = [];
    public ?string $updatedAt = null;

    public static function fromRow(array $row): self
    {
        $c = new self();
        $c->id = (int) $row['id'];
        $c->configType = (string) $row['config_type'];
        $c->configKey = (string) $row['config_key'];
        $c->translations = is_string($row['translations'] ?? null)
            ? (array) (json_decode($row['translations'], true) ?? [])
            : [];
        $c->updatedAt = $row['updated_at'] ?? null;
        return $c;
    }

    public static function find(string $configType, string $configKey): ?self
    {
        $row = App::instance()->db->fetch(
            'SELECT * FROM config_translations WHERE config_type = ? AND config_key = ? LIMIT 1',
            [$configType, $configKey]
        );
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Read a single translated value with default-language fallback.
     * Returns null if no translation exists for that language.
     */
    public static function read(string $configType, string $configKey, string $field, string $language): ?string
    {
        $row = self::find($configType, $configKey);
        if ($row === null) return null;
        $value = $row->translations[$field][$language] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Set or update one (field, language) cell on the row, creating
     * the row if it doesn't exist yet. Empty string deletes the
     * cell (so blanking + saving returns to the default-language
     * fallback).
     */
    public static function set(string $configType, string $configKey, string $field, string $language, string $value): self
    {
        $existing = self::find($configType, $configKey);
        $translations = $existing?->translations ?? [];

        if (!isset($translations[$field]) || !is_array($translations[$field])) {
            $translations[$field] = [];
        }
        if ($value === '') {
            unset($translations[$field][$language]);
            // Drop the whole field entry if no languages left
            if ($translations[$field] === []) {
                unset($translations[$field]);
            }
        } else {
            $translations[$field][$language] = $value;
        }

        $db = App::instance()->db;
        if ($existing === null) {
            $id = $db->insert('config_translations', [
                'config_type' => $configType,
                'config_key' => $configKey,
                'translations' => json_encode($translations, JSON_UNESCAPED_UNICODE),
            ]);
            return self::find($configType, $configKey) ?? throw new \RuntimeException('Failed to create config translation.');
        }

        // If everything was wiped, just drop the row to keep the table clean.
        if ($translations === []) {
            $db->delete('config_translations', ['id' => $existing->id]);
            $existing->translations = [];
            return $existing;
        }

        $db->update(
            'config_translations',
            ['translations' => json_encode($translations, JSON_UNESCAPED_UNICODE)],
            ['id' => $existing->id]
        );
        $existing->translations = $translations;
        return $existing;
    }
}
