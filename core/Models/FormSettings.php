<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;
use Velk\ClientEvents;

/**
 * Conversion settings for one form, configured on /admin/forms/{id}.
 *
 * Two things: which of the form's fields map onto Google's enhanced-conversion
 * identifiers, and an optional raw snippet to run when the conversion fires
 * (the escape hatch for sites that paste gtag directly instead of using GTM —
 * a dataLayer push does nothing when nobody is listening).
 *
 * A form with no row here is the normal case, not an error: the conversion
 * event still fires, just with an empty `user_data` and no snippet.
 */
final class FormSettings
{
    public string $formId;

    /**
     * Google key => source field name, e.g. `['email' => 'seu_email']`.
     * Only keys in ClientEvents::USER_DATA_KEYS ever survive a save.
     *
     * @var array<string,string>
     */
    public array $userDataFields = [];

    public ?string $conversionSnippet = null;

    public static function fromRow(array $row): self
    {
        $s = new self();
        $s->formId = (string) $row['form_id'];

        $decoded = is_string($row['user_data_fields'] ?? null)
            ? json_decode((string) $row['user_data_fields'], true)
            : null;
        $s->userDataFields = self::sanitizeMapping(is_array($decoded) ? $decoded : []);

        $snippet = $row['conversion_snippet'] ?? null;
        $s->conversionSnippet = (is_string($snippet) && $snippet !== '') ? $snippet : null;

        return $s;
    }

    /** Never null — a form without a row behaves like a form with empty settings. */
    public static function forForm(string $formId): self
    {
        $row = App::instance()->db->fetch(
            'SELECT * FROM form_settings WHERE form_id = ? LIMIT 1',
            [$formId]
        );
        if ($row === null) {
            $s = new self();
            $s->formId = $formId;
            return $s;
        }
        return self::fromRow($row);
    }

    /**
     * Upsert. Writing an empty mapping and a null snippet is a legitimate
     * "turn it all off" — the row stays, holding that decision explicitly.
     *
     * @param array<string,mixed> $mapping
     */
    public static function save(string $formId, array $mapping, ?string $snippet): void
    {
        $clean = self::sanitizeMapping($mapping);
        $snippet = ($snippet !== null && trim($snippet) !== '') ? $snippet : null;

        $db = App::instance()->db;
        $exists = $db->fetch('SELECT form_id FROM form_settings WHERE form_id = ? LIMIT 1', [$formId]);

        $payload = [
            'user_data_fields'   => $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE),
            'conversion_snippet' => $snippet,
        ];

        if ($exists === null) {
            $db->insert('form_settings', ['form_id' => $formId] + $payload);
        } else {
            $db->update('form_settings', $payload, ['form_id' => $formId]);
        }
    }

    /**
     * Keeps only the whitelisted Google keys pointing at non-empty field
     * names. Iterates the whitelist rather than the input, so a hand-edited
     * row can't smuggle an extra key into the payload — same structural
     * guarantee ClientEvents::buildUserData() relies on, applied one layer
     * earlier so bad data never reaches storage either.
     *
     * @param  array<string,mixed> $mapping
     * @return array<string,string>
     */
    private static function sanitizeMapping(array $mapping): array
    {
        $out = [];
        foreach (ClientEvents::USER_DATA_KEYS as $key) {
            $field = $mapping[$key] ?? null;
            if (is_string($field) && trim($field) !== '') {
                $out[$key] = trim($field);
            }
        }
        return $out;
    }
}
