<?php

declare(strict_types=1);

namespace Velk;

/**
 * O HTML de um campo richtext, fora os blocos do tema (ver RichtextBlocks).
 */
final class Richtext
{
    /**
     * As tags que só embrulham texto: sem texto dentro, não mostram nada.
     * Imagem, tabela, linha (`<hr>`) e o marcador de um bloco do tema (uma
     * `<div>`) ficam de fora — um texto só com um deles tem conteúdo.
     *
     * A expressão casa o valor inteiro e para na primeira coisa que não é
     * uma dessas tags nem espaço: num texto de verdade, isso é logo depois
     * do primeiro `<p>`, sem percorrer o resto.
     */
    private const ONLY_EMPTY_TEXT_TAGS = '~^(?:\s+|</?(?:p|br|h[1-6]|ul|ol|li|blockquote|pre|code|strong|em|b|i|s|u|a|span)(?:\s[^<>]*)?/?>)*+\z~i';

    /**
     * O valor é o HTML de um editor de texto sem nada escrito?
     *
     * O editor do painel nunca devolve '': vazio, ele grava `<p></p>` (ou um
     * título, uma lista, uma citação sem texto, se foi isso que sobrou depois
     * de apagar tudo). Para uma tradução, isso é "não traduzido" — na hora
     * de gravar um richtext (FieldRenderer::translationOverrides) e, pela
     * forma mais estreita de isEmptyDocument(), na de ler (fieldIn/fieldsIn
     * dos modelos, Option::getPathIn).
     *
     * Erra para o lado de manter: qualquer tag fora da lista acima, um
     * comentário, um `&nbsp;` contam como conteúdo. E só responde por HTML —
     * '' e espaço em branco são o vazio de um campo de texto, que quem chama
     * já trata.
     */
    public static function isEmptyMarkup(mixed $value): bool
    {
        if (!is_string($value) || !str_contains($value, '<')) {
            return false;
        }
        return preg_match(self::ONLY_EMPTY_TEXT_TAGS, $value) === 1;
    }

    /**
     * O valor é um editor de texto em branco — o vazio de isEmptyMarkup() que
     * começa por um bloco de texto (`<p>`, título, lista, citação, código),
     * como o editor grava?
     *
     * É a regra da leitura das traduções (fieldIn/fieldsIn dos modelos,
     * Option::getPathIn), onde o tipo do campo não está à mão: um campo de
     * texto traduzido só com uma tag em linha — o ícone ou a bandeira de um
     * idioma, `<i class="fi fi-us"></i>` — é tradução, e não pode cair no
     * idioma padrão. Na gravação o tipo é conhecido, e isEmptyMarkup() só vale
     * para richtext.
     */
    public static function isEmptyDocument(mixed $value): bool
    {
        return is_string($value)
            && preg_match('~^\s*<(?:p|h[1-6]|ul|ol|blockquote|pre)[\s>]~i', $value) === 1
            && self::isEmptyMarkup($value);
    }
}
