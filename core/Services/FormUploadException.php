<?php

declare(strict_types=1);

namespace Velk\Services;

/**
 * Falha de upload em formulário público, identificada por CÓDIGO.
 *
 * O serviço sabe O QUE deu errado; o tema decide como dizer. Antes a mensagem
 * vinha pronta em português daqui e o controller a repassava direto para o
 * campo — texto do engine na tela do visitante, invisível para o `i18n:scan`
 * (nunca passou por `t()`) e portanto sem tradução possível por caminho
 * nenhum.
 *
 * `$params` carrega o que o texto precisa e só o serviço conhece: o limite em
 * MB, a lista de extensões aceitas. Sem isso o tema conseguiria dizer "arquivo
 * grande demais" mas não "máximo 5 MB".
 *
 * A mensagem em inglês continua no `getMessage()` — ela nunca chega ao
 * visitante, serve para log e depuração, onde um código sozinho não ajuda.
 */
final class FormUploadException extends \RuntimeException
{
    /**
     * `errorCode` e não `code`: `Exception` já tem uma propriedade `$code`
     * (int), e redeclará-la como readonly string é erro fatal de PHP.
     *
     * @param array<string,scalar> $params
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $params = [],
        string $debugMessage = ''
    ) {
        parent::__construct($debugMessage !== '' ? $debugMessage : $errorCode);
    }
}
