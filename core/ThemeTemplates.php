<?php

declare(strict_types=1);

namespace Velk;

/**
 * Os templates que o site.json declara e o tema não tem.
 *
 * O FrontRouter usa o arquivo declarado ou, se ele não existe, o de reserva
 * do tema — page.php para páginas, single.php para itens, taxonomy.php para
 * termos. Sem os dois, aquele endereço dá 404. É o que missing() aponta,
 * para o schema:validate acusar antes de o visitante dar com o 404.
 */
final class ThemeTemplates
{
    /** @return list<string> um problema por template declarado que falta, sem o de reserva */
    public static function missing(Config $config): array
    {
        $dir = $config->path('theme') . '/templates';
        // Como o FrontRouter lê: o nome sem `.php` ganha a extensão.
        $withExt = static fn(string $name): string => str_ends_with($name, '.php') ? $name : $name . '.php';
        $has = static fn(string $name): bool => is_file($dir . '/' . ltrim($withExt($name), '/'));
        $problems = [];
        $check = static function (string $where, mixed $name, string $fallback) use ($has, $withExt, &$problems): void {
            if ($has($fallback)) return;
            if ($name === null) {
                // Sem template declarado, o FrontRouter usa o de reserva.
                $problems[] = "{$where}: no template declared, and there is no templates/{$fallback} to fall back on — its URLs return 404.";
                return;
            }
            if (!is_string($name) || $name === '' || $has($name)) return;
            $problems[] = "{$where}: templates/{$withExt($name)} not found, and there is no templates/{$fallback} to fall back on — its URLs return 404.";
        };

        foreach ((array) $config->site('pages') as $key => $page) {
            if (is_array($page)) $check("Page \"{$key}\"", $page['template'] ?? null, 'page.php');
        }
        foreach ((array) $config->site('page_templates') as $key => $tpl) {
            if (is_array($tpl) && isset($tpl['file'])) $check("Page template \"{$key}\"", $tpl['file'], 'page.php');
        }
        foreach (['item_types' => ['Item type', 'single.php'], 'taxonomies' => ['Taxonomy', 'taxonomy.php']] as $section => [$label, $fallback]) {
            foreach ((array) $config->site($section) as $key => $def) {
                if (!is_array($def) || ($def['has_page'] ?? true) === false) continue;
                if (isset($def['template'])) $check("{$label} \"{$key}\"", $def['template'], $fallback);
                foreach ((array) ($def['templates'] ?? []) as $tplKey => $tpl) {
                    if (is_array($tpl) && isset($tpl['file'])) $check("{$label} \"{$key}\", template \"{$tplKey}\"", $tpl['file'], $fallback);
                }
            }
        }
        return $problems;
    }
}
