<?php

declare(strict_types=1);

namespace Velk;

/**
 * Resolve o modelo de template de um item_type ou taxonomia. Puro — opera
 * sobre o array do def (mesmo shape nos dois), então itens e termos
 * compartilham a lógica.
 *
 *  - `template` (string) → modo "single": um arquivo fixo, sem picker.
 *  - `templates` (mapa não-vazio) → modo "list": chave => {label, file, fields?}.
 *
 * Campos: "global" (nenhuma entrada de `templates` tem a chave `fields`; usa
 * def['fields']) ou "per_template" (TODA entrada tem `fields`; def['fields']
 * ausente). A regra de exclusividade é imposta no schema:validate.
 */
final class TemplateResolver
{
    public static function mode(array $def): string
    {
        $templates = $def['templates'] ?? null;
        return is_array($templates) && $templates !== [] ? 'list' : 'single';
    }

    /** @return array<string,string> chave => label (vazio no modo single) */
    public static function options(array $def): array
    {
        if (self::mode($def) !== 'list') {
            return [];
        }
        $out = [];
        foreach ($def['templates'] as $key => $entry) {
            $out[(string) $key] = (string) ((is_array($entry) ? $entry['label'] ?? null : null) ?? $key);
        }
        return $out;
    }

    public static function fieldsMode(array $def): string
    {
        if (self::mode($def) !== 'list') {
            return 'global';
        }
        foreach ($def['templates'] as $entry) {
            if (!is_array($entry) || !array_key_exists('fields', $entry)) {
                return 'global';
            }
        }
        return 'per_template';
    }

    public static function fileFor(array $def, ?string $key): ?string
    {
        if (self::mode($def) === 'single') {
            $file = (string) ($def['template'] ?? '');
            return $file !== '' ? $file : null;
        }
        $entry = ($key !== null ? ($def['templates'][$key] ?? null) : null);
        $file = is_array($entry) ? (string) ($entry['file'] ?? '') : '';
        if ($file !== '') {
            return $file;
        }
        // I8.2: the item's saved custom key doesn't resolve (missing, or
        // its entry was removed/renamed in site.json) — fall back to the
        // type's own `template` (singular), when declared alongside
        // `templates`. Matches AGENTS.md's documented chain: item's custom
        // key → the type's template → single.php (that last step is
        // FrontRouter's job, outside this pure resolver). Only reached
        // when the key doesn't resolve — a valid key's file is returned
        // above, unchanged.
        $typeFile = (string) ($def['template'] ?? '');
        return $typeFile !== '' ? $typeFile : null;
    }

    /** @return list<array<string,mixed>> defs brutos (antes de resolveFields) */
    public static function fieldsFor(array $def, ?string $key): array
    {
        if (self::fieldsMode($def) === 'per_template') {
            $entry = ($key !== null ? ($def['templates'][$key] ?? null) : null);
            return is_array($entry) && is_array($entry['fields'] ?? null) ? $entry['fields'] : [];
        }
        return is_array($def['fields'] ?? null) ? $def['fields'] : [];
    }
}
