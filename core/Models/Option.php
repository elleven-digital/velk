<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;
use Velk\Richtext;

/**
 * Wraps the `settings` table for site-wide "options pages" — admin forms
 * that hold global custom fields (contact info, footer texts, etc.) defined
 * by the theme in `site.json → options`.
 *
 * Storage layout: one row per options page, with key `options.{pageKey}`
 * and a JSON blob of all field values.
 */
final class Option
{
    private const PREFIX = 'options.';

    /** Cached JSON values per group key. */
    private static array $cache = [];

    public static function getGroup(string $key): array
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }
        $row = App::instance()->db->fetch(
            'SELECT value FROM settings WHERE setting_key = ? LIMIT 1',
            [self::PREFIX . $key]
        );
        $values = [];
        if ($row !== null && is_string($row['value'])) {
            $decoded = json_decode($row['value'], true);
            if (is_array($decoded)) {
                $values = $decoded;
            }
        }
        return self::$cache[$key] = $values;
    }

    public static function setGroup(string $key, array $values): void
    {
        $db = App::instance()->db;
        $existing = $db->fetch('SELECT setting_key FROM settings WHERE setting_key = ? LIMIT 1', [self::PREFIX . $key]);
        $json = json_encode($values, JSON_UNESCAPED_UNICODE);
        if ($existing === null) {
            $db->insert('settings', ['setting_key' => self::PREFIX . $key, 'value' => $json]);
        } else {
            $db->update('settings', ['value' => $json], ['setting_key' => self::PREFIX . $key]);
        }
        self::$cache[$key] = $values;
    }

    /**
     * Read the full translations map for an option group. Shape:
     * `{"en": {...fields...}, "es": {...fields...}}`.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getTranslations(string $key): array
    {
        $row = App::instance()->db->fetch(
            'SELECT value_translations FROM settings WHERE setting_key = ? LIMIT 1',
            [self::PREFIX . $key]
        );
        if ($row === null || !is_string($row['value_translations'] ?? null)) {
            return [];
        }
        $decoded = json_decode($row['value_translations'], true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function setTranslations(string $key, array $translations): void
    {
        $db = App::instance()->db;
        $existing = $db->fetch('SELECT setting_key FROM settings WHERE setting_key = ? LIMIT 1', [self::PREFIX . $key]);
        $json = $translations === [] ? null : json_encode($translations, JSON_UNESCAPED_UNICODE);
        if ($existing === null) {
            $db->insert('settings', [
                'setting_key' => self::PREFIX . $key,
                'value' => '{}',
                'value_translations' => $json,
            ]);
        } else {
            $db->update('settings', ['value_translations' => $json], ['setting_key' => self::PREFIX . $key]);
        }
    }

    /**
     * Whole group for one language, merged over the default-language
     * values. Used by the `options()` helper, so that `options('x')['f']`
     * and `option('x.f')` answer the same in every language.
     *
     * Same rule as getPathIn(): '', null and a richtext with nothing
     * written (`<p></p>`) count as untranslated. The merge is shallow, as
     * in Item::fieldsIn() — a translated repeater replaces the default one
     * as a whole.
     */
    public static function getGroupIn(string $key, string $language): array
    {
        $values = self::getGroup($key);
        $translated = self::getTranslations($key)[$language] ?? null;
        if (!is_array($translated) || $translated === []) {
            return $values;
        }
        $translated = array_filter(
            $translated,
            static fn($value) => $value !== '' && $value !== null && !Richtext::isEmptyDocument($value)
        );
        return array_merge($values, $translated);
    }

    /**
     * Read one field value with language fallback. Used by the
     * `option()` helper to make all option reads i18n-aware
     * transparently.
     */
    public static function getPathIn(string $path, string $language, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        if ($segments === []) return $default;
        $groupKey = array_shift($segments);
        if ($groupKey === '') return $default;

        // Try the translation tree first, fall back to the default
        // value tree. We walk the segments through both — translations
        // may be partial (one field translated, rest in default).
        $translations = self::getTranslations($groupKey);
        $translatedTree = $translations[$language] ?? null;
        if (is_array($translatedTree)) {
            $value = $translatedTree;
            $foundAll = true;
            foreach ($segments as $segment) {
                if (is_array($value) && array_key_exists($segment, $value)) {
                    $value = $value[$segment];
                } else {
                    $foundAll = false;
                    break;
                }
            }
            // Um richtext sem nada escrito (`<p></p>`) conta como não
            // traduzido, como em fieldIn() dos modelos.
            if ($foundAll && $value !== '' && $value !== null && !Richtext::isEmptyDocument($value)) {
                return $value;
            }
        }

        // Fallback: default-language value
        return self::getPath($path, $default);
    }

    /**
     * Read a value by dot path: `option('contact.email')` returns
     * `getGroup('contact')['email']`. Supports nested paths into repeaters
     * and groups: `option('contact.social.0.url')`.
     */
    public static function getPath(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        if ($segments === []) return $default;

        $groupKey = array_shift($segments);
        if ($groupKey === '') return $default;

        $value = self::getGroup($groupKey);
        foreach ($segments as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }
        return $value;
    }

    /** Reset the in-process cache (useful between tests). */
    public static function flushCache(): void
    {
        self::$cache = [];
    }
}
