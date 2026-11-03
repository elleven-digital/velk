<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * A language the site supports BEYOND the default. The default
 * (declared in site.json → i18n.default) is intentionally not stored
 * here — it's code-level configuration, not admin-managed.
 *
 * Each row carries a unique URL prefix (e.g., /en, /es) that Phase 2
 * routing uses to detect the active language from the request path.
 */
final class Language
{
    public string $code;
    public string $label;
    public ?string $urlPrefix = null;
    public int $sortOrder = 0;
    public bool $enabled = true;
    public string $createdAt;

    public static function fromRow(array $row): self
    {
        $l = new self();
        $l->code = (string) $row['code'];
        $l->label = (string) $row['label'];
        $l->urlPrefix = $row['url_prefix'] !== null ? (string) $row['url_prefix'] : null;
        $l->sortOrder = (int) $row['sort_order'];
        $l->enabled = (bool) $row['enabled'];
        $l->createdAt = (string) $row['created_at'];
        return $l;
    }

    public static function find(string $code): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM languages WHERE code = ? LIMIT 1', [$code]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findByPrefix(string $prefix): ?self
    {
        $prefix = '/' . trim($prefix, '/');
        $row = App::instance()->db->fetch('SELECT * FROM languages WHERE url_prefix = ? LIMIT 1', [$prefix]);
        return $row === null ? null : self::fromRow($row);
    }

    /** @return list<self> */
    public static function all(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM languages';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, code ASC';
        $rows = App::instance()->db->fetchAll($sql);
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function create(string $code, string $label, ?string $urlPrefix, int $sortOrder = 0, bool $enabled = true): self
    {
        App::instance()->db->insert('languages', [
            'code' => $code,
            'label' => $label,
            'url_prefix' => $urlPrefix,
            'sort_order' => $sortOrder,
            'enabled' => $enabled ? 1 : 0,
        ]);
        return self::find($code) ?? throw new \RuntimeException('Failed to create language.');
    }

    public function save(array $data): void
    {
        $update = [];
        if (array_key_exists('label', $data))      $update['label']      = $data['label'];
        if (array_key_exists('url_prefix', $data)) $update['url_prefix'] = $data['url_prefix'];
        if (array_key_exists('sort_order', $data)) $update['sort_order'] = (int) $data['sort_order'];
        if (array_key_exists('enabled', $data))    $update['enabled']    = $data['enabled'] ? 1 : 0;
        if ($update !== []) {
            App::instance()->db->update('languages', $update, ['code' => $this->code]);
        }
    }

    public function delete(): void
    {
        App::instance()->db->delete('languages', ['code' => $this->code]);
    }
}
