<?php

declare(strict_types=1);

namespace Velk\Admin;

/**
 * O campo `price` anexado a todo tipo `sellable: true`, no editor do item
 * (ItemController, pelo Config::saleFields). `item_price()` e o checkout leem
 * esse nome, na moeda de `store.currency`.
 *
 * Mora no admin porque só o editor o desenha, e o texto vem do catálogo do
 * painel — `__()` aqui é visto pelo `i18n:scan-admin`. A moeda entra na ajuda
 * já validada (três letras maiúsculas, Config::storeCurrency), então o escape
 * dos parâmetros do `__()` não a altera.
 */
final class SaleFields
{
    /** @return list<array<string, mixed>> */
    public static function fields(string $currency): array
    {
        return [
            [
                'name' => 'price',
                'type' => 'text',
                'label' => __('items.sale_price_label'),
                'help' => __('items.sale_price_help', ['currency' => $currency]),
            ],
        ];
    }
}
