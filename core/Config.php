<?php

declare(strict_types=1);

namespace Velk;

final class Config
{
    private array $env;
    private array $site;
    private string $rootPath;
    private bool $themeInstalled = false;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
        $envFile = $rootPath . '/config/env.php';

        if (!is_file($envFile)) {
            throw new \RuntimeException("Config file not found: {$envFile}");
        }

        // env.php reads from environment variables (loaded via Velk\Env::load).
        $this->env = require $envFile;

        // The theme is a separate package — it brings its own site.json.
        // When no theme is installed yet, we boot with an empty schema so the
        // setup page can render and tell the user what to do.
        $themePath = $this->env['paths']['theme'] ?? ($rootPath . '/theme');
        $siteFile = $themePath . '/site.json';

        if (!is_file($siteFile)) {
            $this->site = [];
            $this->themeInstalled = false;
            return;
        }

        $rawSite = file_get_contents($siteFile);
        $decoded = json_decode((string) $rawSite, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid site.json: ' . json_last_error_msg());
        }

        $this->site = $decoded;
        $this->themeInstalled = true;
    }

    public function themeInstalled(): bool
    {
        return $this->themeInstalled;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->env;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function site(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->site;
        }

        $segments = explode('.', $key);
        $value = $this->site;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function pages(): array
    {
        return $this->site['pages'] ?? [];
    }

    public function page(string $key): ?array
    {
        return $this->site['pages'][$key] ?? null;
    }

    /**
     * Key of the site.json page used as the front-end 404 (top-level
     * "not_found_page"). Null when the site doesn't configure one —
     * the engine falls back to the generic 404 response.
     */
    public function notFoundPageKey(): ?string
    {
        $key = $this->site['not_found_page'] ?? null;
        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Page templates available for admin-created pages. Each entry is:
     *   key => {label, file, fields[]}
     *
     * `file` is the PHP template under theme/templates/. `fields` is
     * the custom-field schema used in the admin edit form for any page
     * built from this template. Empty/missing → admin shows the
     * "Criar página" button as disabled with a helpful explanation.
     */
    public function pageTemplates(): array
    {
        return $this->site['page_templates'] ?? [];
    }

    public function pageTemplate(string $key): ?array
    {
        return $this->site['page_templates'][$key] ?? null;
    }

    public function itemTypes(): array
    {
        return $this->site['item_types'] ?? [];
    }

    // ─── i18n ──────────────────────────────────────────────────────
    //
    // The i18n block in site.json is small intentionally — only the
    // toggle and the default language live here. Everything else
    // (additional languages, translations) is managed via the admin
    // and stored in the DB. This keeps content/translation work
    // entirely separate from the dev/config layer.

    public function isI18nEnabled(): bool
    {
        return (bool) ($this->site['i18n']['enabled'] ?? false);
    }

    public function defaultLanguage(): string
    {
        $code = $this->site['i18n']['default'] ?? null;
        return is_string($code) && $code !== '' ? $code : 'pt-BR';
    }

    /**
     * Nome de exibição do idioma padrão — o que aparece no switcher do site
     * e nas abas de idioma do painel.
     *
     * Existe porque o padrão é o ÚNICO idioma sem nome editável: os demais
     * são linhas em `languages`, com campo "Nome" obrigatório, e o painel
     * proíbe criar uma linha para o padrão (LanguageController: 'não pode ser
     * igual ao default'). Sem esta chave o rótulo dele era um mapa fixo no
     * código — que nomeava treze idiomas e não deixava ninguém escolher
     * "Brasil" ou "PT" no lugar de "Português (Brasil)".
     *
     * Null quando ausente; quem chama decide o fallback (hoje: o próprio
     * código). Só é exigida quando `i18n.enabled` é true — num site de um
     * idioma só, nenhuma tela renderiza rótulo de idioma.
     */
    public function defaultLanguageLabel(): ?string
    {
        $label = $this->site['i18n']['default_label'] ?? null;
        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    /**
     * Effective slug for an item type at a given language. Looks up
     * the translated slug from `config_translations` first, falling
     * back to the site.json default. The site.json `slug` is the
     * authoritative default-language value; translations only ever
     * override for non-default languages.
     */
    public function itemTypeSlugIn(string $type, string $language): string
    {
        $defaultSlug = (string) ($this->itemType($type)['slug'] ?? $type);
        if ($language === $this->defaultLanguage()) {
            return $defaultSlug;
        }
        $translated = \Velk\Models\ConfigTranslation::read('item_type', $type, 'slug', $language);
        return $translated ?? $defaultSlug;
    }

    public function taxonomySlugIn(string $taxonomy, string $language): string
    {
        $defaultSlug = (string) ($this->taxonomy($taxonomy)['slug'] ?? $taxonomy);
        if ($language === $this->defaultLanguage()) {
            return $defaultSlug;
        }
        $translated = \Velk\Models\ConfigTranslation::read('taxonomy', $taxonomy, 'slug', $language);
        return $translated ?? $defaultSlug;
    }

    /**
     * Inverse of itemTypeSlugIn — given a URL segment (e.g., "news"
     * or "blog") and the current language, find which item type it
     * resolves to. Used by FrontRouter to match incoming URLs.
     * Returns null when no type's translated/default slug matches.
     */
    public function itemTypeByLocalizedSlug(string $segment, string $language): ?string
    {
        foreach ($this->itemTypes() as $type => $def) {
            $type = (string) $type;
            if ($this->itemTypeSlugIn($type, $language) === $segment) {
                return $type;
            }
        }
        return null;
    }

    public function taxonomyByLocalizedSlug(string $segment, string $language): ?string
    {
        foreach ($this->taxonomies() as $tax => $def) {
            $tax = (string) $tax;
            if ($this->taxonomySlugIn($tax, $language) === $segment) {
                return $tax;
            }
        }
        return null;
    }

    // ─── Site members ──────────────────────────────────────────────
    //
    // Members are an opt-in feature. When `site.json → members.enabled`
    // is missing or false, none of the member routes/UI are exposed.
    //
    // `types` is optional — a site can have members without distinct
    // types (everyone is just a "member"). When types exist, each one
    // can declare its own custom fields in addition to the global
    // member fields under `members.fields`.
    //
    // `public_registration` (default false): when true, the front-end
    // can expose a registration form via POST /member/register. When
    // false, only the admin can create member accounts.

    public function isMembersEnabled(): bool
    {
        return (bool) ($this->site['members']['enabled'] ?? false);
    }

    public function isMemberPublicRegistration(): bool
    {
        return (bool) ($this->site['members']['public_registration'] ?? false);
    }

    public function members(): array
    {
        return $this->site['members'] ?? [];
    }

    /** Custom fields declared at the top level (apply to every member). */
    public function memberFields(): array
    {
        return (array) ($this->site['members']['fields'] ?? []);
    }

    /** All member type definitions keyed by their type slug. */
    public function memberTypes(): array
    {
        return (array) ($this->site['members']['types'] ?? []);
    }

    public function memberType(string $type): ?array
    {
        return $this->site['members']['types'][$type] ?? null;
    }

    /**
     * Effective field schema for a specific type: global fields merged
     * with that type's own fields, in that order. Pass null for type
     * to get just the global fields (used when the site has no types).
     */
    public function memberFieldsFor(?string $type): array
    {
        $globals = $this->memberFields();
        if ($type === null) {
            return $this->resolveFields($globals);
        }
        $typeDef = $this->memberType($type);
        $typeFields = $typeDef !== null ? (array) ($typeDef['fields'] ?? []) : [];
        return $this->resolveFields(array_merge($globals, $typeFields));
    }

    /**
     * URL paths for the public-facing member auth flows. The defaults
     * live under `/member/*`; a site can override any of them in
     * site.json → members.routes (e.g., {"login": "/entrar"}) to match
     * its UX language without code changes.
     */
    public function memberRoutes(): array
    {
        $custom = (array) ($this->site['members']['routes'] ?? []);
        return array_merge([
            'login'             => '/member/login',
            'logout'            => '/member/logout',
            'register'          => '/member/register',
            'password_reset'    => '/member/password-reset',
            'password_new'      => '/member/password-new',
            'two_factor'        => '/member/two-factor',
            'two_factor_resend' => '/member/two-factor-resend',
            'subscribe'            => '/member/subscribe',
            'subscription_portal'  => '/member/subscription/portal',
            'subscription_success' => '/member/subscription/success',
            'subscription_cancel'  => '/member/subscription/cancel',
            'stripe_webhook'       => '/member/stripe-webhook',
        ], $custom);
    }

    /** Planos de assinatura declarados em site.json → members.plans. */
    public function memberPlans(): array
    {
        return (array) ($this->site['members']['plans'] ?? []);
    }

    public function memberPlan(string $key): ?array
    {
        return $this->site['members']['plans'][$key] ?? null;
    }

    /**
     * members.multiple_plans === true: um membro pode ter mais de uma
     * assinatura ativa — uma por plano. Ausente ou qualquer outro valor:
     * uma por membro. Só o literal true liga; schema:validate rejeita o resto.
     */
    public function allowsMultiplePlans(): bool
    {
        return ($this->site['members']['multiple_plans'] ?? false) === true;
    }

    /**
     * Assinaturas ligadas: membros habilitados, planos declarados, e a
     * chave secreta do Stripe presente no .env. Sem a chave, o admin
     * mostra os campos mas o checkout fica inerte e o tema esconde os botões.
     */
    public function isSubscriptionsEnabled(): bool
    {
        // Exige TAMBÉM o webhook secret. Só com a chave secreta o checkout
        // funciona e o Stripe cobra — mas todo webhook chega sem assinatura
        // válida, é rejeitado com 400, e o membro paga sem nunca receber
        // acesso. Meio configurado tem que parecer DESLIGADO, não quebrado.
        return $this->isMembersEnabled()
            && $this->memberPlans() !== []
            && ((string) \Velk\Env::get('STRIPE_SECRET_KEY', '')) !== ''
            && ((string) \Velk\Env::get('STRIPE_WEBHOOK_SECRET', '')) !== '';
    }

    // ─── Loja / venda de itens ────────────────────────────────────

    public function isItemTypeSellable(string $type): bool
    {
        $def = $this->itemType($type);
        return $def !== null && ($def['sellable'] ?? false) === true;
    }

    /**
     * item_types.<type>.repeat_purchase !== false: o membro pode comprar o
     * mesmo item mais de uma vez. Ausente = true. Só o literal false
     * restringe; schema:validate rejeita o resto.
     */
    public function isItemTypeRepeatPurchase(string $type): bool
    {
        $def = $this->itemType($type);
        return $def === null || ($def['repeat_purchase'] ?? true) !== false;
    }

    /**
     * Tipos VENDÁVEIS com repeat_purchase false, na ordem de declaração.
     * A flag num tipo sem sellable não tem efeito (schema:validate acusa).
     * @return list<string>
     */
    public function singlePurchaseTypes(): array
    {
        $out = [];
        foreach ($this->sellableTypes() as $type => $def) {
            if (($def['repeat_purchase'] ?? true) === false) {
                $out[] = (string) $type;
            }
        }
        return $out;
    }

    /** Item types marcados sellable, keyed pelo slug. */
    public function sellableTypes(): array
    {
        $out = [];
        foreach ((array) ($this->site['item_types'] ?? []) as $type => $def) {
            if (is_array($def) && ($def['sellable'] ?? false) === true) {
                $out[(string) $type] = $def;
            }
        }
        return $out;
    }

    /**
     * Campo preço auto-anexado a tipos sellable (padrão seoFields), na moeda
     * da loja. O texto vem do catálogo do painel (Velk\Admin\SaleFields):
     * chamar só do admin.
     */
    public function saleFields(): array
    {
        return \Velk\Admin\SaleFields::fields($this->storeCurrency());
    }

    public function storeCurrency(): string
    {
        $c = strtoupper(trim((string) ($this->site['store']['currency'] ?? 'BRL')));
        return preg_match('/^[A-Z]{3}$/', $c) === 1 ? $c : 'BRL';
    }

    /** Loja ligada: há ≥ 1 tipo sellable e a chave do Stripe está presente. */
    public function isStoreEnabled(): bool
    {
        // Mesma razão de isSubscriptionsEnabled(): sem o secret do webhook da
        // loja, o pedido fica em `pending` para sempre depois de pago.
        return $this->sellableTypes() !== []
            && ((string) \Velk\Env::get('STRIPE_SECRET_KEY', '')) !== ''
            && ((string) \Velk\Env::get('STRIPE_STORE_WEBHOOK_SECRET', '')) !== '';
    }

    public function storeRoutes(): array
    {
        $custom = (array) ($this->site['store']['routes'] ?? []);
        return array_merge([
            'checkout' => '/store/checkout',
            'success'  => '/store/success',
            'cancel'   => '/store/cancel',
            'webhook'  => '/store/stripe-webhook',
        ], $custom);
    }

    /**
     * Handler(s) do webhook GLOBAL de pagamento — site.json → chave de topo
     * `payment_webhook`, disparado em `order.<status>` e
     * `subscription.<status>` (ver Velk\Services\WebhookDispatcher e o
     * design doc do payment-webhook). Mesma forma já aceita pelo webhook de
     * formulários (`forms.<id>.webhook`, em PublicFormController): uma
     * string é um único handler, uma lista de strings é vários, chamados na
     * ordem declarada.
     *
     * Permissivo, como todo acessor de Config: só normaliza a FORMA, nunca
     * valida o CONTEÚDO (isso é trabalho do `schema:validate`, em bin/velk)
     * nem checa se a função existe (isso é o runtime, via
     * WebhookDispatcher::dispatch — mesmo log-e-pula do forms webhook).
     * Nunca lança: chave ausente, string vazia, array vazio, ou qualquer
     * outro tipo (int, bool, array associativo tipo {"a":"b"}) vira `[]`
     * silenciosamente. Numa lista, entradas que não sejam string não-vazia
     * são descartadas, preservando a ordem das demais.
     */
    public function paymentWebhook(): array
    {
        $raw = $this->site['payment_webhook'] ?? null;

        if (is_string($raw)) {
            return $raw !== '' ? [$raw] : [];
        }

        // array_is_list() separa uma lista JSON de um objeto JSON (que o
        // json_decode também traduz pra array em PHP, mas com chaves
        // string) — um objeto no lugar de string|list não é uma forma
        // válida, então cai no `return []` final igual qualquer outro tipo.
        if (is_array($raw) && array_is_list($raw)) {
            $out = [];
            foreach ($raw as $item) {
                if (is_string($item) && $item !== '') {
                    $out[] = $item;
                }
            }
            return $out;
        }

        return [];
    }

    public function itemType(string $type): ?array
    {
        return $this->site['item_types'][$type] ?? null;
    }

    /** Um item type é hierárquico quando declara "hierarchical": true. */
    public function isItemTypeHierarchical(string $type): bool
    {
        return (($this->site['item_types'][$type]['hierarchical'] ?? false)) === true;
    }

    /** Uma taxonomia é hierárquica quando declara "hierarchical": true. */
    public function isTaxonomyHierarchical(string $taxonomy): bool
    {
        return (($this->site['taxonomies'][$taxonomy]['hierarchical'] ?? false)) === true;
    }

    public function taxonomies(): array
    {
        return $this->site['taxonomies'] ?? [];
    }

    public function taxonomy(string $taxonomy): ?array
    {
        return $this->site['taxonomies'][$taxonomy] ?? null;
    }

    public function fieldGroup(string $group): ?array
    {
        return $this->site['field_groups'][$group] ?? null;
    }

    /**
     * Os blocos que o tema declara para o editor de texto (`richtext_blocks`),
     * só os bem formados: chave no padrão e definição em objeto. O
     * `schema:validate` aponta os outros.
     *
     * @return array<string, array<string, mixed>>
     */
    public function richtextBlocks(): array
    {
        $blocks = $this->site['richtext_blocks'] ?? null;
        if (!is_array($blocks)) {
            return [];
        }
        $out = [];
        foreach ($blocks as $key => $def) {
            if (is_string($key) && RichtextBlocks::validKey($key) && is_array($def)) {
                $out[$key] = $def;
            }
        }
        return $out;
    }

    public function richtextBlock(string $key): ?array
    {
        return $this->richtextBlocks()[$key] ?? null;
    }

    /**
     * Os campos de um bloco, prontos para o formulário e para o partial:
     * referências a grupo expandidas, e sem `blocks` nos campos de texto.
     */
    public function richtextBlockFields(string $key): array
    {
        $def = $this->richtextBlock($key);
        if ($def === null || !is_array($def['fields'] ?? null)) {
            return [];
        }
        return RichtextBlocks::withoutNestedBlocks($this->resolveFields($def['fields']));
    }

    /**
     * Registro global de variantes de imagem: nome => {width, height, fit}.
     *
     * A reservada "thumb" (caixa global gerada no upload —
     * ver ImageVariantService::THUMB_BOX) é aplicada DEPOIS da varredura e
     * sempre vence — mesmo que nenhum campo declare `variants`, e mesmo
     * que um campo declare sua própria variante chamada "thumb". As
     * demais entradas vêm de uma varredura RECURSIVA de
     * `$this->site` inteiro — não enumera pages/item_types/taxonomies/
     * field_groups/options/members um por um: desce em qualquer array e,
     * ao achar uma def de campo (`type` === "image" ou "gallery") com
     * `variants` não-vazio, indexa cada `{name, width?, height?, fit?}`
     * (fit default "contain"; width/height ausentes viram null). Isso
     * cobre qualquer lugar onde uma def de campo more hoje — inclusive
     * per-template fields, repeaters, flexible layouts, tabs/group — e
     * continua valendo se uma fonte nova aparecer no futuro sem precisar
     * tocar este método.
     *
     * Permissivo por design pra tudo que não for "thumb": a varredura só
     * coleta (last-writer-wins em nomes duplicados entre campos). Nunca
     * lança nem valida más configurações (nome reservado, caixa vazia,
     * fit inválido, conflito entre nomes) — isso é responsabilidade do
     * `schema:validate` (bin/velk), não deste método. Um campo que
     * declare uma variante chamada "thumb" é ignorado aqui — o registro
     * global sobrescreve o que a varredura tiver coletado sob esse nome
     * — e essa configuração é adicionalmente rejeitada com erro pelo
     * `schema:validate`.
     *
     * @return array<string, array{width:?int, height:?int, fit:string}>
     */
    public function imageVariants(): array
    {
        $registry = [];

        $this->collectImageVariants($this->site, $registry);

        // Reservada global — aplicada por último de propósito, pra nenhum
        // campo que declare "thumb" conseguir sobrescrever a caixa real
        // usada no upload (ver docblock acima).
        $registry['thumb'] = [
            'width' => \Velk\Services\ImageVariantService::THUMB_BOX,
            'height' => \Velk\Services\ImageVariantService::THUMB_BOX,
            'fit' => 'contain',
        ];

        return $registry;
    }

    /**
     * Varredura recursiva usada por imageVariants() — ver o docblock acima
     * pro porquê de varrer a árvore inteira em vez de enumerar fontes.
     *
     * @param array<string, array{width:?int, height:?int, fit:string}> $registry
     */
    private function collectImageVariants(array $node, array &$registry): void
    {
        $type = $node['type'] ?? null;
        $variants = $node['variants'] ?? null;

        if (($type === 'image' || $type === 'gallery') && is_array($variants) && $variants !== []) {
            foreach ($variants as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $name = $variant['name'] ?? null;
                if (!is_string($name) || $name === '') {
                    continue;
                }
                $fit = $variant['fit'] ?? null;
                $registry[$name] = [
                    'width' => isset($variant['width']) ? (int) $variant['width'] : null,
                    'height' => isset($variant['height']) ? (int) $variant['height'] : null,
                    'fit' => (is_string($fit) && $fit !== '') ? $fit : 'contain',
                ];
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectImageVariants($value, $registry);
            }
        }
    }

    /** Modos de consentimento de uma categoria. */
    public const CONSENT_MODES = ['required', 'opt_out', 'opt_in'];

    /**
     * Categorias de consentimento declaradas pelo tema em `consent.categories`.
     *
     * O bloco inteiro é opcional, e essa é a chave do desenho: sem ele o site
     * segue com os três campos de script de sempre, sem categoria e sem
     * consentimento. Quem não quer LGPD gerenciada pelo Velk não paga nada
     * por ela existir.
     *
     * Cada categoria declara `label` (o que o popup mostra) e `mode`:
     *
     *   required  — sempre ativa, o visitante não desliga (é o caso do
     *               próprio cookie de consentimento, e de verificação de
     *               domínio, favicon, preconnect)
     *   opt_out   — ativa por padrão, o visitante pode desligar
     *   opt_in    — inativa por padrão, só liga com aceite explícito
     *
     * Qual modo cada categoria recebe é decisão do dono do site, não do
     * engine: o Velk dá o mecanismo, a adequação à lei é de quem publica.
     * Entradas malformadas são descartadas aqui e acusadas pelo
     * `schema:validate` — em runtime, categoria inválida sumindo é melhor
     * que categoria inválida virando um gate que não gateia nada.
     *
     * @return array<string, array{label: string, mode: string}>
     */
    public function consentCategories(): array
    {
        $raw = $this->site['consent']['categories'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $def) {
            $key = (string) $key;
            if ($key === '' || !is_array($def)) {
                continue;
            }
            $mode = (string) ($def['mode'] ?? '');
            if (!in_array($mode, self::CONSENT_MODES, true)) {
                continue;
            }
            $label = $def['label'] ?? null;
            $out[$key] = [
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : $key,
                'mode' => $mode,
            ];
        }
        return $out;
    }

    /**
     * O site TEM mecanismo de consentimento?
     *
     * Declarado pelo tema em `consent.enabled`. É afirmação sobre o TEMA, não
     * sobre o engine: só quem monta o site sabe se existe um banner ali para
     * o visitante aceitar ou recusar.
     *
     * Sem isso, o painel oferecia "exige consentimento" em qualquer site, e
     * um script marcado como opt_in num tema sem banner ficava inerte para
     * sempre — nada podia aceitá-lo, e nada na tela explicava o porquê. Um
     * script que nunca carrega e não acusa erro é o pior estado possível.
     *
     * Desligado, a área de consentimento some do painel inteira e todo script
     * é tratado como necessário.
     */
    public function isConsentEnabled(): bool
    {
        return (bool) ($this->site['consent']['enabled'] ?? false);
    }

    /**
     * O formulário guarda as submissões reprovadas pelo antispam?
     *
     * `forms.<id>.keep_spam` — por formulário, e não global, porque volume e
     * sensibilidade variam demais entre eles. Uma newsletter de um campo só
     * apanha de bot às milhares; um formulário longo de orçamento quase não.
     * E o que cada um coleta muda o peso de guardar: reter dado pessoal de
     * quem o sistema classificou como bot tem consequência de LGPD, e a
     * decisão precisa caber por formulário.
     *
     * Ausente = false. Sem a flag nada é gravado e o link "Spam" não existe
     * no painel — o comportamento é exatamente o de antes desta feature.
     */
    public function keepsSpamFor(string $formId): bool
    {
        return (bool) ($this->site['forms'][$formId]['keep_spam'] ?? false);
    }

    /**
     * `forms.<id>.steps` — as etapas do formulário, como listas de NOMES de
     * campo. A presença desta chave é o "permite preenchimento por etapas";
     * não existe flag separada.
     *
     * É um PARTICIONAMENTO, não uma redeclaração: `fields` segue sendo a
     * fonte única de tipo, validação e `preview_field`, e `data` segue um
     * mapa plano. Reorganizar etapa é editar duas linhas do site.json —
     * nenhum lead gravado muda, nenhuma migração.
     *
     * Declaração malformada devolve [] — ou seja, "sem etapas". O
     * `schema:validate` é quem recusa a configuração errada; em runtime,
     * cair no comportamento de hoje é a degradação segura. Adivinhar uma
     * partição a partir de lixo geraria etapa com campo faltando e
     * reprovaria envio legítimo.
     *
     * @return list<list<string>>
     */
    public function formSteps(string $formId): array
    {
        $raw = $this->site['forms'][$formId]['steps'] ?? null;
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $out = [];
        foreach ($raw as $step) {
            if (!is_array($step)) {
                return [];
            }
            $names = [];
            foreach ($step as $name) {
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
            if ($names === []) {
                return [];
            }
            $out[] = $names;
        }
        return $out;
    }

    /** O formulário permite preenchimento por etapas? */
    public function formHasSteps(string $formId): bool
    {
        return $this->formSteps($formId) !== [];
    }

    /**
     * Os nomes de campo de uma etapa. `$step` é **1-BASED**: a etapa 1 é a
     * primeira. Este método é o ÚNICO lugar do sistema que converte para o
     * índice 0-based do array do site.json — o resto só fala 1-based, que é
     * o que vai no `_step`, na coluna `pending_step` e no `$context`.
     *
     * Fora do intervalo devolve [], nunca erro: quem valida a etapa é o
     * `FormSubmission::resolveStep()`.
     *
     * @return list<string>
     */
    public function formStepFields(string $formId, int $step): array
    {
        return $this->formSteps($formId)[$step - 1] ?? [];
    }

    /**
     * O mapa de categorias que o gate realmente usa.
     *
     * Declaradas quando existem; as implícitas de `Consent` quando o tema não
     * declara nenhuma. Isso é o que permite consentimento funcionar SEM tocar
     * no tema — o dono do site marca "exige consentimento" no painel, e o
     * banner aprova ou recusa tudo de uma vez.
     *
     * Todo mundo que decide carregamento consome este método, e não
     * `consentCategories()` direto: o gate precisa reconhecer `_optional_on`
     * e `_optional_off` como categorias legítimas, senão um script marcado
     * assim cairia no caso "categoria desconhecida" e nunca sairia.
     *
     * @return array<string, array{label: string, mode: string}>
     */
    public function effectiveConsentCategories(): array
    {
        if (!$this->isConsentEnabled()) {
            return [];
        }
        $declared = $this->consentCategories();
        return $declared !== [] ? $declared : \Velk\Consent::implicitCategories();
    }

    /**
     * Built-in option groups that every Velk installation gets automatically,
     * without needing to declare them in `site.json`. Currently:
     *
     *   - subscriptions · prices, availability and currency of the plans in
     *     `members.plans` (Velk\Admin\SubscriptionOptions) — only when plans
     *     are declared. Its texts come from the admin catalog, so call this
     *     from the admin only.
     *
     * Built-in groups are merged with theme-defined options in `resolvedOptions()`
     * and behave identically in the admin (same Option model, same edit view),
     * except that a group with `translatable: false` has no language tabs.
     *
     * @return array<string, array<string,mixed>>
     */
    public function builtinOptions(): array
    {
        // O grupo `tracking` saiu daqui na v3.6.0: scripts viraram entidade
        // com tela própria (/admin/scripts), porque consentimento é decisão
        // POR SCRIPT e três textareas carregam uma decisão cada.
        //
        // A conversão de cadastro foi junto para aquela tela. As chaves
        // continuam gravadas no grupo `tracking` da tabela de opções — é de
        // lá que MemberController lê (`option('tracking.signup_snippet')`), e
        // `Option::getPath()` busca pela chave, não pelo schema declarado.
        // Mover a chave exigiria migração de dado para ganhar nada.
        $groups = [];

        $plans = $this->memberPlans();
        if ($plans !== []) {
            $groups['subscriptions'] = \Velk\Admin\SubscriptionOptions::group($plans);
        }

        return $groups;
    }

    /**
     * All option groups visible in the admin: theme-defined first, then
     * built-in groups appended (so themes can shadow a built-in by declaring
     * the same key in site.json — niche but supported).
     *
     * @return array<string, array<string,mixed>>
     */
    public function resolvedOptions(): array
    {
        $themeOptions = (array) ($this->site['options'] ?? []);
        $builtin = $this->builtinOptions();
        // Theme keys win on collision — `array_merge` of $builtin + $theme would
        // also work but $themeOptions + $builtin makes the precedence explicit.
        return array_merge($builtin, $themeOptions);
    }

    /**
     * Look up a single option group by key, checking theme definitions first
     * then falling back to built-ins.
     */
    public function optionGroup(string $key): ?array
    {
        $themeOptions = (array) ($this->site['options'] ?? []);
        if (isset($themeOptions[$key])) {
            return (array) $themeOptions[$key];
        }
        $builtin = $this->builtinOptions();
        return $builtin[$key] ?? null;
    }

    /**
     * Built-in SEO field set. Automatically attached to pages and to item
     * types with `has_page: true`. Stored in the same `fields` JSON as
     * regular content fields, but rendered as a separate block in the
     * admin so editors find them in a predictable place. Labels and help
     * come from the admin catalog (Velk\Admin\SeoFields), in the admin's
     * language — or ADMIN_LOCALE in the CLI.
     *
     * @return list<array<string,mixed>>
     */
    public function seoFields(): array
    {
        return \Velk\Admin\SeoFields::fields();
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function path(string $key): string
    {
        return (string) $this->get("paths.{$key}");
    }

    public function resolveFields(array $fields): array
    {
        $resolved = [];
        foreach ($fields as $field) {
            // Include reference: {"group": "name"} with no type — expand
            // inline from field_groups. (Distinct from {"type": "group"},
            // the visual container handled below.)
            if (isset($field['group']) && !isset($field['type'])) {
                $group = $this->fieldGroup($field['group']);
                if ($group !== null && isset($group['fields'])) {
                    foreach ($group['fields'] as $subField) {
                        $resolved[] = $subField;
                    }
                }
                continue;
            }

            // Organizational containers can hold include references in
            // their children — recurse so `{"group": "hero"}` works
            // inside a tab or group exactly like at the top level.
            $type = (string) ($field['type'] ?? '');
            if ($type === 'group' && is_array($field['fields'] ?? null)) {
                $field['fields'] = $this->resolveFields($field['fields']);
            } elseif ($type === 'tabs' && is_array($field['tabs'] ?? null)) {
                foreach ($field['tabs'] as $i => $tab) {
                    if (is_array($tab) && is_array($tab['fields'] ?? null)) {
                        $field['tabs'][$i]['fields'] = $this->resolveFields($tab['fields']);
                    }
                }
            }

            $resolved[] = $field;
        }
        return $resolved;
    }
}
