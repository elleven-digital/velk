<?php

declare(strict_types=1);

namespace Velk\Admin;

use Velk\App;
use Velk\Config;
use Velk\Models\Item;
use Velk\Models\Language;
use Velk\Models\Page;
use Velk\Models\Term;
use Velk\TranslatedSlug;

/**
 * Colisões de endereço num idioma, para o painel avisar ao salvar.
 *
 * Num idioma que não é o padrão, um conteúdo responde pelo slug traduzido
 * ou, sem ele, pelo slug padrão. Quando o slug traduzido de um é o slug do
 * outro naquele idioma, os dois caem no mesmo endereço e só um abre ali (o
 * traduzido; o publicado contra um rascunho — ver TranslatedSlug). O outro
 * fica sem endereço no idioma. Nada impede salvar: o aviso diz quem ficou
 * sem endereço, para o autor mudar um dos slugs.
 *
 * Cada colisão: ['language' => código, 'label' => nome do idioma,
 * 'url' => endereço disputado, 'lost' => este conteúdo ficou sem ele,
 * 'others' => títulos de quem divide o endereço (de quem o tem, se este
 * perdeu)]. Tipos e taxonomias sem página (`has_page: false`) e a página de
 * 404 não têm endereço a disputar.
 */
final class SlugConflicts
{
    /** @return list<array{language: string, label: string, url: string, lost: bool, others: list<string>}> */
    public static function forItem(Item $item): array
    {
        $config = App::instance()->config;
        if (($config->itemType($item->type)['has_page'] ?? true) === false) return [];
        $hier = $config->isItemTypeHierarchical($item->type);
        $out = [];
        foreach (self::languages($config) as $lang) {
            $path = $hier
                ? Item::fullPath($item, $config, $lang->code)
                : '/' . trim($config->itemTypeSlugIn($item->type, $lang->code), '/') . '/' . $item->slugIn($lang->code);
            $owner = $item->urlOwnerIn($lang->code);
            if ($owner !== null && $owner->id !== $item->id) {
                $out[] = self::conflict($lang, $path, true, [$owner->title]);
                continue;
            }
            $others = [];
            foreach (TranslatedSlug::candidates('items', 'type = ?', [$item->type], $item->slugIn($lang->code), $lang->code) as $row) {
                $other = Item::fromRow($row);
                // Em hierarquia, o mesmo slug só colide se a cadeia inteira dá o
                // mesmo endereço — irmãos, ou pais que também colidem.
                if ($other->id !== $item->id && (!$hier || Item::fullPath($other, $config, $lang->code) === $path)) {
                    $others[] = $other->title;
                }
            }
            if ($others !== []) {
                $out[] = self::conflict($lang, $path, false, $others);
            }
        }
        return $out;
    }

    /** @return list<array{language: string, label: string, url: string, lost: bool, others: list<string>}> */
    public static function forTerm(Term $term): array
    {
        $config = App::instance()->config;
        if (($config->taxonomy($term->taxonomy)['has_page'] ?? true) === false) return [];
        $hier = $config->isTaxonomyHierarchical($term->taxonomy);
        $out = [];
        foreach (self::languages($config) as $lang) {
            $path = $hier
                ? Term::fullPath($term, $config, $lang->code)
                : '/' . trim($config->taxonomySlugIn($term->taxonomy, $lang->code), '/') . '/' . $term->slugIn($lang->code);
            $owner = $term->urlOwnerIn($lang->code);
            if ($owner !== null && $owner->id !== $term->id) {
                $out[] = self::conflict($lang, $path, true, [$owner->name]);
                continue;
            }
            $others = [];
            foreach (TranslatedSlug::candidates('terms', 'taxonomy = ?', [$term->taxonomy], $term->slugIn($lang->code), $lang->code) as $row) {
                $other = Term::fromRow($row);
                if ($other->id !== $term->id && (!$hier || Term::fullPath($other, $config, $lang->code) === $path)) {
                    $others[] = $other->name;
                }
            }
            if ($others !== []) {
                $out[] = self::conflict($lang, $path, false, $others);
            }
        }
        return $out;
    }

    /** @return list<array{language: string, label: string, url: string, lost: bool, others: list<string>}> */
    public static function forPage(string $key, Config $config): array
    {
        return Page::withAllRows(static fn(): array => self::pageConflicts($key, $config));
    }

    /** @return list<array{language: string, label: string, url: string, lost: bool, others: list<string>}> */
    private static function pageConflicts(string $key, Config $config): array
    {
        $out = [];
        $languages = self::languages($config);
        // A página de 404 não tem endereço próprio.
        if ($languages === [] || $key === $config->notFoundPageKey()) return [];
        // As páginas são poucas: compara o path de cada uma no idioma.
        $keys = [];
        foreach (array_keys($config->pages()) as $k) {
            if ((string) $k !== $config->notFoundPageKey()) $keys[] = (string) $k;
        }
        foreach (Page::allCustom() as $custom) {
            $keys[] = $custom->key;
        }
        foreach ($languages as $lang) {
            $path = Page::fullPath($key, $config, $lang->code);
            $owner = Page::resolveByUrl($path, $config, $lang->code)['key'] ?? null;
            if ($owner !== null && $owner !== $key) {
                $out[] = self::conflict($lang, $path, true, [self::pageTitle($owner, $config)]);
                continue;
            }
            $others = [];
            foreach ($keys as $other) {
                if ($other !== $key && Page::fullPath($other, $config, $lang->code) === $path) {
                    $others[] = self::pageTitle($other, $config);
                }
            }
            if ($others !== []) {
                $out[] = self::conflict($lang, $path, false, $others);
            }
        }
        return $out;
    }

    /** @return list<Language> os idiomas ativos que não são o padrão */
    private static function languages(Config $config): array
    {
        if (!$config->isI18nEnabled()) return [];
        $default = $config->defaultLanguage();
        return array_values(array_filter(
            Language::all(onlyEnabled: true),
            static fn(Language $lang): bool => $lang->code !== $default
        ));
    }

    private static function pageTitle(string $key, Config $config): string
    {
        $row = Page::findByKey($key);
        if ($row !== null && $row->title !== '') return $row->title;
        return (string) ($config->page($key)['label'] ?? $key);
    }

    /**
     * @param list<string> $others
     * @return array{language: string, label: string, url: string, lost: bool, others: list<string>}
     */
    private static function conflict(Language $lang, string $path, bool $lost, array $others): array
    {
        return [
            'language' => $lang->code,
            'label' => $lang->label,
            'url' => \url($path, $lang->code),
            'lost' => $lost,
            'others' => $others,
        ];
    }
}
