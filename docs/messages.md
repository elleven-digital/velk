# Mensagens: o engine devolve código, o tema escreve a frase

O core do Velk nunca escolhe as palavras que o visitante lê. Ele reporta **o
que aconteceu** — um código, mais os dados que só ele conhece — e o tema
decide como dizer.

```php
// o que o engine devolve
['status' => 'error', 'code' => 'rate_limited', 'params' => ['minutes' => 15]]

// o que o tema faz com isso (theme/functions.php)
'rate_limited' => 'Muitas tentativas. Tente de novo em {minutes} minuto(s).',
```

## Por que

Até a v3.7 o core mandava a frase pronta — 59 frases, entre formulários,
membros, loja e upload. Isso quebrava de três jeitos:

**Um site em português exibia inglês.** As strings-fonte do engine eram em
inglês, e o único conserto era a tela de tradução — que o
`ThemeStringController` esconde quando o site não é multi-idioma. Ou seja: sem
saída para a maioria dos sites.

**Os erros mais lidos nem tradução tinham.** Erros de campo ("Campo
obrigatório") e falhas de upload eram literais cravados no PHP, sem passar por
`t()`. Invisíveis para o `i18n:scan`, invisíveis para o painel — e eram
justamente as mensagens que mais gente lia.

**A voz do site era do CMS.** "Message sent." é uma decisão editorial. Um site
de advocacia, um e-commerce e uma campanha eleitoral não dizem a mesma coisa
quando um formulário é enviado, e nenhum deles deveria herdar a redação do
engine.

Códigos resolvem os três de uma vez: sem inglês para traduzir, sem string
escondida do scanner, e a redação inteira mora no tema.

## Formato

```php
[
  'status' => 'success' | 'error' | 'info' | 'two_factor',
  'code'   => 'submitted',              // o que aconteceu
  'params' => ['minutes' => 15],        // só quando a frase precisa de dados
  'errors' => [                         // só em formulários
      'email' => ['code' => 'invalid_email'],
      'bio'   => ['code' => 'max_length', 'params' => ['max' => 200]],
  ],
  'values' => ['email' => 'x@y.com'],   // o que o visitante digitou
]
```

Um formulário com `steps` acrescenta mais quatro chaves, só na resposta
`step_saved` de uma etapa intermediária: `step`, `next_step`, `total_steps` e
`token` — veja a linha de `step_saved` na tabela abaixo. Nenhuma outra
resposta ganhou chave por causa das etapas. (O bloco acima é o núcleo comum:
uma resposta de sucesso ainda pode trazer `message`, e em JSON o `event`.)

`status` é para ramificar (cor do box, `if (!res.ok)`); `code` é para escolher
a frase. Em JSON, `error` responde 422 e o resto 200.

### A única frase que sobra

`success_message`, declarado por formulário no `site.json`, continua chegando
como `message`. Não é o engine tendo opinião — é o tema mandando a dele, e
quem escreveu foi quem montou o site. Passa por `t()` como qualquer string de
tema. Ausente, não há texto nenhum: o tema recebe só `submitted` e decide.

`step_saved` — a etapa intermediária de um formulário com `steps` — nunca traz
`message`, de propósito: a frase de sucesso é do fim do formulário, não de
cada etapa. Entre uma etapa e outra quem decide o que mostrar (ou se mostra
algo) é o tema.

## Onde os códigos chegam

| Helper | Origem |
|---|---|
| `form_status($id)` | envio de formulário |
| `member_status()` | entrar, cadastrar, senha, assinatura |
| `store_status()` | carrinho e pedido |
| resposta JSON | os mesmos, quando o envio é AJAX |

## Vocabulário

### Formulários — `form_status()`

| Código | Quando |
|---|---|
| `submitted` | enviado com sucesso |
| `step_saved` | etapa intermediária gravada; vem com `step`, `next_step`, `total_steps` e `token` |
| `validation_failed` | algum campo reprovou; veja `errors` |
| `captcha_failed` | o Turnstile não confirmou |
| `session_expired` | token CSRF inválido ou vencido |
| `form_not_found` | o `id` não existe no `site.json` |
| `file_too_large` | o POST estourou o limite do PHP — chega vazio, sem nem dar pra saber qual campo |
| `ajax_required` | etapa intermediária enviada sem `Accept: application/json` — em POST nativo o tema não recebe o token da etapa seguinte |
| `invalid_token` | `_step_token` desconhecido, de outro formulário, ou de outra sessão |
| `form_completed` | o lead já foi concluído — inclusive por outro envio final simultâneo — ou bloqueado como spam pelo painel; nos três casos o token morreu e não abre mais o lead |
| `invalid_step` | `_step` além da etapa pendente, fora do intervalo declarado, `_step` diferente de 1 sem vir `_step_token` junto, ou `_step` AUSENTE vindo `_step_token` — os dois últimos são o mesmo descuido de encanamento no tema |
| `incomplete_lead` | etapa final faltando obrigatório de etapa anterior; os nomes vêm em `errors`. Redesenhar a etapa final com esses erros não resolve — os campos não estão nela: mande a visitante DE VOLTA para a etapa que os declara |

### Erros de campo — dentro de `errors`

| Código | Params | Quando |
|---|---|---|
| `required` | — | campo obrigatório em branco |
| `max_length` | `max` | passou de `maxlength` |
| `invalid_email` | — | não é email |
| `invalid_url` | — | não é URL |
| `invalid_number` | — | não é número |
| `invalid_option` | — | valor fora das `options` do campo |

### Upload — também dentro de `errors`

| Código | Params | Quando |
|---|---|---|
| `too_large` | `max_mb` | passou do limite do campo |
| `type_not_accepted` | `accepted` | fora do `accept` daquele campo |
| `type_not_allowed` | — | extensão que o Velk nunca aceita |
| `empty_file` | — | arquivo de 0 byte |
| `no_file` | — | nada enviado |
| `incomplete_upload` | — | conexão caiu no meio |
| `invalid_upload` | — | não veio por upload de verdade |
| `invalid_svg` | — | SVG com conteúdo não permitido |
| `store_failed` | — | falha do servidor ao gravar |
| `upload_failed` | — | falha inesperada; o detalhe fica no log |

`store_failed` cobre disco cheio, diretório temporário ausente e extensão do
PHP bloqueada. Colapsados de propósito: a diferença não muda nada para quem
está na tela, e detalhar infraestrutura entrega informação a quem não deveria
ver. O `getMessage()` da exceção mantém o detalhe para o log.

### Membros — `member_status()`

| Código | Params | Quando |
|---|---|---|
| `signed_in` | — | entrou |
| `signed_out` | — | saiu |
| `credentials_missing` | — | email ou senha em branco |
| `invalid_credentials` | — | email ou senha errados |
| `rate_limited` | `minutes` | tentativas demais |
| `session_expired` | — | CSRF inválido |
| `registered` | — | conta criada |
| `email_taken` | — | já existe conta com esse email |
| `invalid_email` | — | email inválido no cadastro |
| `password_too_short` | — | menos de 8 caracteres |
| `invalid_member_type` | — | tipo de conta que o site não declara |
| `registration_failed` | — | falha inesperada no cadastro |
| `password_reset_sent` | — | pedido de redefinição aceito |
| `password_updated` | — | senha trocada |
| `reset_link_invalid` | — | link expirado ou já usado |
| `account_not_found` | — | conta do link não existe mais |
| `otp_sent` | — | código enviado por email |
| `otp_resent` | — | código reenviado |
| `otp_invalid` | — | código errado ou vencido |
| `otp_resend_wait` | — | pediu outro rápido demais |
| `otp_rate_limited` | — | códigos demais pedidos |
| `otp_send_failed` | — | o email não saiu |
| `otp_session_expired` | — | a verificação venceu |
| `plan_unavailable` | — | plano ou periodicidade que não existe |
| `already_subscribed` | — | já tem assinatura ativa — com `members.multiple_plans`, já tem *esse* plano; trocar de plano é pelo portal |
| `checkout_failed` | — | o Stripe não abriu |
| `no_subscription` | — | não há assinatura |
| `portal_failed` | — | o portal do Stripe não abriu |
| `subscription_paid` | — | pagamento confirmado |
| `subscription_cancelled` | — | desistiu no checkout |

`password_reset_sent` sai igual para email que existe e email que não existe —
é assim de propósito, senão o formulário vira um verificador de quem tem conta
no site. O tema não deve diferenciar os dois casos, porque não consegue.

Quando o site pede código por email, `otp_sent` chega com `status`
`two_factor` — não `success`. É etapa, não conclusão: o tema deve trocar o
formulário de login pelo de código, que posta na rota `two_factor`. O status
continua `two_factor` enquanto o código estiver errado, para a tela não
voltar ao login. Responde 200, não 422, porque não é falha.

### Loja — `store_status()`

| Código | Quando |
|---|---|
| `cart_empty` | carrinho vazio |
| `cart_invalid` | nenhum item válido |
| `checkout_failed` | o Stripe não abriu |
| `session_expired` | CSRF inválido |
| `order_paid` | pagamento confirmado |
| `purchase_cancelled` | desistiu no checkout |
| `already_purchased` | já tem o item em pedido pago e o tipo não permite repetir (`repeat_purchase: false`); chega com `status` `info` |

## Escrevendo o mapa no tema

Um mapa em `theme/functions.php`, que o Velk carrega no boot:

```php
<?php
/** @return array<string,string> */
function tema_mensagens(): array
{
    return [
        'submitted'           => 'Recebido — retornamos em breve.',
        'validation_failed'   => 'Verifique os campos destacados.',
        'invalid_credentials' => 'Email ou senha incorretos.',
        'rate_limited'        => 'Muitas tentativas. Tente de novo em {minutes} minuto(s).',
        // …um por código que este site usa.
    ];
}

/** @return array<string,string> */
function tema_erros_de_campo(): array
{
    return [
        'required'      => 'Campo obrigatório.',
        'max_length'    => 'Use no máximo {max} caracteres.',
        'invalid_email' => 'Email inválido.',
        'too_large'     => 'Arquivo maior que {max_mb} MB.',
        // …
    ];
}

function tema_texto(?array $retorno, ?string $padrao = null): string
{
    if ($retorno === null) return (string) $padrao;

    // `message` só chega quando o formulário declara success_message no
    // site.json — texto de quem montou o site, tem precedência.
    $escrito = $retorno['message'] ?? null;
    if (is_string($escrito) && $escrito !== '') return $escrito;

    $texto = tema_mensagens()[(string) ($retorno['code'] ?? '')] ?? null;
    return $texto === null
        ? (string) $padrao
        : tema_preencher($texto, (array) ($retorno['params'] ?? []));
}

function tema_erro_de_campo(array $erro): string
{
    $texto = tema_erros_de_campo()[(string) ($erro['code'] ?? '')] ?? 'Confira este campo.';
    return tema_preencher($texto, (array) ($erro['params'] ?? []));
}

function tema_preencher(string $texto, array $params): string
{
    foreach ($params as $k => $v) {
        $texto = str_replace('{' . $k . '}', (string) $v, $texto);
    }
    return $texto;
}
```

No template:

```php
<?= e(tema_texto($status, 'Não foi possível concluir.')) ?>
<?= e(tema_erro_de_campo($r['errors']['email'])) ?>
```

Um tema **não precisa cobrir todos os códigos** — mas sempre passe o texto
genérico. Sem ele, um código não mapeado vira `invalid_credentials` cru na
tela do visitante.

## Multi-idioma

Os códigos não mudam com o idioma — quem muda é o mapa. Duas opções:

```php
// 1. o mapa passa por t(), e o painel traduz como qualquer string de tema
'invalid_credentials' => t('Email ou senha incorretos.'),

// 2. um mapa por idioma, sem passar pelo banco
$mapas = ['pt' => [...], 'en' => [...]];
return $mapas[current_language()] ?? $mapas['pt'];
```

A primeira integra com o painel; a segunda não depende dele. Sites de idioma
único não precisam de nenhuma das duas — escrevem o texto direto, que é
exatamente o problema que os códigos vieram resolver.
