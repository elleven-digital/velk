<?php

declare(strict_types=1);

namespace Velk\Admin;

/**
 * Os campos de SEO anexados a toda página e a todo tipo com `has_page`
 * (Config::seoFields): `meta_title`, `meta_description` e `og_image`. O
 * editor os desenha na seção "SEO & compartilhamento"; o CLI os lista no
 * `item:schema` / `page:schema` e os valida no `item:create`.
 *
 * O texto vem do catálogo do painel — `__()` aqui é visto pelo
 * `i18n:scan-admin` —, no idioma de quem usa o painel ou, no CLI, no
 * `ADMIN_LOCALE`. Sem HTML no catálogo destas chaves: o FieldRenderer escapa
 * rótulo e ajuda.
 */
final class SeoFields
{
    /** @return list<array<string, mixed>> */
    public static function fields(): array
    {
        return [
            [
                'name'  => 'meta_title',
                'type'  => 'text',
                'label' => __('items.seo_meta_title_label'),
                'help'  => __('items.seo_meta_title_help'),
            ],
            [
                'name'  => 'meta_description',
                'type'  => 'textarea',
                'label' => __('items.seo_meta_description_label'),
                'help'  => __('items.seo_meta_description_help'),
            ],
            [
                'name'  => 'og_image',
                'type'  => 'image',
                'label' => __('items.seo_og_image_label'),
                'help'  => __('items.seo_og_image_help'),
            ],
        ];
    }
}
