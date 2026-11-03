<?php

declare(strict_types=1);

namespace Velk;

use Velk\Models\Language;
use Velk\Models\ThemeString;

/**
 * Translation runtime for the current request.
 *
 * Responsibilities:
 *   - Track the active language for this request (default unless
 *     overridden by ?lang= preview param or, in Phase 2, by URL prefix)
 *   - Provide the `t()` lookup with an in-memory catalog cache so
 *     hundreds of `t()` calls per page hit the DB once total
 *   - Expose helpers used by Config / theme to enumerate languages
 *
 * Lifecycle: instantiated once during App::register(), accessed via
 * App::instance()->i18n. The catalog is lazy-loaded on first `t()`
 * call, so a request that translates nothing (a JSON endpoint, say)
 * skips the DB read entirely.
 */
final class I18n
{
    private Config $config;
    private Database $db;
    private Request $request;

    /** @var array<string, string>|null */
    private ?array $catalog = null;

    private ?string $activeLanguage = null;

    public function __construct(Config $config, Database $db, Request $request)
    {
        $this->config = $config;
        $this->db = $db;
        $this->request = $request;
    }

    /** True when site.json declares i18n.enabled = true. */
    public function isEnabled(): bool
    {
        return (bool) $this->config->get('site.i18n.enabled', false)
            || (bool) ($this->config->site('i18n.enabled') ?? false);
    }

    /** The code declared as default in site.json → i18n.default. Falls back to "pt-BR". */
    public function defaultLanguage(): string
    {
        $code = $this->config->site('i18n.default');
        return is_string($code) && $code !== '' ? $code : 'pt-BR';
    }

    /**
     * Active language for this request.
     *
     * Resolution order (Phase 1):
     *   1. ?lang=xx query param (preview — checked against enabled list;
     *      FRONT-END requests only, see isAdminRequest())
     *   2. Default
     *
     * Phase 2 will add: URL prefix detection. Phase 3 may add: cookie
     * persistence + Accept-Language sniff.
     */
    public function current(): string
    {
        if ($this->activeLanguage !== null) {
            return $this->activeLanguage;
        }

        if (!$this->isEnabled()) {
            return $this->activeLanguage = $this->defaultLanguage();
        }

        // The ?lang= preview is a front-end authoring/QA tool. Admin
        // requests never get it: a stray ?lang=en on, say, the admin
        // forgot-password POST would otherwise silently flip current()
        // to "en" for the rest of the request, and any url()-built link
        // the handler generates afterwards (notably the emailed
        // reset-password link) would come out prefixed /en/ — a prefix
        // that route was never registered under, so the link 404s.
        if (!$this->isAdminRequest()) {
            $preview = (string) ($this->request->query['lang'] ?? '');
            if ($preview !== '' && $this->isAvailable($preview)) {
                return $this->activeLanguage = $preview;
            }
        }

        return $this->activeLanguage = $this->defaultLanguage();
    }

    /**
     * True when the current request targets the admin panel — same
     * ADMIN_PREFIX (`admin.prefix`) computation admin_url() and
     * AdminRouter use to mount admin routes, and the same
     * exact-or-followed-by-slash boundary check Request::capture() uses
     * to strip the install base path (so a front-end path that merely
     * starts with the prefix string, e.g. "/administracao" against
     * "/admin", doesn't false-positive). $request->path is already
     * base-path-stripped, matching what AdminRouter registers against.
     */
    private function isAdminRequest(): bool
    {
        $prefix = '/' . trim((string) $this->config->get('admin.prefix', '/admin'), '/');
        $path = '/' . trim($this->request->path, '/');
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    /** All languages exposed to the front, in display order (default first). */
    public function available(): array
    {
        $default = $this->defaultLanguage();
        $out = [
            $default => [
                'code' => $default,
                'label' => $this->labelFor($default),
                'url_prefix' => null,
                'is_default' => true,
            ],
        ];
        if (!$this->isEnabled()) {
            return $out;
        }
        foreach (Language::all(onlyEnabled: true) as $lang) {
            if ($lang->code === $default) continue; // can't shadow default
            $out[$lang->code] = [
                'code' => $lang->code,
                'label' => $lang->label,
                'url_prefix' => $lang->urlPrefix,
                'is_default' => false,
            ];
        }
        return $out;
    }

    public function isAvailable(string $code): bool
    {
        return isset($this->available()[$code]);
    }

    /**
     * Nome de exibição de um código de idioma.
     *
     * Cada idioma tem UM dono do seu nome, e a ordem abaixo reflete isso:
     * o padrão é nomeado pelo tema (`i18n.default_label` no site.json), os
     * adicionais pelo painel (campo "Nome", obrigatório na criação). Não há
     * sobreposição — o painel proíbe criar uma linha para o código padrão.
     *
     * Antes existia aqui um mapa fixo com treze idiomas, que cobria o padrão
     * justamente por ele não ter dono. Saiu junto com a chave nova: nomear
     * idioma é decisão de quem monta o site, não do engine — e um mapa fixo
     * impedia trocar "Português (Brasil)" por "Brasil" ou "PT".
     *
     * O código cru é o último recurso, não uma opção de projeto: significa
     * que o tema esqueceu a chave, e o `schema:validate` reprova isso quando
     * i18n está ligado.
     */
    public function labelFor(string $code): string
    {
        if ($code === $this->defaultLanguage()) {
            $label = $this->config->defaultLanguageLabel();
            if ($label !== null) {
                return $label;
            }
        }
        $lang = Language::find($code);
        if ($lang !== null && $lang->label !== '') return $lang->label;
        return $code;
    }

    /**
     * Traduz um texto-fonte.
     *
     * A chave do catálogo é o PRÓPRIO texto (mais o contexto, quando houver),
     * então o idioma em que ele está escrito é escolha de quem escreveu o
     * tema — o engine não tem opinião sobre isso. Sem tradução cadastrada, a
     * resposta é a própria fonte.
     *
     * Placeholders `{name}` são substituídos DEPOIS da consulta, para que a
     * tradução também os receba.
     */
    public function translate(string $original, array $params = [], ?string $context = null): string
    {
        $current = $this->current();

        // SEM atalho por idioma, de propósito — já houve um, e ele era um bug.
        //
        // Ele devolvia a fonte sem consultar o catálogo quando o idioma do
        // visitante era um valor FIXO aqui ('en'), assumindo que todo texto
        // passado ao t() está escrito em inglês. Isso valia para o core na
        // v3.6.3, quando o atalho nasceu, e deixou de valer na v3.7.1: o
        // engine passou a devolver códigos, e as únicas fontes que restaram
        // são as DO TEMA, cujo idioma quem escreve o tema escolhe. Um tema em
        // português exibia PORTUGUÊS para o visitante inglês, e a tradução
        // cadastrada no painel nunca era consultada — sem erro, e sem
        // conserto pelo painel.
        //
        // O idioma PADRÃO do site continua exibindo o texto do tema, e agora
        // por garantia em vez de por sorte: o painel recusa cadastrar o
        // padrão como idioma (LanguageController → error_is_default), então
        // aquele slot do catálogo não tem como ser preenchido e o fallback
        // abaixo é o único caminho possível.
        //
        // Custo: uma carga de catálogo por requisição, memoizada em
        // loadCatalog(). Todo site cujo idioma não fosse 'en' já pagava
        // exatamente isso.
        $catalog = $this->loadCatalog($current);
        $key = ThemeString::makeKey($original, $context);
        $translated = $catalog[$key] ?? $original;

        return $params === [] ? $translated : self::interpolate($translated, $params);
    }

    private function loadCatalog(string $code): array
    {
        if ($this->catalog !== null) return $this->catalog;

        // Catálogo indisponível vira catálogo VAZIO, e `t()` devolve o
        // texto-fonte. Isso importa porque TODA requisição que traduz algo
        // passa por aqui: uma falha de leitura não pode derrubar a página
        // inteira. Exibir o texto do tema é degradação aceitável — é o mesmo
        // que o visitante do idioma padrão já vê —, erro fatal não é.
        try {
            $this->catalog = ThemeString::loadCatalog($code);
        } catch (\Throwable $e) {
            $this->catalog = [];
        }
        return $this->catalog;
    }

    /** Replace `{name}` placeholders with values from the params array. */
    private static function interpolate(string $template, array $params): string
    {
        if ($template === '' || $params === []) return $template;
        $replacements = [];
        foreach ($params as $key => $value) {
            $replacements['{' . $key . '}'] = (string) $value;
        }
        return strtr($template, $replacements);
    }

    /**
     * Manually override the active language for the remainder of the
     * request. Used by /admin/theme-strings preview switching and by
     * the upcoming Phase 2 URL-prefix detection in FrontRouter.
     */
    public function setCurrent(string $code): void
    {
        $this->activeLanguage = $code;
        $this->catalog = null; // force reload on next translate()
    }
}
