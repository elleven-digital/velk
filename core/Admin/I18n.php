<?php
declare(strict_types=1);

namespace Velk\Admin;

/**
 * File-based translation runtime for the ADMIN PANEL.
 *
 * Distinct from Velk\I18n (the site's DB-backed content i18n). Catalogs are
 * plain PHP array files at core/Admin/lang/<locale>.php returning
 * ['area.key' => 'text']. English (en) is the canonical source catalog.
 *
 * Active locale (per request) is set by the admin base Controller to the
 * authenticated user's `locale`, else the configured default (ADMIN_LOCALE).
 * Missing key falls back: active → default → source(en) → the key itself, so
 * nothing ever renders blank.
 */
final class I18n
{
    /** @var array<string,array<string,string>> */
    private array $catalogs = [];
    private string $current;

    public function __construct(
        private string $langDir,
        private string $defaultLocale,
        private string $sourceLocale = 'en',
    ) {
        $this->current = $defaultLocale;
    }

    /** @return string[] locales that have a catalog file, sorted. */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->langDir . '/*.php') ?: [] as $path) {
            $out[] = basename($path, '.php');
        }
        sort($out);
        return $out;
    }

    public function setCurrent(string $locale): void
    {
        $this->current = $locale !== '' ? $locale : $this->defaultLocale;
    }

    public function current(): string
    {
        return $this->current;
    }

    /**
     * Translate a symbolic key. The catalog text is trusted and returned as-is;
     * {name} placeholders are replaced with HTML-escaped param values.
     */
    public function translate(string $key, array $params = []): string
    {
        return $this->interpolate($this->lookup($key), $params, true);
    }

    /**
     * I10: like translate(), but interpolated params are NOT HTML-escaped.
     * For PLAIN-TEXT sinks only — e.g. an email Subject header, which isn't
     * an HTML context and would otherwise literally render entities
     * ("Joe&#039;s &amp; Co"). Never use this for HTML body output; use
     * translate() there so params stay escaped.
     */
    public function translateRaw(string $key, array $params = []): string
    {
        return $this->interpolate($this->lookup($key), $params, false);
    }

    /** @param array<string,mixed> $params */
    private function interpolate(string $text, array $params, bool $escape): string
    {
        if (!$params) {
            return $text;
        }
        $repl = [];
        foreach ($params as $k => $v) {
            $value = (string) $v;
            $repl['{' . $k . '}'] = $escape ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
        }
        return strtr($text, $repl);
    }

    private function lookup(string $key): string
    {
        foreach ([$this->current, $this->defaultLocale, $this->sourceLocale] as $locale) {
            $catalog = $this->catalog($locale);
            if (isset($catalog[$key])) {
                return $catalog[$key];
            }
        }
        return $key;
    }

    /** @return array<string,string> */
    private function catalog(string $locale): array
    {
        if (!isset($this->catalogs[$locale])) {
            $path = $this->langDir . '/' . $locale . '.php';
            $data = is_file($path) ? require $path : [];
            $this->catalogs[$locale] = is_array($data) ? $data : [];
        }
        return $this->catalogs[$locale];
    }

    public function labelFor(string $code): string
    {
        $names = ['en' => 'English', 'pt' => 'Português', 'es' => 'Español'];
        return $names[$code] ?? $code;
    }
}
