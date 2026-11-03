<?php

declare(strict_types=1);

namespace Velk;

/**
 * Estado de consentimento do visitante.
 *
 * Guarda APENAS escolhas explícitas. Uma categoria ausente do cookie não é
 * "negada" — é "ainda não respondida", e aí quem decide é o `mode` declarado
 * no site.json. Essa distinção é o núcleo do modelo:
 *
 *   required  → concedida sempre; o cookie não pode revogar
 *   opt_out   → concedida até o visitante dizer não
 *   opt_in    → negada até o visitante dizer sim
 *
 * Guardar só o explícito também mantém o cookie pequeno e faz a coisa certa
 * quando o site MUDA de opinião: se `analise` virar de opt_in para opt_out
 * depois, quem nunca respondeu passa a receber, e quem recusou explicitamente
 * continua sem — que é o comportamento honesto.
 *
 * Tudo aqui é estático e puro: recebe o cookie cru e as categorias, devolve
 * decisão. Nada de $_COOKIE, header ou sessão — o que torna o gate inteiro
 * testável sem HTTP.
 */
final class Consent
{
    public const COOKIE = 'velk_consent';

    /** Seis meses. Prazo comum de renovação de consentimento na prática. */
    public const TTL_DAYS = 180;

    /** Versão do formato guardado, para conseguir migrar sem quebrar. */
    public const VERSION = 1;

    /**
     * Categorias implícitas, usadas quando o tema NÃO declara nenhuma.
     *
     * Existem porque consentimento precisa funcionar sem tocar no tema — o
     * dono do site marca "exige consentimento" no painel e pronto. Aí o
     * banner é geral: aprova ou recusa tudo de uma vez, e estas duas chaves
     * guardam só o padrão de cada script antes de alguém responder.
     *
     * Declarar categorias de verdade continua sendo o que torna o banner
     * apresentável — "Marketing" e "Análise" dizem algo ao visitante,
     * "Opcionais" não.
     */
    public const IMPLICIT_ON = '_optional_on';
    public const IMPLICIT_OFF = '_optional_off';

    /**
     * O mapa implícito, no formato que todo o resto consome.
     *
     * Os rótulos são genéricos de propósito: o tema desenha o banner e
     * escolhe o texto que o visitante lê. Estes só aparecem no painel.
     *
     * @return array<string, array{label: string, mode: string}>
     */
    public static function implicitCategories(): array
    {
        return [
            self::IMPLICIT_ON => ['label' => 'Opcionais', 'mode' => 'opt_out'],
            self::IMPLICIT_OFF => ['label' => 'Opcionais', 'mode' => 'opt_in'],
        ];
    }

    /**
     * Escolhas explícitas do cookie cru: `['marketing' => true]`.
     *
     * Nunca lança e nunca devolve lixo: cookie ausente, JSON inválido,
     * versão desconhecida ou formato inesperado viram `[]` — tratado como
     * "não respondeu", que faz cada categoria cair no seu `mode`. Um cookie
     * corrompido não pode ligar rastreamento que ninguém aceitou.
     *
     * @return array<string, bool>
     */
    public static function parse(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        if ((int) ($decoded['v'] ?? 0) !== self::VERSION) {
            return [];
        }

        $choices = $decoded['c'] ?? null;
        if (!is_array($choices)) {
            return [];
        }

        $out = [];
        foreach ($choices as $key => $value) {
            // Só booleano de verdade. "1", "true" e 1 ficam de fora de
            // propósito: o cookie é escrito pelo nosso próprio runtime, então
            // qualquer outra forma indica adulteração ou versão antiga, e o
            // lado seguro é ignorar.
            if (is_string($key) && $key !== '' && is_bool($value)) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Serializa as escolhas explícitas para gravar no cookie. */
    public static function encode(array $choices): string
    {
        $clean = [];
        foreach ($choices as $key => $value) {
            if (is_string($key) && $key !== '' && is_bool($value)) {
                $clean[$key] = $value;
            }
        }
        return (string) json_encode(
            ['v' => self::VERSION, 'c' => (object) $clean],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Uma categoria está concedida?
     *
     * Categoria que não está declarada no site.json devolve `false`. Ela
     * pode existir num cookie antigo, de quando o tema ainda a declarava —
     * e nesse caso os scripts dela também não existem mais, então negar é
     * consistente com o que a página vai realmente conter.
     *
     * @param array<string, array{label: string, mode: string}> $categories
     * @param array<string, bool> $choices  escolhas explícitas (de parse())
     */
    public static function isGranted(string $category, array $categories, array $choices): bool
    {
        $mode = $categories[$category]['mode'] ?? null;
        if ($mode === null) {
            return false;
        }

        // `required` ignora o cookie por definição: se pudesse ser revogada,
        // não seria necessária. É onde mora o próprio cookie de
        // consentimento, verificação de domínio e afins.
        if ($mode === 'required') {
            return true;
        }

        if (array_key_exists($category, $choices)) {
            return $choices[$category];
        }

        return $mode === 'opt_out';
    }

    /**
     * Estado resolvido de TODAS as categorias declaradas — o que o runtime
     * entrega pro tema montar o popup já com os controles no estado certo.
     *
     * @param array<string, array{label: string, mode: string}> $categories
     * @param array<string, bool> $choices
     * @return array<string, bool>
     */
    public static function resolveAll(array $categories, array $choices): array
    {
        $out = [];
        foreach ($categories as $key => $_def) {
            $out[(string) $key] = self::isGranted((string) $key, $categories, $choices);
        }
        return $out;
    }

    /**
     * HTML dos scripts de UMA posição, já com o gate aplicado.
     *
     * Categoria concedida sai crua, exatamente onde o autor pôs — nenhum
     * atraso, nenhum JS no meio. É o caminho do visitante recorrente que já
     * respondeu, ou seja, o caminho comum depois da primeira visita.
     *
     * Categoria não concedida sai inerte, para o runtime ligar se e quando o
     * visitante aceitar:
     *
     *     <script type="text/plain" data-velk-consent="mkt">BASE64</script>
     *
     * Duas escolhas aqui não são estéticas:
     *
     * `type="text/plain"` o navegador não executa — é o mecanismo que
     * Cookiebot, Klaro e afins usam. Um `<template>` também seria inerte, mas
     * o wrapper precisa sobreviver ao conteúdo, e é aí que entra o segundo
     * ponto.
     *
     * O conteúdo vai em BASE64 pelo mesmo motivo que o template do repetidor
     * (ver FieldRenderer::renderRepeater): HTML cru colado por uma pessoa
     * pode conter `</script>` — qualquer snippet que escreve outra tag
     * contém — e fecharia o wrapper no meio, despejando o resto como markup
     * solto na página. base64 só tem [A-Za-z0-9+/=], então não existe
     * sequência capaz de fechar nada.
     *
     * @param list<array<string,mixed>> $rows  linhas do repetidor
     * @param array<string, array{label: string, mode: string}> $categories
     * @param array<string, bool> $choices
     */
    public static function renderScripts(
        array $rows,
        string $location,
        array $categories,
        array $choices
    ): string {
        $out = '';

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((string) ($row['location'] ?? '') !== $location) {
                continue;
            }
            $code = $row['code'] ?? null;
            if (!is_string($code) || trim($code) === '') {
                continue;
            }

            $category = (string) ($row['category'] ?? '');

            // A senha da página entra em cada <script> do código colado (ver
            // PublicCsp::nonceScripts): no modo strict da CSP, é o que deixa o
            // navegador rodar o que o painel cadastrou. Nos adiados ela entra
            // ANTES do base64, e o runtime copia os atributos ao recriar.
            $code = PublicCsp::nonceScripts($code);

            // Categoria VAZIA é o padrão e significa "necessário, sempre
            // carrega" — é o que um script recebe quando ninguém marcou
            // "exige consentimento" na tela. Sai cru, sem gate.
            if ($category === '') {
                $out .= "\n" . $code . "\n";
                continue;
            }

            // Categoria preenchida mas DESCONHECIDA é outra coisa: o tema
            // declarava essa categoria e deixou de declarar (renome no
            // site.json, por exemplo). Aí não há como decidir, e o lado
            // seguro de "não sei" é não carregar — deixar passar
            // transformaria um erro de configuração em rastreamento sem
            // consentimento. A tela sinaliza essas linhas em vermelho.
            if (!isset($categories[$category])) {
                continue;
            }

            if (self::isGranted($category, $categories, $choices)) {
                $out .= "\n" . $code . "\n";
                continue;
            }

            $out .= "\n" . sprintf(
                '<script type="text/plain" data-velk-consent="%s">%s</script>',
                htmlspecialchars($category, ENT_QUOTES, 'UTF-8'),
                base64_encode($code)
            ) . "\n";
        }

        return $out;
    }

    /**
     * O visitante já respondeu alguma coisa?
     *
     * É o que o tema usa pra decidir se mostra o banner. Separado de
     * `resolveAll()` porque "aceitou tudo" e "nunca viu o banner" podem
     * resolver para o mesmo estado, e só o segundo deve exibi-lo.
     */
    public static function hasAnswered(array $choices): bool
    {
        return $choices !== [];
    }

    /**
     * O runtime `window.velkConsent`, inline.
     *
     * Inline e não arquivo servido por rota porque o core não tem pipeline de
     * asset pro front — não existe `core/assets` nem rota pública de estático.
     * O `ClientEvents::render()` já emite script inline pelo mesmo motivo, e
     * inline evita um request a mais em algo que precisa rodar cedo.
     *
     * Superfície pública, deliberadamente pequena — o popup é do tema:
     *
     *   velkConsent.state()       → {mkt: false, ana: true}
     *   velkConsent.categories()  → {mkt: {label, mode}, ...}
     *   velkConsent.answered()    → já respondeu? (decide exibir o banner)
     *   velkConsent.set({mkt: true})
     *   velkConsent.acceptAll() / rejectAll()
     *   evento 'velkconsent' no document a cada mudança
     *
     * `set()` NÃO recarrega a página: ativa os inertes na hora. O que ele não
     * faz é o inverso — desligar o que já carregou, porque não existe: script
     * executado já gravou cookie e já mandou dado. Revogar vale da próxima
     * navegação em diante, e é por isso que `set()` também regrava o cookie
     * server-side-visível.
     */
    public static function renderRuntime(array $categories, array $choices): string
    {
        $payload = json_encode([
            'cookie' => self::COOKIE,
            'ttl' => self::TTL_DAYS,
            'version' => self::VERSION,
            'categories' => (object) $categories,
            'state' => (object) self::resolveAll($categories, $choices),
            'answered' => self::hasAnswered($choices),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);

        // JSON_HEX_TAG é o que impede um rótulo de categoria — texto livre,
        // digitado no site.json — fechar este <script>.
        $nonce = PublicCsp::nonce();
        return <<<HTML

<script nonce="{$nonce}">(function(){
var C = {$payload};
function readCookie(){
  var m = document.cookie.match(new RegExp('(?:^|; )' + C.cookie + '=([^;]*)'));
  if (!m) return {};
  try {
    var d = JSON.parse(decodeURIComponent(m[1]));
    if (!d || d.v !== C.version || typeof d.c !== 'object' || d.c === null) return {};
    var out = {};
    for (var k in d.c) if (typeof d.c[k] === 'boolean') out[k] = d.c[k];
    return out;
  } catch (e) { return {}; }
}
function writeCookie(choices){
  var v = encodeURIComponent(JSON.stringify({v: C.version, c: choices}));
  var exp = new Date(Date.now() + C.ttl * 864e5).toUTCString();
  var secure = location.protocol === 'https:' ? '; Secure' : '';
  document.cookie = C.cookie + '=' + v + '; Path=/; Expires=' + exp + '; SameSite=Lax' + secure;
}
function resolve(choices){
  var out = {};
  for (var k in C.categories) {
    var mode = C.categories[k].mode;
    out[k] = mode === 'required' ? true
      : (k in choices ? choices[k] : mode === 'opt_out');
  }
  return out;
}
// base64 → texto, ciente de UTF-8. atob() sozinho devolve bytes e
// transformaria acento em mojibake.
function decode(b64){
  var bin = atob(b64), bytes = new Uint8Array(bin.length);
  for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return new TextDecoder('utf-8').decode(bytes);
}
function activate(state){
  var nodes = document.querySelectorAll('script[type="text/plain"][data-velk-consent]');
  Array.prototype.forEach.call(nodes, function(node){
    if (!state[node.getAttribute('data-velk-consent')]) return;
    var frag = document.createElement('div');
    frag.innerHTML = decode(node.textContent || '');
    // <script> vindo de innerHTML NÃO executa — precisa ser recriado. Sem
    // isto, o <noscript> e o <iframe> do snippet funcionariam e o script
    // não, sem nada no console. Os atributos vão junto, inclusive a senha
    // (nonce) que o PHP pôs antes do base64.
    Array.prototype.forEach.call(frag.querySelectorAll('script'), function(old){
      var s = document.createElement('script');
      for (var i = 0; i < old.attributes.length; i++) {
        s.setAttribute(old.attributes[i].name, old.attributes[i].value);
      }
      s.text = old.text;
      old.parentNode.replaceChild(s, old);
    });
    while (frag.firstChild) node.parentNode.insertBefore(frag.firstChild, node);
    node.parentNode.removeChild(node);
  });
}
var choices = readCookie();
var state = C.answered || Object.keys(choices).length ? resolve(choices) : C.state;
function commit(next){
  for (var k in next) if (C.categories[k] && C.categories[k].mode !== 'required') {
    choices[k] = !!next[k];
  }
  writeCookie(choices);
  state = resolve(choices);
  activate(state);
  document.dispatchEvent(new CustomEvent('velkconsent', {detail: {state: state}}));
}
window.velkConsent = {
  categories: function(){ return JSON.parse(JSON.stringify(C.categories)); },
  state: function(){ return JSON.parse(JSON.stringify(state)); },
  answered: function(){ return Object.keys(choices).length > 0; },
  set: commit,
  acceptAll: function(){ var n = {}; for (var k in C.categories) n[k] = true; commit(n); },
  rejectAll: function(){ var n = {}; for (var k in C.categories) n[k] = false; commit(n); }
};
// Visitante que já respondeu: o PHP já emitiu o que podia cru. Isto cobre a
// aba aberta antes do aceite e o cookie gravado em outra aba.
activate(state);
})();</script>

HTML;
    }
}
