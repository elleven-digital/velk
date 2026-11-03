<?php

declare(strict_types=1);

namespace Velk\Admin;

/**
 * O grupo de opções "Planos & Assinaturas" (/admin/options/subscriptions),
 * gerado dos planos declarados em `site.json → members.plans`: um "ativo" e
 * um preço por intervalo oferecido de cada plano, mais a moeda. Renderiza e
 * salva pelo OptionController, como qualquer página de opções; quem lê os
 * valores é o SubscriptionService (buildPlans), pelos nomes dos campos.
 *
 * Mora no admin porque só o admin o usa (Config::builtinOptions → tela e
 * sidebar), e os textos vêm do catálogo do painel — `__()` aqui é visto pelo
 * `i18n:scan-admin`. O nome do plano entra cru no rótulo: o FieldRenderer
 * escapa o rótulo inteiro.
 */
final class SubscriptionOptions
{
    /**
     * @param array<string, mixed> $plans `members.plans` do site.json
     * @return array{label: string, description: string, translatable: bool, fields: list<array<string, mixed>>}
     */
    public static function group(array $plans): array
    {
        $fields = [];
        foreach ($plans as $key => $plan) {
            if (!is_array($plan)) {
                continue;
            }
            $name = (string) ($plan['name'] ?? $key);
            $intervals = (array) ($plan['intervals'] ?? ['month']);

            $fields[] = [
                'name' => $key . '_active',
                'type' => 'boolean',
                'label' => $name . ' — ' . __('subscriptions.field_active'),
            ];
            if (in_array('month', $intervals, true)) {
                $fields[] = [
                    'name' => $key . '_price_month',
                    'type' => 'text',
                    'label' => $name . ' — ' . __('subscriptions.field_price_month'),
                    'help' => __('subscriptions.help_price_month'),
                ];
            }
            if (in_array('year', $intervals, true)) {
                $fields[] = [
                    'name' => $key . '_price_year',
                    'type' => 'text',
                    'label' => $name . ' — ' . __('subscriptions.field_price_year'),
                    'help' => __('subscriptions.help_price_year'),
                ];
            }
        }

        $fields[] = [
            'name' => 'currency',
            'type' => 'text',
            'label' => __('subscriptions.field_currency'),
            'help' => __('subscriptions.help_currency'),
        ];

        return [
            'label' => __('subscriptions.group_label'),
            'description' => __('subscriptions.group_description'),
            // Preço, "ativo" e moeda não têm tradução, e o SubscriptionService
            // lê só os valores do idioma padrão: abas de idioma aqui seriam
            // campos que ninguém lê.
            'translatable' => false,
            'fields' => $fields,
        ];
    }
}
