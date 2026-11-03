<?php

declare(strict_types=1);

namespace Velk;

final class FieldRenderer
{
    /**
     * O `name` de um campo do site.json como texto, ou $fallback quando não
     * é um valor simples — um site.json quebrado (um nome que é lista, por
     * exemplo) não vira aviso do PHP; quem valida aponta o erro.
     */
    public static function nameOf(array $field, string $fallback = ''): string
    {
        $name = $field['name'] ?? null;
        return is_scalar($name) ? (string) $name : $fallback;
    }

    /** O `label` do campo, ou o `name` dele, ou null — mesma regra de nameOf(). */
    private static function labelOf(array $field): ?string
    {
        foreach (['label', 'name'] as $key) {
            if (is_scalar($field[$key] ?? null)) return (string) $field[$key];
        }
        return null;
    }

    /**
     * Render a list of field definitions against a FLAT values array.
     *
     * This is the canonical entry point for anything that renders a
     * fields list (admin views, repeater rows, flexible rows). It
     * exists because two field types — `group` and `tabs` — are purely
     * ORGANIZATIONAL: they wrap other fields visually but do not own a
     * value. Their children read from and write to the same flat
     * storage as top-level fields, so rendering them needs the whole
     * values array, not a single value.
     *
     * That flatness is a deliberate contract: removing a group/tabs
     * wrapper from site.json later (or moving a field out of one)
     * changes nothing about stored data — the field keeps its name,
     * the JSON column keeps its key. Layout is presentation, never
     * persistence.
     */
    /**
     * Operadores aceitos em `show_if`. Dois de propósito: cobrem os casos
     * reais (booleano marcado, select num valor, select em um de vários) sem
     * virar uma linguagem de expressão dentro do JSON.
     */
    public const SHOW_IF_OPS = ['equals', 'in'];

    /**
     * O campo deve aparecer, dados os valores atuais do MESMO escopo?
     *
     * Sem `show_if`, sempre. Com `show_if` malformado, também sempre — um
     * campo que some por erro de configuração é pior que um campo a mais na
     * tela, e o `schema:validate` acusa a malformação antes de chegar aqui.
     *
     * "Mesmo escopo" é o array plano de valores que o chamador passou: a
     * linha do repetidor, ou o formulário inteiro. `group`/`tabs` são
     * transparentes no armazenamento, então um campo dentro de um grupo pode
     * depender de outro fora dele sem nada especial.
     *
     * @param array<string,mixed> $values
     */
    public static function matchesShowIf(array $field, array $values): bool
    {
        $cond = $field['show_if'] ?? null;
        if (!is_array($cond)) {
            return true;
        }
        $target = $cond['field'] ?? null;
        if (!is_string($target) || $target === '') {
            return true;
        }

        $current = $values[$target] ?? null;

        if (array_key_exists('in', $cond)) {
            $accepted = is_array($cond['in']) ? $cond['in'] : [];
            foreach ($accepted as $candidate) {
                if (self::looseEquals($current, $candidate)) {
                    return true;
                }
            }
            return false;
        }

        if (array_key_exists('equals', $cond)) {
            return self::looseEquals($current, $cond['equals']);
        }

        return true;
    }

    /**
     * Comparação tolerante ao que o armazenamento devolve.
     *
     * Um `boolean` volta como bool do JSON mas como "1"/"" de um POST cru; um
     * `select` de números volta como string. Comparar com `===` faria
     * `"equals": true` falhar exatamente no caso mais comum — checkbox
     * controlando um bloco. `==` do PHP tem armadilhas próprias ("abc" == 0
     * era true antes do PHP 8), então o booleano é normalizado à parte.
     */
    private static function looseEquals(mixed $current, mixed $expected): bool
    {
        if (is_bool($expected)) {
            return $expected === (bool) $current;
        }
        if ($current === null) {
            return $expected === null || $expected === '';
        }
        if (is_scalar($current) && is_scalar($expected)) {
            return (string) $current === (string) $expected;
        }
        return $current === $expected;
    }

    public static function renderFields(array $fieldDefs, array $values, string $namePrefix = 'fields'): string
    {
        // Aba de tradução: `required` sai de TODO campo.
        //
        // Dois motivos, e o segundo é o que quebrava de verdade.
        //
        // 1. Tradução é opcional por contrato. `fieldIn()` cai no idioma
        //    padrão quando não há tradução, e o controller só guarda o que o
        //    tradutor de fato preencheu. Exigir contradiz o próprio modelo.
        //
        // 2. Os painéis das outras línguas ficam ESCONDIDOS. Um `required`
        //    ali trava o submit sem nada visível: o navegador tenta focar um
        //    campo que a pessoa não vê, desiste, e a página simplesmente não
        //    envia. O console diz "invalid form control is not focusable" e
        //    mais nada. Mesma armadilha que `show_if` já resolvia logo
        //    abaixo — faltava resolver aqui.
        //
        // A checagem é no PREFIXO porque é ele o contrato: é o que faz o
        // POST cair em `fields_translations`. Um parâmetro novo exigiria que
        // os quatro chamadores (item, page, term, option) lembrassem — e o
        // quinto, no futuro, esqueceria.
        $ehTraducao = str_starts_with($namePrefix, 'fields_translations[');

        $html = '';
        foreach ($fieldDefs as $field) {
            if (!is_array($field)) continue;

            if ($ehTraducao) {
                $field['required'] = false;
            }

            // Visibilidade condicional, decidida no SERVIDOR. O JS depois só
            // acompanha a digitação — assim não pisca um campo que deveria
            // estar escondido, e um erro de JS em outro widget não deixa o
            // formulário exibindo tudo.
            if (isset($field['show_if']) && is_array($field['show_if'])) {
                $field['__conditional'] = $field['show_if'];
                $field['__required_original'] = !empty($field['required']);
                if (!self::matchesShowIf($field, $values)) {
                    $field['__hidden'] = true;
                    // `required` num campo escondido trava o submit sem nada
                    // visível na tela: o navegador tenta focar um elemento que
                    // não existe para o usuário e a página só não envia. Some
                    // enquanto o campo estiver oculto.
                    $field['required'] = false;
                }
            }

            // Unresolved include reference ({"group": "x"} with no
            // type/name) — controllers normally expand these through
            // Config::resolveFields() before rendering, but if a caller
            // passes raw defs, skipping beats rendering an orphan text
            // input named `fields[]`.
            if (isset($field['group']) && !isset($field['type']) && !isset($field['name'])) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'group' || $type === 'tabs') {
                // Containers receive the whole flat values array — their
                // children resolve their own values from it.
                $html .= self::render($field, $values, $namePrefix);
                continue;
            }
            $name = self::nameOf($field);
            $html .= self::render($field, $values[$name] ?? null, $namePrefix);
        }
        return $html;
    }

    /**
     * Render a field input given its definition, current value, and form name prefix.
     * For `group`/`tabs` containers, $value must be the FLAT values array of the
     * surrounding context (renderFields passes it automatically).
     */
    public static function render(array $field, mixed $value, string $namePrefix = 'fields'): string
    {
        $type = (string) ($field['type'] ?? 'text');

        // Organizational containers produce their own wrapper markup
        // (no <label for>, no single input) — early return, skipping
        // the standard field chrome below.
        if ($type === 'group') {
            return self::renderGroup($field, is_array($value) ? $value : [], $namePrefix);
        }
        if ($type === 'tabs') {
            return self::renderTabs($field, is_array($value) ? $value : [], $namePrefix);
        }

        $name = self::nameOf($field);
        $label = (string) ($field['label'] ?? $name);
        $help = (string) ($field['help'] ?? '');
        $required = (bool) ($field['required'] ?? false);
        $inputName = $namePrefix . '[' . $name . ']';
        $inputId = preg_replace('/[^a-z0-9_]/i', '_', $namePrefix . '_' . $name) ?? $name;

        $body = match ($type) {
            'text' => self::renderInput('text', $inputName, $inputId, $value, $field),
            'email' => self::renderInput('email', $inputName, $inputId, $value, $field),
            'url' => self::renderInput('url', $inputName, $inputId, $value, $field),
            'number' => self::renderInput('number', $inputName, $inputId, $value, $field),
            'date' => self::renderInput('date', $inputName, $inputId, $value, $field),
            'textarea' => self::renderTextarea($inputName, $inputId, $value, $field),
            'richtext' => self::renderRichText($inputName, $inputId, $value, $field),
            'image' => self::renderImage($inputName, $inputId, $value, $field),
            'file' => self::renderFile($inputName, $inputId, $value, $field),
            'gallery' => self::renderGallery($inputName, $inputId, $value, $field),
            'select' => self::renderSelect($inputName, $inputId, $value, $field),
            'boolean' => self::renderBoolean($inputName, $inputId, $value, $field),
            'repeater' => self::renderRepeater($inputName, $inputId, $value, $field),
            'flexible' => self::renderFlexible($inputName, $inputId, $value, $field),
            'link' => self::renderLink($inputName, $inputId, $value, $field),
            'item' => self::renderItemField($inputName, $inputId, $value, $field),
            default => self::renderInput('text', $inputName, $inputId, $value, $field),
        };

        $helpHtml = $help !== '' ? '<p class="field__help">' . e($help) . '</p>' : '';
        $required = $required ? ' <span class="field__required">*</span>' : '';

        // Visibilidade condicional. A condição viaja no atributo para o JS
        // reavaliar a cada digitação; o `hidden` já vem resolvido do servidor
        // (ver renderFields). O `required` original vai junto porque o JS
        // precisa devolvê-lo quando o campo reaparecer — sem isso, um campo
        // obrigatório que some uma vez deixa de ser obrigatório para sempre.
        $conditional = '';
        if (isset($field['__conditional']) && is_array($field['__conditional'])) {
            $conditional = ' data-show-if="' . e((string) json_encode(
                $field['__conditional'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )) . '"';
            if (!empty($field['__hidden'])) {
                $conditional .= ' hidden';
            }
            if (!empty($field['__required_original'])) {
                $conditional .= ' data-required-when-visible="1"';
            }
        }

        return sprintf(
            '<div class="field field--%s" data-field-type="%s" data-field-name="%s"%s>
                <label class="field__label" for="%s">%s%s</label>
                <div class="field__control">%s</div>
                %s
            </div>',
            e($type),
            e($type),
            e($name),
            $conditional,
            e($inputId),
            e($label),
            $required,
            $body,
            $helpHtml
        );
    }

    /**
     * Collect POSTed values into a clean array, applying field definitions.
     *
     * `group` and `tabs` containers are transparent here: their child
     * fields are collected into the SAME flat result as everything
     * else, keyed by the child's own name. The container itself never
     * becomes a storage key — which is what makes the wrappers safe to
     * add/remove in site.json without touching saved data.
     */
    public static function collect(array $fieldDefs, mixed $postData): array
    {
        if (!is_array($postData)) {
            $postData = [];
        }

        $result = [];
        foreach ($fieldDefs as $field) {
            if (!is_array($field)) continue;
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'group') {
                $result = array_merge($result, self::collect((array) ($field['fields'] ?? []), $postData));
                continue;
            }
            if ($type === 'tabs') {
                foreach ((array) ($field['tabs'] ?? []) as $tab) {
                    if (!is_array($tab)) continue;
                    $result = array_merge($result, self::collect((array) ($tab['fields'] ?? []), $postData));
                }
                continue;
            }

            $name = self::nameOf($field);
            if ($name === '') continue;
            $raw = $postData[$name] ?? null;

            $result[$name] = match ($type) {
                'boolean' => !empty($raw) && $raw !== '0',
                'number' => $raw === null || $raw === '' ? null : (is_numeric($raw) ? $raw + 0 : null),
                'repeater' => self::collectRepeater($field, $raw),
                'flexible' => self::collectFlexible($field, $raw),
                'gallery' => self::collectGallery($raw),
                'link' => self::collectLink($raw),
                'item' => self::collectItem($raw),
                // Os demais tipos guardam um valor só (texto, mídia, data,
                // opção): uma lista ali é dado de outro tipo — o campo de um
                // bloco que era repetidor e virou texto — e chega vazia.
                default => is_array($raw) ? null : $raw,
            };
        }
        return $result;
    }

    /**
     * Reduz o bundle coletado de UM idioma às traduções que o editor de
     * fato mexeu — para o fallback de fieldIn()/fieldsIn() ficar limpo e o
     * JSON de traduções não guardar cópias inúteis do idioma padrão.
     *
     * Regra (conservadora):
     *   1. Valor "vazio" para o TIPO do campo → descartado. É type-aware de
     *      propósito: um image/file sem mídia chega como "0", enquanto um
     *      text com o literal "0" é conteúdo válido. O filtro antigo
     *      (`$v !== '' && $v !== null && $v !== []`) não distinguia os dois
     *      e ainda deixava passar o "0" da imagem e — pior — o `false` de
     *      todo boolean, congelando-o em cada idioma a cada save.
     *   2. boolean nunca é "vazio" (é sempre true/false): o único sinal de
     *      que NÃO houve tradução é ser igual ao valor do idioma padrão, então
     *      só é gravado quando difere do default.
     *   3. Demais tipos preenchidos são mantidos mesmo quando iguais ao
     *      default (escolha conservadora — não altera o comportamento atual
     *      de text/number/etc.; só limpa os vazios e o boolean).
     *
     * @param array<int,mixed>    $fieldDefs  as MESMAS defs passadas a collect()
     * @param array<string,mixed> $translated saída de collect() para o idioma salvo
     * @param array<string,mixed> $default    saída de collect() para o idioma padrão
     * @return array<string,mixed>
     */
    public static function translationOverrides(array $fieldDefs, array $translated, array $default): array
    {
        $types = self::leafFieldTypes($fieldDefs);

        $result = [];
        foreach ($translated as $name => $value) {
            $type = $types[$name] ?? 'text';

            // (1) Vazio para o tipo → cai no fallback do idioma padrão.
            if (self::isEmptyTranslationValue($type, $value)) {
                continue;
            }
            // (2) boolean sem estado "vazio": só é override quando difere.
            if ($type === 'boolean'
                && array_key_exists($name, $default)
                && $value === $default[$name]) {
                continue;
            }

            $result[$name] = $value;
        }
        return $result;
    }

    /**
     * "Vazio" por tipo — o valor que collect() produz quando o input não
     * foi preenchido. boolean é o único que nunca é vazio (o caso dele é
     * tratado em translationOverrides comparando com o default).
     *
     * O richtext tem o vazio dele: o editor do painel nunca envia '', e sim
     * `<p></p>`. Sem essa linha, toda aba de tradução em branco gravava um
     * parágrafo vazio que escondia o texto do idioma padrão.
     */
    private static function isEmptyTranslationValue(string $type, mixed $value): bool
    {
        return match ($type) {
            'boolean'                         => false,
            'number', 'link', 'item'          => $value === null,
            'image', 'file'                   => !is_numeric($value) || (int) $value <= 0,
            'gallery', 'repeater', 'flexible' => !is_array($value) || $value === [],
            'richtext'                        => $value === '' || $value === null || Richtext::isEmptyMarkup($value),
            // text, email, url, date, textarea, select
            default                           => $value === '' || $value === null,
        };
    }

    /**
     * Mapa name => type dos campos-folha, espelhando a travessia de
     * collect(): group/tabs são transparentes (os filhos herdam o escopo
     * plano), os demais tipos — inclusive repeater/flexible — são folhas
     * cujo valor ocupa uma única chave no bundle.
     *
     * @param array<int,mixed> $fieldDefs
     * @return array<string,string>
     */
    private static function leafFieldTypes(array $fieldDefs): array
    {
        $types = [];
        foreach ($fieldDefs as $field) {
            if (!is_array($field)) continue;
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'group') {
                $types = array_merge($types, self::leafFieldTypes((array) ($field['fields'] ?? [])));
                continue;
            }
            if ($type === 'tabs') {
                foreach ((array) ($field['tabs'] ?? []) as $tab) {
                    if (!is_array($tab)) continue;
                    $types = array_merge($types, self::leafFieldTypes((array) ($tab['fields'] ?? [])));
                }
                continue;
            }

            $name = self::nameOf($field);
            if ($name === '') continue;
            $types[$name] = $type;
        }
        return $types;
    }

    /**
     * Normaliza o sub-array POSTado de um campo `link`.
     * URL vazia ⇒ null (link vazio); senão objeto {title, url, new_tab}.
     * Pura — testável sem bootstrap. Ordem das chaves fixa (title, url, new_tab).
     */
    private static function collectLink(mixed $raw): ?array
    {
        $raw = is_array($raw) ? $raw : [];
        $url = trim((string) ($raw['url'] ?? ''));
        // URL vazia OU com esquema perigoso (javascript:, data:, …) ⇒ link
        // vazio. Barra o stored-XSS já na escrita; link_tag() reforça na leitura.
        if ($url === '' || !is_safe_url($url)) {
            return null;
        }
        return [
            'title' => trim((string) ($raw['title'] ?? '')),
            'url' => $url,
            'new_tab' => !empty($raw['new_tab']) && $raw['new_tab'] !== '0',
        ];
    }

    /**
     * Normaliza o valor POSTado de um campo `item` para o id do item
     * (int positivo) ou null. Puro, sem lookup de banco — mesma política
     * de image/gallery (que só coagem o id).
     */
    private static function collectItem(mixed $raw): ?int
    {
        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * Group — a collapsible, purely organizational box around fields.
     *
     * Implemented as native <details>/<summary>: collapse/expand needs
     * zero JS (so it also works instantly inside freshly-cloned
     * repeater rows), it's keyboard-accessible for free, and hidden
     * children still submit their inputs (display:none doesn't affect
     * form submission). Groups start CLOSED by default — same as
     * repeater/flexible rows — keeping long edit pages scannable;
     * `"open": true` in the def starts one expanded.
     *
     * Visual identity is deliberately distinct from repeater/flexible
     * rows (see .field-group in admin.css): those represent DATA
     * (rows that exist in storage), a group is just chrome.
     */
    /**
     * O chevron de qualquer `<details>` do admin — acordeão de campos, seção
     * de conversão, cartão de lead. Fonte única de propósito: quando o ícone
     * existia solto em cada view, a lista de submissões acabou com um `▾`
     * digitado à mão, que não girava ao abrir e mudava de forma conforme a
     * fonte do sistema.
     *
     * A rotação é CSS (`.chevron`, girado por `details[open] > summary`), não
     * markup — por isso o mesmo HTML serve aberto e fechado.
     */
    public static function chevron(): string
    {
        return '<span class="chevron" aria-hidden="true">'
            . '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">'
            . '<path d="M3 5l3 3 3-3" stroke="currentColor" stroke-width="1.5" '
            . 'stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
    }

    private static function renderGroup(array $field, array $values, string $namePrefix): string
    {
        $label = (self::labelOf($field) ?? __('fields.group_default_label'));
        $open = ($field['open'] ?? false) === true;
        $subFields = (array) ($field['fields'] ?? []);

        return sprintf(
            '<details class="field-group" data-field-group%s>
                <summary class="field-group__summary">
                    %s
                    <span class="field-group__label">%s</span>
                </summary>
                <div class="field-group__body">%s</div>
            </details>',
            $open ? ' open' : '',
            self::chevron(),
            e($label),
            self::renderFields($subFields, $values, $namePrefix)
        );
    }

    /**
     * Tabs — organizational tab strip over field sets.
     *
     * Tab/panel pairing is ORDINAL (button N activates panel N within
     * this container), not id-based — so the markup survives base64
     * template cloning inside repeaters without any id de-duplication.
     * Switching is handled by a single delegated click listener in
     * admin.js (no per-instance init → dynamically inserted rows work
     * without re-binding). Inactive panels are display:none but their
     * inputs still submit, so values in non-visible tabs are never lost.
     *
     * All tabs share the surrounding flat storage scope — two tabs
     * cannot both define a field named "title" (schema:validate flags
     * that as a duplicate).
     */
    private static function renderTabs(array $field, array $values, string $namePrefix): string
    {
        $tabs = (array) ($field['tabs'] ?? []);

        $navHtml = '';
        $panelsHtml = '';
        $i = 0;
        foreach ($tabs as $tab) {
            if (!is_array($tab)) continue;
            $tabLabel = (string) ($tab['label'] ?? __('fields.tab_default_label', ['n' => $i + 1]));
            $active = $i === 0 ? ' is-active' : '';
            $navHtml .= sprintf(
                '<button type="button" class="field-tabs__tab%s" data-field-tab role="tab" aria-selected="%s">%s</button>',
                $active,
                $i === 0 ? 'true' : 'false',
                e($tabLabel)
            );
            $panelsHtml .= sprintf(
                '<div class="field-tabs__panel%s" data-field-tab-panel role="tabpanel">%s</div>',
                $active,
                self::renderFields((array) ($tab['fields'] ?? []), $values, $namePrefix)
            );
            $i++;
        }

        return sprintf(
            '<div class="field-tabs" data-field-tabs>
                <div class="field-tabs__nav" role="tablist">%s</div>
                <div class="field-tabs__panels">%s</div>
            </div>',
            $navHtml,
            $panelsHtml
        );
    }

    /**
     * Structural validation for a fields list — used by `schema:validate`.
     *
     * Walks the tree tracking one FLAT NAME SCOPE per storage context:
     * group/tabs children live in the surrounding scope (their values
     * land in the same JSON object), while repeater fields and each
     * flexible layout open fresh scopes (their values nest inside
     * rows). Duplicate names within a scope are flagged because the
     * later field would silently overwrite the earlier one's value on
     * save — with containers, that's an easy mistake to make (e.g. a
     * "title" inside the Hero group AND a top-level "title").
     *
     * @return list<string> human-readable issues, empty when valid
     */
    public static function validateFields(array $fields, string $path): array
    {
        $issues = [];
        $seen = [];
        self::walkValidate($fields, $path, $seen, $issues);

        // `show_if` é validado DEPOIS do walk, com `$seen` já preenchido: a
        // condição pode apontar para um campo declarado mais abaixo, ou
        // dentro de um group/tabs — todos compartilham o mesmo escopo plano.
        // Validar durante o walk reprovaria referência para a frente.
        self::validateShowIf($fields, $path, array_keys($seen), $issues);

        return $issues;
    }

    /**
     * Confere as condições `show_if` contra os nomes do mesmo escopo.
     *
     * Existe porque a falha em runtime é muda: `matchesShowIf()` trata
     * condição malformada como "sempre visível", e uma que aponta para campo
     * inexistente compara contra `null` — o campo some da tela e nada explica
     * o motivo.
     *
     * @param list<string> $scopeNames
     */
    private static function validateShowIf(array $fields, string $path, array $scopeNames, array &$issues): void
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');
            $name = self::nameOf($field, '?');

            // Contêineres abrem escopo próprio para os filhos — repeater e
            // flexible — ou compartilham o de fora — group e tabs.
            if ($type === 'repeater' && is_array($field['fields'] ?? null)) {
                $inner = [];
                self::collectNames($field['fields'], $inner);
                self::validateShowIf($field['fields'], "{$path} > repeater \"{$name}\"", $inner, $issues);
                continue;
            }
            if ($type === 'flexible') {
                foreach ((array) ($field['layouts'] ?? []) as $lk => $ld) {
                    if (!is_array($ld) || !is_array($ld['fields'] ?? null)) continue;
                    $inner = [];
                    self::collectNames($ld['fields'], $inner);
                    self::validateShowIf($ld['fields'], "{$path} > flexible \"{$name}\" layout \"{$lk}\"", $inner, $issues);
                }
                continue;
            }
            if ($type === 'group' && is_array($field['fields'] ?? null)) {
                self::validateShowIf($field['fields'], $path, $scopeNames, $issues);
                continue;
            }
            if ($type === 'tabs') {
                foreach ((array) ($field['tabs'] ?? []) as $tab) {
                    if (!is_array($tab) || !is_array($tab['fields'] ?? null)) continue;
                    self::validateShowIf($tab['fields'], $path, $scopeNames, $issues);
                }
                continue;
            }

            if (!array_key_exists('show_if', $field)) {
                continue;
            }
            $where = "{$path}: field \"{$name}\" show_if";
            $cond = $field['show_if'];

            if (!is_array($cond)) {
                $issues[] = "{$where}: must be an object like {\"field\": \"outro\", \"equals\": true}.";
                continue;
            }
            $target = $cond['field'] ?? null;
            if (!is_string($target) || $target === '') {
                $issues[] = "{$where}: \"field\" is required (the name of the field it depends on).";
                continue;
            }
            if ($target === $name) {
                $issues[] = "{$where}: cannot depend on itself.";
                continue;
            }
            if (!in_array($target, $scopeNames, true)) {
                $issues[] = "{$where}: \"{$target}\" is not a field in the same scope. "
                    . 'A condition can only reference a sibling (group/tabs are transparent; '
                    . 'a repeater row is its own scope).';
            }
            $ops = array_values(array_intersect(self::SHOW_IF_OPS, array_keys($cond)));
            if ($ops === []) {
                $issues[] = "{$where}: needs one of: " . implode(', ', self::SHOW_IF_OPS) . '.';
            } elseif (count($ops) > 1) {
                $issues[] = "{$where}: use only one of " . implode(', ', self::SHOW_IF_OPS) . '.';
            } elseif ($ops[0] === 'in' && !is_array($cond['in'])) {
                $issues[] = "{$where}: \"in\" must be a list of values.";
            }
        }
    }

    /** Nomes de campo de um escopo plano (desce em group/tabs). */
    private static function collectNames(array $fields, array &$out): void
    {
        foreach ($fields as $field) {
            if (!is_array($field)) continue;
            $type = (string) ($field['type'] ?? 'text');
            if ($type === 'group') {
                self::collectNames((array) ($field['fields'] ?? []), $out);
                continue;
            }
            if ($type === 'tabs') {
                foreach ((array) ($field['tabs'] ?? []) as $tab) {
                    if (is_array($tab)) self::collectNames((array) ($tab['fields'] ?? []), $out);
                }
                continue;
            }
            if (isset($field['name'])) $out[] = (string) $field['name'];
        }
    }

    private static function walkValidate(array $fields, string $path, array &$seen, array &$issues): void
    {
        foreach ($fields as $i => $field) {
            if (!is_array($field)) {
                $issues[] = "{$path}: field #{$i} is not an object.";
                continue;
            }

            // `{"group": "name"}` (no type) is the REUSABLE-INCLUDE
            // syntax — Config::resolveFields() expands it inline from
            // site.json → field_groups before rendering. Distinct from
            // `{"type": "group"}` (the visual container). Skip here;
            // callers that want the real picture validate the RESOLVED
            // list (schema:validate does).
            if (isset($field['group']) && !isset($field['type']) && !isset($field['name'])) {
                continue;
            }

            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'group') {
                $label = (self::labelOf($field) ?? "#{$i}");
                $sub = $field['fields'] ?? null;
                if (!is_array($sub) || $sub === []) {
                    $issues[] = "{$path}: group \"{$label}\" needs a non-empty 'fields' array.";
                    continue;
                }
                // Same $seen — group children share the parent's flat scope.
                self::walkValidate($sub, "{$path} > group \"{$label}\"", $seen, $issues);
                continue;
            }

            if ($type === 'tabs') {
                $tabs = $field['tabs'] ?? null;
                if (!is_array($tabs) || $tabs === []) {
                    $issues[] = "{$path}: tabs #{$i} needs a non-empty 'tabs' array.";
                    continue;
                }
                foreach ($tabs as $j => $tab) {
                    $tabLabel = is_array($tab) ? (string) ($tab['label'] ?? "#{$j}") : "#{$j}";
                    if (!is_array($tab) || !is_array($tab['fields'] ?? null)) {
                        $issues[] = "{$path}: tab \"{$tabLabel}\" needs a 'fields' array.";
                        continue;
                    }
                    // Same $seen — ALL tabs share the parent's flat scope.
                    self::walkValidate($tab['fields'], "{$path} > tab \"{$tabLabel}\"", $seen, $issues);
                }
                continue;
            }

            $name = self::nameOf($field);
            if ($name === '') {
                $issues[] = "{$path}: field #{$i} is missing 'name'.";
                continue;
            }
            if (isset($seen[$name])) {
                $issues[] = "{$path}: duplicate field name \"{$name}\" — fields inside groups/tabs share the same flat storage as their siblings.";
            }
            $seen[$name] = true;

            // Campo file: `accept`, quando presente, precisa ser uma lista
            // não-vazia de categorias conhecidas — um typo no site.json
            // viraria silenciosamente um picker sem filtro.
            if ($type === 'file' && array_key_exists('accept', $field)) {
                $accept = $field['accept'];
                if (!is_array($accept) || $accept === [] || array_diff($accept, self::FILE_CATEGORIES) !== []) {
                    $issues[] = "{$path}: campo file \"{$name}\" tem 'accept' inválido — use uma lista com: "
                        . implode(', ', self::FILE_CATEGORIES) . '.';
                }
            }

            // Repeater subfields and flexible layouts nest inside rows —
            // each opens a fresh name scope.
            if ($type === 'repeater' && is_array($field['fields'] ?? null)) {
                foreach (self::validateFields($field['fields'], "{$path} > repeater \"{$name}\"") as $issue) {
                    $issues[] = $issue;
                }

                // `title_field` rotula a linha em vez de "Item 01". Validado
                // aqui porque em runtime um nome errado (ou um tipo que não
                // vira texto) apenas volta pro contador, e a configuração
                // parece ter sido ignorada de graça.
                if (array_key_exists('title_field', $field)) {
                    $titleField = $field['title_field'];
                    $where = "{$path} > repeater \"{$name}\"";
                    if (!is_string($titleField) || $titleField === '') {
                        $issues[] = "{$where}: 'title_field' must be a non-empty subfield name.";
                    } else {
                        $def = self::findSubField($field['fields'], $titleField);
                        if ($def === null) {
                            $issues[] = "{$where}: 'title_field' points to \"{$titleField}\", which is not one of its subfields.";
                        } elseif (!in_array((string) ($def['type'] ?? 'text'), self::ROW_TITLE_TYPES, true)) {
                            $issues[] = "{$where}: 'title_field' points to \"{$titleField}\" of type \""
                                . (string) ($def['type'] ?? 'text') . "\", which cannot label a row. Use one of: "
                                . implode(', ', self::ROW_TITLE_TYPES) . '.';
                        }
                    }
                }
            }
            if ($type === 'flexible') {
                foreach ((array) ($field['layouts'] ?? []) as $layoutKey => $layoutDef) {
                    if (!is_array($layoutDef) || !is_array($layoutDef['fields'] ?? null)) continue;
                    foreach (self::validateFields($layoutDef['fields'], "{$path} > flexible \"{$name}\" layout \"{$layoutKey}\"") as $issue) {
                        $issues[] = $issue;
                    }
                }
            }
        }
    }

    private static function collectRepeater(array $field, mixed $raw): array
    {
        $rows = [];
        if (!is_array($raw)) return $rows;

        $subFields = (array) ($field['fields'] ?? []);
        foreach ($raw as $key => $row) {
            // M1: skip the sentinel row for the fresh-row <template> — must
            // match what renderRepeater() actually stamps (renderRepeaterRow(
            // $name, '__INDEX__', ...) at the template line, same sentinel
            // collectFlexible() already checks). '__template__' was never
            // emitted, so this guard never fired.
            if ($key === '__INDEX__') continue;
            if (!is_array($row)) continue;
            $rows[] = self::collect($subFields, $row);
        }
        return array_values($rows);
    }

    /**
     * Flexible content — repeater where each row picks its own layout
     * (a named group of fields, defined upfront). Same shape as ACF
     * Pro's Flexible Content. Storage is a list of dicts each starting
     * with `_layout` so the theme can switch on it:
     *
     *   [
     *     {"_layout": "hero",     "title": "...",  "image": 12},
     *     {"_layout": "two_cols", "left":  "...",  "right": "..."},
     *     {"_layout": "hero",     "title": "..." , "image": 5}
     *   ]
     *
     * Rows referencing layouts no longer in the config are silently
     * dropped on save (self-heal). The reserved key `_layout` cannot
     * be used as a subfield name inside a layout.
     */
    private static function collectFlexible(array $field, mixed $raw): array
    {
        $rows = [];
        if (!is_array($raw)) return $rows;

        $layouts = (array) ($field['layouts'] ?? []);

        foreach ($raw as $key => $row) {
            if ($key === '__INDEX__') continue;
            if (!is_array($row)) continue;

            $layoutKey = (string) ($row['_layout'] ?? '');
            $layoutDef = $layouts[$layoutKey] ?? null;
            if ($layoutDef === null) continue;

            $subFields = (array) ($layoutDef['fields'] ?? []);
            $collected = self::collect($subFields, $row);
            // Store the layout marker first so it's visually obvious
            // when inspecting the JSON column or theme dump.
            $rows[] = ['_layout' => $layoutKey] + $collected;
        }
        return array_values($rows);
    }

    private static function renderInput(string $htmlType, string $name, string $id, mixed $value, array $field): string
    {
        $required = !empty($field['required']) ? ' required' : '';
        $placeholder = isset($field['placeholder']) ? ' placeholder="' . e((string) $field['placeholder']) . '"' : '';
        return sprintf(
            '<input class="input" type="%s" name="%s" id="%s" value="%s"%s%s>',
            e($htmlType),
            e($name),
            e($id),
            e($value),
            $placeholder,
            $required
        );
    }

    private static function renderTextarea(string $name, string $id, mixed $value, array $field): string
    {
        $rows = (int) ($field['rows'] ?? 4);
        return sprintf(
            '<textarea class="input input--textarea" name="%s" id="%s" rows="%d">%s</textarea>',
            e($name),
            e($id),
            $rows,
            e($value)
        );
    }

    private static function renderRichText(string $name, string $id, mixed $value, array $field): string
    {
        // Toolbar button copy is UI chrome (not schema data), so it's
        // translated via __() under the fields.richtext_* keys. bulletList/
        // blockquote/link/image intentionally reuse the same translated word for both
        // the `title` tooltip and the visible button text — mirrors the
        // original PT markup (e.g. title="Lista" and text "• Lista" were
        // already the same word).
        $bold = e(__('fields.richtext_bold'));
        $italic = e(__('fields.richtext_italic'));
        $strike = e(__('fields.richtext_strike'));
        $paragraph = e(__('fields.richtext_paragraph'));
        $h2 = e(__('fields.richtext_h2'));
        $h3 = e(__('fields.richtext_h3'));
        $list = e(__('fields.richtext_list'));
        $orderedList = e(__('fields.richtext_ordered_list'));
        $quote = e(__('fields.richtext_blockquote'));
        $link = e(__('fields.richtext_link'));
        $image = e(__('fields.richtext_image'));
        $undo = e(__('fields.richtext_undo'));
        $redo = e(__('fields.richtext_redo'));
        $code = e(__('fields.richtext_code'));
        $codeBlock = e(__('fields.richtext_code_block'));
        $table = e(__('fields.richtext_table'));
        $addRow = e(__('fields.richtext_table_add_row'));
        $delRow = e(__('fields.richtext_table_del_row'));
        $addCol = e(__('fields.richtext_table_add_col'));
        $delCol = e(__('fields.richtext_table_del_col'));
        $delTable = e(__('fields.richtext_table_delete'));

        // Os blocos do tema que este campo aceita (site.json → richtext_blocks, e a
        // lista `blocks` do campo). Só com algum declarado a barra ganha o
        // botão, e o editor recebe o que precisa para abrir a janela do
        // bloco: a lista, o endereço e o token de CSRF.
        $blocks = [];
        $listed = $field['blocks'] ?? null;
        foreach (is_array($listed) && array_is_list($listed) ? $listed : [] as $key) {
            $def = is_string($key) ? App::instance()->config->richtextBlock($key) : null;
            if ($def !== null) {
                $blocks[$key] = ['key' => $key, 'label' => (string) ($def['label'] ?? $key)];
            }
        }
        $blockButton = '';
        $blockAttrs = '';
        $blockTools = '';
        if ($blocks !== []) {
            $block = e(__('fields.richtext_block'));
            // "+ Bloco" abre e fecha uma faixa embaixo da barra com os blocos
            // do campo (antes, uma janela de escolha); cada um abre direto a
            // janela com os campos dele.
            $blockButton = '<button type="button" data-cmd="block" title="' . $block . '" aria-expanded="false">+ ' . $block . '</button>';
            $blockTools = '<div class="richtext__toolbar-row richtext__toolbar-row--blocks" data-block-tools role="group" aria-label="' . $block . '" hidden>';
            foreach ($blocks as $b) {
                $blockTools .= '<button type="button" data-cmd="insertBlock" data-block="' . e($b['key']) . '">' . e($b['label']) . '</button>';
            }
            $blockTools .= '</div>';
            $blockAttrs = sprintf(
                ' data-blocks="%s" data-blocks-url="%s" data-blocks-csrf="%s"',
                e((string) json_encode(array_values($blocks), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                e(admin_url('blocks')),
                e(csrf_token())
            );
        }

        $toolbar = '<button type="button" data-cmd="bold" title="' . $bold . '"><strong>B</strong></button>'
            . '<button type="button" data-cmd="italic" title="' . $italic . '"><em>I</em></button>'
            . '<button type="button" data-cmd="strike" title="' . $strike . '"><s>S</s></button>'
            . '<button type="button" data-cmd="paragraph" title="' . $paragraph . '">P</button>'
            . '<button type="button" data-cmd="h2" title="' . $h2 . '">H2</button>'
            . '<button type="button" data-cmd="h3" title="' . $h3 . '">H3</button>'
            . '<button type="button" data-cmd="bulletList" title="' . $list . '">• ' . $list . '</button>'
            . '<button type="button" data-cmd="orderedList" title="' . $orderedList . '">1. ' . $list . '</button>'
            . '<button type="button" data-cmd="blockquote" title="' . $quote . '">' . $quote . '</button>'
            . '<button type="button" data-cmd="link" title="' . $link . '">' . $link . '</button>'
            . '<button type="button" data-cmd="image" title="' . $image . '">' . $image . '</button>'
            . '<button type="button" data-cmd="code" title="' . $code . '"><code>&lt;/&gt;</code></button>'
            . '<button type="button" data-cmd="codeBlock" title="' . $codeBlock . '"><code>{ }</code></button>'
            . '<button type="button" data-cmd="insertTable" title="' . $table . '">' . $table . '</button>'
            . $blockButton
            . '<button type="button" data-cmd="undo" title="' . $undo . '">↶</button>'
            . '<button type="button" data-cmd="redo" title="' . $redo . '">↷</button>';

        // As operações da tabela numa faixa própria, embaixo, que só aparece
        // com o cursor numa tabela (updateToolbarState, admin.js). Fora dela
        // eram cinco botões apagados e de nome cifrado (+R, −C…) no meio da
        // barra; aqui cabem os nomes por extenso.
        $tableTools = '<button type="button" data-cmd="addRowAfter">' . $addRow . '</button>'
            . '<button type="button" data-cmd="deleteRow">' . $delRow . '</button>'
            . '<button type="button" data-cmd="addColumnAfter">' . $addCol . '</button>'
            . '<button type="button" data-cmd="deleteColumn">' . $delCol . '</button>'
            . '<button type="button" data-cmd="deleteTable">' . $delTable . '</button>';

        return sprintf(
            '<div class="richtext" data-richtext%s>
                <div class="richtext__toolbar" data-richtext-toolbar><div class="richtext__toolbar-row">%s</div>%s<div class="richtext__toolbar-row richtext__toolbar-row--table" data-table-tools role="group" aria-label="%s" hidden>%s</div></div>
                <div class="richtext__editor" data-richtext-editor></div>
                <textarea class="richtext__source" name="%s" id="%s" hidden>%s</textarea>
            </div>',
            $blockAttrs,
            $toolbar,
            $blockTools,
            $table,
            $tableTools,
            e($name),
            e($id),
            e($value)
        );
    }

    private static function renderImage(string $name, string $id, mixed $value, array $field): string
    {
        $mediaId = is_numeric($value) ? (int) $value : 0;
        $media = $mediaId > 0 ? \Velk\Models\Media::find($mediaId) : null;

        $previewHtml = '';
        $hasMedia = $media !== null;
        if ($media !== null) {
            $previewSrc = $media->isImage() ? $media->url('thumb') : '';
            $previewHtml = sprintf(
                '<div class="image-field__current">
                    %s
                    <div class="image-field__meta">
                        <strong>%s</strong>
                        <span class="muted">%s · %s%s</span>
                    </div>
                </div>',
                $previewSrc !== ''
                    ? '<img class="image-field__current-img" src="' . e($previewSrc) . '" alt="">'
                    : '<div class="image-field__current-doc">' . e(strtoupper(pathinfo($media->filename, PATHINFO_EXTENSION))) . '</div>',
                e($media->originalName),
                e($media->humanSize()),
                e($media->mime),
                $media->width && $media->height ? ' · ' . $media->width . '×' . $media->height : ''
            );
        }

        return sprintf(
            '<div class="image-field" data-image-field>
                <input type="hidden" name="%s" id="%s" value="%s" data-image-field-input>
                <div class="image-field__preview" data-image-field-preview>%s</div>
                <div class="image-field__actions">
                    <button type="button" class="button button--small button--ghost" data-image-field-pick>%s</button>
                    <button type="button" class="button button--small" data-image-field-clear%s>%s</button>
                </div>
            </div>',
            e($name),
            e($id),
            e((string) $mediaId),
            $previewHtml,
            $hasMedia ? e(__('fields.image_change')) : e(__('fields.image_select')),
            $hasMedia ? '' : ' hidden',
            e(__('fields.remove'))
        );
    }

    /** Categorias aceitas pelo campo file (e pelo param ?accept= do picker). */
    private const FILE_CATEGORIES = ['image', 'video', 'audio', 'document', 'archive'];

    /**
     * Campo `file` — referência a QUALQUER mídia da biblioteca (espelho do
     * campo image, sem restrição a imagem). `accept` (opcional) limita as
     * categorias que o picker mostra. Guarda o media id num hidden.
     */
    private static function renderFile(string $name, string $id, mixed $value, array $field): string
    {
        $mediaId = is_numeric($value) ? (int) $value : 0;
        $media = $mediaId > 0 ? \Velk\Models\Media::find($mediaId) : null;
        $accept = array_values(array_intersect(
            array_map('strval', (array) ($field['accept'] ?? [])),
            self::FILE_CATEGORIES
        ));

        $previewHtml = '';
        $hasMedia = $media !== null;
        if ($media !== null) {
            $visual = match ($media->category()) {
                'image' => '<img class="file-field__thumb" src="' . e($media->url('thumb')) . '" alt="">',
                'video' => '<video class="file-field__thumb" src="' . e($media->url()) . '" preload="metadata" muted playsinline></video>',
                default => '<div class="file-field__doc">' . e(strtoupper(pathinfo($media->filename, PATHINFO_EXTENSION))) . '</div>',
            };
            $previewHtml = sprintf(
                '<div class="file-field__current">
                    %s
                    <div class="file-field__meta">
                        <strong>%s</strong>
                        <span class="muted">%s · %s</span>
                    </div>
                </div>',
                $visual,
                e($media->originalName),
                e($media->humanSize()),
                e($media->mime)
            );
        }

        return sprintf(
            '<div class="file-field" data-file-field data-accept="%s">
                <input type="hidden" name="%s" id="%s" value="%s" data-file-field-input>
                <div class="file-field__preview" data-file-field-preview>%s</div>
                <div class="file-field__actions">
                    <button type="button" class="button button--small button--ghost" data-file-field-pick>%s</button>
                    <button type="button" class="button button--small" data-file-field-clear%s>%s</button>
                </div>
            </div>',
            e(implode(',', $accept)),
            e($name),
            e($id),
            e((string) $mediaId),
            $previewHtml,
            $hasMedia ? e(__('fields.file_change')) : e(__('fields.file_select')),
            $hasMedia ? '' : ' hidden',
            e(__('fields.remove'))
        );
    }

    private static function renderGallery(string $name, string $id, mixed $value, array $field): string
    {
        $ids = self::normalizeGalleryValue($value);

        // Batch-load all referenced media in a single query (findMany
        // returns [id => Media]). Iterating the original $ids array
        // preserves the user-defined order; missing IDs are silently
        // dropped so the gallery self-heals if an image is deleted
        // from the media library.
        //
        // The browser submits hidden inputs in DOM order, so the saved
        // array order is whatever order the DOM has at submit time —
        // drag-and-drop in JS just reorders the DOM, no extra
        // bookkeeping needed.
        $mediaMap = $ids === [] ? [] : \Velk\Models\Media::findMany($ids);

        $itemsHtml = '';
        foreach ($ids as $mediaId) {
            $media = $mediaMap[$mediaId] ?? null;
            if ($media === null) continue;
            $itemsHtml .= self::renderGalleryItem($name, $media);
        }

        $emptyHidden = $itemsHtml === '' ? '' : ' hidden';

        return sprintf(
            '<div class="gallery-field" data-gallery-field data-gallery-name="%s">
                <div class="gallery-field__items" data-gallery-items>%s</div>
                <p class="gallery-field__empty" data-gallery-empty%s>%s</p>
                <div class="gallery-field__actions">
                    <button type="button" class="button button--small button--ghost" data-gallery-add>+ %s</button>
                </div>
            </div>',
            e($name),
            $itemsHtml,
            $emptyHidden,
            e(__('fields.gallery_empty')),
            e(__('fields.gallery_add'))
        );
    }

    /**
     * Render a single gallery thumbnail. Used at both initial render and as
     * a server-side reference for the JS template (the JS rebuilds this
     * shape when adding new items so server and client stay in sync).
     */
    private static function renderGalleryItem(string $name, \Velk\Models\Media $media): string
    {
        $thumb = $media->isImage() ? $media->url('thumb') : '';
        $visual = $thumb !== ''
            ? '<img class="gallery-field__thumb" src="' . e($thumb) . '" alt="' . e($media->alt ?? '') . '">'
            : '<div class="gallery-field__doc">' . e(strtoupper(pathinfo($media->filename, PATHINFO_EXTENSION))) . '</div>';

        // Inline SVG (matches the repeater/flexible close icons for
        // visual consistency across the admin).
        $iconClose = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';
        return sprintf(
            '<div class="gallery-field__item" data-gallery-item draggable="true">
                <input type="hidden" name="%s[]" value="%d">
                %s
                <button type="button" class="gallery-field__remove" data-gallery-remove aria-label="%s">%s</button>
            </div>',
            e($name),
            $media->id,
            $visual,
            e(__('fields.remove')),
            $iconClose
        );
    }

    /**
     * Filter raw POST payload down to a clean list of media IDs. Anything
     * non-numeric or zero is dropped — keeps the stored JSON tidy and
     * defends against tampering. Order is preserved.
     */
    private static function collectGallery(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }
        return $ids;
    }

    /**
     * Coerce a stored gallery value into a list of ints. Accepts arrays
     * (the normal case) and ignores stray non-numeric entries.
     */
    private static function normalizeGalleryValue(mixed $value): array
    {
        if (!is_array($value)) return [];
        $ids = [];
        foreach ($value as $v) {
            if (is_numeric($v) && (int) $v > 0) {
                $ids[] = (int) $v;
            }
        }
        return $ids;
    }

    private static function renderSelect(string $name, string $id, mixed $value, array $field): string
    {
        $options = (array) ($field['options'] ?? []);
        $html = '<select class="input input--select" name="' . e($name) . '" id="' . e($id) . '">';
        if (!empty($field['placeholder'])) {
            $html .= '<option value="">' . e((string) $field['placeholder']) . '</option>';
        }
        foreach ($options as $optValue => $optLabel) {
            $selected = ((string) $value === (string) $optValue) ? ' selected' : '';
            $html .= '<option value="' . e((string) $optValue) . '"' . $selected . '>' . e((string) $optLabel) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private static function renderBoolean(string $name, string $id, mixed $value, array $field): string
    {
        $checked = !empty($value) ? ' checked' : '';
        return sprintf(
            '<label class="boolean-field"><input type="checkbox" name="%s" id="%s" value="1"%s> <span>%s</span></label>',
            e($name),
            e($id),
            $checked,
            e((string) ($field['inline_label'] ?? __('fields.boolean_yes')))
        );
    }

    /**
     * Link — campo composto (título opcional + URL + abrir em nova aba).
     * Os 3 sub-inputs são nomeados sob o name do campo (`name[url]` etc.),
     * então o POST chega como sub-array que collectLink() normaliza.
     * Sem `required` no HTML: link vazio não pode travar o save da página.
     */
    private static function renderLink(string $name, string $id, mixed $value, array $field): string
    {
        $link = is_array($value) ? $value : [];
        $urlInput = self::renderInput('url', $name . '[url]', $id . '_url', $link['url'] ?? '', ['placeholder' => 'https://...']);
        $titleInput = self::renderInput('text', $name . '[title]', $id . '_title', $link['title'] ?? '', []);
        $newTabInput = self::renderBoolean($name . '[new_tab]', $id . '_new_tab', $link['new_tab'] ?? false, ['inline_label' => __('fields.link_open_new_tab')]);
        // "Abrir em nova aba" divide a linha com a URL porque é atributo DELA
        // — a mesma decisão que o título, que fica embaixo por ser texto livre
        // e precisar da largura toda.
        return sprintf(
            '<div class="link-field">
                <div class="link-field__row">
                    <label class="link-field__sub link-field__sub--grow"><span class="link-field__label">%s</span>%s</label>
                    <div class="link-field__sub link-field__sub--aside">%s</div>
                </div>
                <label class="link-field__sub"><span class="link-field__label">%s</span>%s</label>
            </div>',
            e(__('fields.link_url_label')),
            $urlInput,
            $newTabInput,
            e(__('fields.link_title_label')),
            $titleInput
        );
    }

    /**
     * Item — seletor (combobox com busca) de um item de um item type.
     * Guarda o id do item escolhido (igual ao image guarda o id da mídia).
     * As opções (somente publicados do tipo) são embutidas server-side; o
     * JS (initItemField) filtra client-side e abre o painel abaixo do campo.
     * O título do item selecionado é resolvido aqui (Item::find) pra aparecer
     * mesmo que ele tenha saído de "publicado" (sai do menu, fica no campo).
     */
    private static function renderItemField(string $name, string $id, mixed $value, array $field): string
    {
        $itemType = (string) ($field['item_type'] ?? '');
        $selectedId = is_numeric($value) ? (int) $value : 0;
        $selected = $selectedId > 0 ? \Velk\Models\Item::find($selectedId) : null;
        // A reference that no longer resolves (deleted item) or points at the
        // wrong type (stale/foreign id) counts as NO selection — the field
        // renders empty instead of re-submitting a dangling id or showing a
        // foreign title. An item that merely left "published" still resolves
        // and stays (find() ignores status), which is the intended behavior.
        if ($selected !== null && $itemType !== '' && $selected->type !== $itemType) {
            $selected = null;
        }
        $validId = $selected !== null ? $selectedId : 0;
        $selectedTitle = $selected !== null ? $selected->title : '';

        $options = $itemType !== '' ? \Velk\Models\Item::listAdmin($itemType, 'published') : [];
        usort($options, fn($a, $b) => strcasecmp($a->title, $b->title));

        $optionsHtml = '';
        foreach ($options as $opt) {
            $optionsHtml .= sprintf(
                '<button type="button" class="item-field__option" data-id="%d" data-title="%s">%s</button>',
                $opt->id,
                e($opt->title),
                e($opt->title)
            );
        }
        if ($optionsHtml === '') {
            $optionsHtml = '<p class="item-field__empty">' . e(__('fields.item_empty')) . '</p>';
        }

        return sprintf(
            '<div class="item-field" data-item-field>
                <input type="hidden" name="%s" id="%s" value="%s" data-item-field-input>
                <input type="text" class="input" value="%s" placeholder="%s" autocomplete="off" data-item-field-search>
                <button type="button" class="item-field__clear" data-item-field-clear%s aria-label="%s">&times;</button>
                <div class="item-field__menu" data-item-field-menu hidden>%s</div>
            </div>',
            e($name),
            e($id),
            e((string) ($validId > 0 ? $validId : '')),
            e($selectedTitle),
            e(__('fields.item_search_placeholder')),
            $validId > 0 ? '' : ' hidden',
            e(__('fields.item_clear')),
            $optionsHtml
        );
    }

    /**
     * Tipos de subcampo que podem rotular a linha de um repetidor.
     *
     * O critério é "o valor já é o texto que a pessoa leria". Ficaram de
     * fora: `image`/`gallery`/`file` (id ou metadados), `boolean` (sim/não
     * não distingue linha nenhuma), `richtext` (HTML — exigiria decidir como
     * degradar marcação), `item` (id que só vira texto com uma consulta ao
     * banco, e o renderer é puro), e os contêineres `repeater`/`flexible`/
     * `group`/`tabs`, que não têm valor próprio.
     */
    public const ROW_TITLE_TYPES = ['text', 'email', 'url', 'tel', 'number', 'textarea', 'select', 'link'];

    /** Corte do rótulo da linha. O CSS ainda aplica ellipsis no que sobra. */
    public const ROW_TITLE_MAX_LENGTH = 80;

    /**
     * Rótulo de UMA linha de repetidor, a partir do subcampo declarado em
     * `title_field`. String vazia quando não há nada aproveitável — e essa
     * é a parte que importa: o CSS só desenha "ITEM 01" quando o span está
     * VAZIO (`:empty`), então devolver '' devolve o contador, que se
     * renumera sozinho ao arrastar e ao excluir. Preencher o span com um
     * número aqui quebraria isso.
     *
     * `select` usa o RÓTULO da opção, não o valor — `{"br": "Brasil"}`
     * mostra "Brasil". O valor é chave de armazenamento; ninguém escolheu
     * "br" como texto de interface.
     *
     * `link` usa o título, e cai na URL quando ele está em branco: um link
     * sem título ainda identifica a linha melhor que "ITEM 03".
     *
     * @param array<string,mixed> $rowValues  valores planos da linha
     * @param list<array<string,mixed>> $subFields  defs dos campos da linha
     */
    public static function rowTitle(array $rowValues, array $subFields, ?string $titleField): string
    {
        if ($titleField === null || $titleField === '') {
            return '';
        }

        $def = self::findSubField($subFields, $titleField);
        if ($def === null || !in_array((string) ($def['type'] ?? 'text'), self::ROW_TITLE_TYPES, true)) {
            return '';
        }

        $raw = $rowValues[$titleField] ?? null;
        $type = (string) ($def['type'] ?? 'text');

        if ($type === 'link') {
            $link = is_array($raw) ? $raw : [];
            $text = is_string($link['title'] ?? null) && trim($link['title']) !== ''
                ? $link['title']
                : (string) ($link['url'] ?? '');
        } elseif ($type === 'select') {
            $options = (array) ($def['options'] ?? []);
            $key = is_scalar($raw) ? (string) $raw : '';
            $text = $key !== '' ? (string) ($options[$key] ?? $key) : '';
        } else {
            $text = is_scalar($raw) ? (string) $raw : '';
        }

        $text = trim($text);
        return $text === '' ? '' : mb_substr($text, 0, self::ROW_TITLE_MAX_LENGTH);
    }

    /**
     * Acha a def de um subcampo pelo nome, descendo em `group`/`tabs`.
     *
     * Descer é necessário porque esses contêineres são transparentes no
     * armazenamento: um campo dentro de um grupo grava no MESMO array plano
     * da linha. Se a busca parasse no primeiro nível, `title_field` apontando
     * pra um campo agrupado falharia mesmo com o valor ali, disponível.
     *
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>|null
     */
    private static function findSubField(array $fields, string $name): ?array
    {
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $type = (string) ($field['type'] ?? 'text');

            if ($type === 'group') {
                $found = self::findSubField((array) ($field['fields'] ?? []), $name);
                if ($found !== null) {
                    return $found;
                }
                continue;
            }
            if ($type === 'tabs') {
                foreach ((array) ($field['tabs'] ?? []) as $tab) {
                    if (!is_array($tab)) {
                        continue;
                    }
                    $found = self::findSubField((array) ($tab['fields'] ?? []), $name);
                    if ($found !== null) {
                        return $found;
                    }
                }
                continue;
            }

            if (self::nameOf($field) === $name) {
                return $field;
            }
        }
        return null;
    }

    private static function renderRepeater(string $name, string $id, mixed $value, array $field): string
    {
        $subFields = (array) ($field['fields'] ?? []);
        $rows = is_array($value) ? array_values($value) : [];

        // Qual subcampo rotula a linha. Renderizado JÁ no servidor, e não só
        // pelo JS: sem isso a lista pisca "ITEM 01" até o script rodar, e um
        // erro de JS em qualquer outro widget da página deixaria o repetidor
        // permanentemente numerado. O JS abaixo só mantém o rótulo em dia
        // enquanto a pessoa digita.
        $titleField = is_string($field['title_field'] ?? null) && $field['title_field'] !== ''
            ? (string) $field['title_field']
            : null;

        $rowsHtml = '';
        foreach ($rows as $i => $row) {
            $rowsHtml .= self::renderRepeaterRow($name, $i, $subFields, is_array($row) ? $row : [], $titleField);
        }

        // The template HTML for a fresh row goes inside a <template> tag.
        // We base64-encode it so:
        //   1. The outer markup never confuses the HTML parser (e.g. when a
        //      nested repeater's inner </template> would prematurely close us).
        //   2. The JS can decode and stamp __INDEX__ cleanly.
        $template = self::renderRepeaterRow($name, '__INDEX__', $subFields, [], $titleField);

        return sprintf(
            '<div class="repeater" data-repeater data-repeater-name="%s"%s>
                <div class="repeater__rows" data-repeater-rows>%s</div>
                <script type="text/template" data-repeater-template>%s</script>
                <button type="button" class="button button--small" data-repeater-add>+ %s</button>
            </div>',
            e($name),
            $titleField !== null ? ' data-repeater-title-field="' . e($titleField) . '"' : '',
            $rowsHtml,
            base64_encode($template),
            e(__('fields.repeater_add'))
        );
    }

    private static function renderRepeaterRow(string $name, int|string $index, array $subFields, array $values, ?string $titleField = null): string
    {
        $rowName = $name . '[' . $index . ']';
        // renderFields (not a manual loop) so group/tabs containers work
        // inside repeater rows too — children get the row's name prefix
        // and resolve values from the row's flat array.
        $body = self::renderFields($subFields, $values, $rowName);

        // Rows loaded from saved data start COLLAPSED so a long list
        // scans as an index instead of a wall of inputs. The blank
        // template row (index __INDEX__) stays expanded — when the user
        // clicks the "add" button they're about to type into it.
        $collapsed = $index !== '__INDEX__' ? ' is-collapsed' : '';

        // ASCII-safe SVG icons — same rationale as renderFlexibleRow.
        $iconHandle = '<svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor" aria-hidden="true"><circle cx="2" cy="3" r="1"/><circle cx="2" cy="7" r="1"/><circle cx="2" cy="11" r="1"/><circle cx="8" cy="3" r="1"/><circle cx="8" cy="7" r="1"/><circle cx="8" cy="11" r="1"/></svg>';
        $iconChevron = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 5l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $iconClose = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

        $toggleLabel = e(__('fields.toggle_collapse'));
        $removeLabel = e(__('fields.remove'));

        return sprintf(
            '<div class="repeater__row%s" data-repeater-row draggable="true">
                <div class="repeater__row-header" data-repeater-row-header>
                    <span class="repeater__row-handle" aria-hidden="true">%s</span>
                    <span class="repeater__row-title" data-repeater-row-title>%s</span>
                    <span class="repeater__row-spacer"></span>
                    <button type="button" class="repeater__row-toggle" data-repeater-toggle aria-label="%s" title="%s">%s</button>
                    <button type="button" class="repeater__remove" data-repeater-remove aria-label="%s" title="%s">%s</button>
                </div>
                <div class="repeater__row-body">%s</div>
            </div>',
            $collapsed,
            $iconHandle,
            // Vazio devolve o contador do CSS (`:empty`), que se renumera
            // sozinho ao reordenar — por isso nunca escrevemos "Item N" aqui.
            e(self::rowTitle($values, $subFields, $titleField)),
            $toggleLabel,
            $toggleLabel,
            $iconChevron,
            $removeLabel,
            $removeLabel,
            $iconClose,
            $body
        );
    }

    /**
     * Flexible content renderer. Produces:
     *  1. The existing rows, each rendered with its layout's fields
     *     populated from saved data.
     *  2. A `<script type="text/template">` for each layout, holding
     *     a base64-encoded blank row HTML — JS decodes and stamps a
     *     fresh row when the user clicks an "Add" button. base64 is
     *     used for the same reason as the repeater: inner </template>
     *     tags from nested widgets would otherwise close the wrapper
     *     prematurely.
     *  3. One "Add" button per layout, in the order defined by the
     *     site.json (PHP assoc-array iteration preserves insertion
     *     order, which matches the JSON definition order).
     *
     * Rows whose stored layout no longer exists in the config are
     * skipped on render — they'll also be dropped on next save via
     * collectFlexible. This gives schema migrations a soft landing.
     */
    private static function renderFlexible(string $name, string $id, mixed $value, array $field): string
    {
        $layouts = (array) ($field['layouts'] ?? []);
        $rows = is_array($value) ? array_values($value) : [];

        $rowsHtml = '';
        foreach ($rows as $i => $row) {
            if (!is_array($row)) continue;
            $layoutKey = (string) ($row['_layout'] ?? '');
            if (!isset($layouts[$layoutKey])) continue;
            $rowsHtml .= self::renderFlexibleRow($name, $i, $layouts, $layoutKey, $row);
        }

        $emptyHidden = $rowsHtml === '' ? '' : ' hidden';

        $templatesHtml = '';
        $addButtonsHtml = '';
        foreach ($layouts as $layoutKey => $layoutDef) {
            $layoutKey = (string) $layoutKey;
            $layoutLabel = (string) ($layoutDef['label'] ?? $layoutKey);
            $template = self::renderFlexibleRow($name, '__INDEX__', $layouts, $layoutKey, []);
            $templatesHtml .= sprintf(
                '<script type="text/template" data-flexible-template="%s">%s</script>',
                e($layoutKey),
                base64_encode($template)
            );
            $addButtonsHtml .= sprintf(
                '<button type="button" class="button button--small" data-flexible-add="%s">+ %s</button>',
                e($layoutKey),
                e($layoutLabel)
            );
        }

        return sprintf(
            '<div class="flexible-field" data-flexible-field data-flexible-name="%s">
                <div class="flexible-field__rows" data-flexible-rows>%s</div>
                <p class="flexible-field__empty" data-flexible-empty%s>%s</p>
                %s
                <div class="flexible-field__add">
                    <span class="flexible-field__add-label">%s</span>
                    %s
                </div>
            </div>',
            e($name),
            $rowsHtml,
            $emptyHidden,
            e(__('fields.flexible_empty')),
            $templatesHtml,
            e(__('fields.flexible_add_label')),
            $addButtonsHtml
        );
    }

    private static function renderFlexibleRow(string $name, int|string $index, array $layouts, string $layoutKey, array $values): string
    {
        $layoutDef = $layouts[$layoutKey] ?? null;
        if ($layoutDef === null) return '';

        $subFields = (array) ($layoutDef['fields'] ?? []);
        $layoutLabel = (string) ($layoutDef['label'] ?? $layoutKey);
        $rowName = $name . '[' . $index . ']';

        // `_layout` is reserved — silently skip if someone defines a
        // subfield with that name (documented in AGENTS.md). Filtering
        // happens BEFORE renderFields so the rest flows through the
        // same path as everywhere else (groups/tabs included).
        $renderable = array_values(array_filter(
            $subFields,
            fn($sub) => !is_array($sub) || self::nameOf($sub) !== '_layout'
        ));
        $body = self::renderFields($renderable, $values, $rowName);

        // Icons are inline SVG (ASCII, immune to encoding round-trips
        // through the base64 template stream — see decodeBase64Utf8 in
        // admin.js for the JS-side fix). Stroke-based icons inherit
        // color from the button via currentColor.
        $iconHandle = '<svg width="10" height="14" viewBox="0 0 10 14" fill="currentColor" aria-hidden="true"><circle cx="2" cy="3" r="1"/><circle cx="2" cy="7" r="1"/><circle cx="2" cy="11" r="1"/><circle cx="8" cy="3" r="1"/><circle cx="8" cy="7" r="1"/><circle cx="8" cy="11" r="1"/></svg>';
        $iconChevron = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 5l3 3 3-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $iconClose = '<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M3 3l6 6M9 3l-6 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>';

        // Same collapsed-by-default policy as repeater rows and groups:
        // saved rows load closed (scannable index), the blank template
        // a fresh "add" stamps out opens expanded for immediate typing.
        $collapsed = $index !== '__INDEX__' ? ' is-collapsed' : '';

        $toggleLabel = e(__('fields.toggle_collapse'));
        $removeLabel = e(__('fields.remove'));

        return sprintf(
            '<div class="flexible-field__row%s" data-flexible-row data-flexible-layout="%s" draggable="true">
                <input type="hidden" name="%s[_layout]" value="%s">
                <div class="flexible-field__row-header" data-flexible-row-header>
                    <span class="flexible-field__row-handle" aria-hidden="true">%s</span>
                    <span class="flexible-field__row-title">%s</span>
                    <span class="flexible-field__row-spacer"></span>
                    <button type="button" class="flexible-field__row-toggle" data-flexible-toggle aria-label="%s" title="%s">%s</button>
                    <button type="button" class="flexible-field__row-remove" data-flexible-remove aria-label="%s" title="%s">%s</button>
                </div>
                <div class="flexible-field__row-body" data-flexible-row-body>%s</div>
            </div>',
            $collapsed,
            e($layoutKey),
            e($rowName),
            e($layoutKey),
            $iconHandle,
            e($layoutLabel),
            $toggleLabel,
            $toggleLabel,
            $iconChevron,
            $removeLabel,
            $removeLabel,
            $iconClose,
            $body
        );
    }
}
