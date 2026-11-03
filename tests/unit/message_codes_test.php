<?php

declare(strict_types=1);

use Velk\PublicFormController;
use Velk\Services\FormUploadException;

/**
 * v3.7.1 (message codes): o engine devolve CÓDIGO, não frase.
 *
 * O que estes testes protegem não é a redação — é a fronteira. Antes o core
 * escolhia as palavras que o visitante lia ("Message sent.", "Check the
 * highlighted fields."), e isso quebrava de dois jeitos ao mesmo tempo: um
 * site em português exibia inglês, e o único conserto possível era a tela de
 * tradução — que `ThemeStringController` esconde quando o site não é
 * multi-idioma. Ou seja: sem saída para a maioria dos sites.
 *
 * Pior, os erros de campo e as falhas de upload nem por `t()` passavam. Eram
 * literais em português cravados no PHP, invisíveis para o `i18n:scan`, e
 * eram justamente as mensagens MAIS lidas de todo o sistema.
 *
 * Agora o engine reporta o fato e o tema escreve a frase. Uma regressão aqui
 * é silenciosa — nada quebra, só volta a vazar cópia do core para a tela do
 * visitante — então o teste é o que segura.
 */

if (!function_exists('mc_validate')) {
    /** @return array<string,array> os erros de `validate()`, só isso. */
    function mc_validate(array $fields, array $post): array
    {
        $m = new ReflectionMethod(PublicFormController::class, 'validate');
        [, $errors] = $m->invoke(new PublicFormController(), $fields, $post);
        return $errors;
    }
}

test('erro de campo é código estruturado, nunca frase pronta', function () {
    $errors = mc_validate([['name' => 'nome', 'type' => 'text', 'required' => true]], ['nome' => '']);

    check_same('required', $errors['nome']['code']);
    // A checagem que importa: nada de texto legível vazando junto. Um
    // `message` aqui significaria que alguém reintroduziu cópia no core.
    check(!isset($errors['nome']['message']), 'erro de campo não deveria carregar frase');
});

test('erro de campo carrega os params que a frase precisa', function () {
    // Sem `max`, o tema consegue dizer "longo demais" mas nunca "máximo 10
    // caracteres" — e aí o limite volta pro core em forma de texto.
    $errors = mc_validate([['name' => 'bio', 'type' => 'text', 'maxlength' => 10]], ['bio' => str_repeat('a', 40)]);

    check_same('max_length', $errors['bio']['code']);
    check_same(10, $errors['bio']['params']['max']);
});

test('cada tipo de campo tem seu próprio código', function () {
    // Um `invalid` genérico obrigaria o tema a dizer "campo inválido" pra
    // tudo. O tipo é a informação; colapsá-la é perdê-la.
    $errors = mc_validate([
        ['name' => 'email', 'type' => 'email'],
        ['name' => 'site',  'type' => 'url'],
        ['name' => 'idade', 'type' => 'number'],
        ['name' => 'plano', 'type' => 'select', 'options' => ['a' => 'A']],
    ], ['email' => 'xx', 'site' => 'nao-url', 'idade' => 'abc', 'plano' => 'inexistente']);

    check_same('invalid_email',  $errors['email']['code']);
    check_same('invalid_url',    $errors['site']['code']);
    check_same('invalid_number', $errors['idade']['code']);
    check_same('invalid_option', $errors['plano']['code']);
});

test('successPayload sem success_message devolve só o código', function () {
    $m = new ReflectionMethod(PublicFormController::class, 'successPayload');
    $payload = $m->invoke(null, []);

    check_same('success', $payload['status']);
    check_same('submitted', $payload['code']);
    // Ausente e SEM fallback em texto: um padrão aqui seria o core voltando
    // a escolher palavras, na única porta que ainda estava aberta.
    check(!isset($payload['message']), 'sem success_message não deveria haver texto');
});

test('successPayload preserva o success_message do site.json', function () {
    // A ÚNICA frase que o payload ainda carrega, e não é do engine: quem a
    // escreveu foi quem montou o site. Some daqui e o tema perde a cópia
    // que o cliente aprovou.
    //
    // Ele passa por `t()`, então precisa de um App com i18n — mesmo stub
    // mínimo de button_label_i18n_defaults_test, pelo mesmo motivo: semear
    // o cache que current() consulta primeiro deixa db/request intocados.
    $app = new \Velk\App(new \Velk\Config(dirname(__DIR__, 2)));
    $app->session = new \Velk\Session();
    $ref = new ReflectionClass(\Velk\I18n::class);
    $i18n = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('config')->setValue($i18n, $app->config);
    $ref->getProperty('activeLanguage')->setValue($i18n, $i18n->defaultLanguage());
    $app->i18n = $i18n;

    $m = new ReflectionMethod(PublicFormController::class, 'successPayload');
    $payload = $m->invoke(null, ['success_message' => 'Recebido, obrigado!']);

    check_same('submitted', $payload['code']);
    check_same('Recebido, obrigado!', $payload['message']);
});

test('FormUploadException carrega código e params, não a frase', function () {
    // `errorCode` e não `code` porque `Exception::$code` já existe e é int —
    // redeclarar como string readonly é erro fatal de PHP, não de estilo.
    $e = new FormUploadException('too_large', ['max_mb' => 5], 'File exceeds the field limit');

    check_same('too_large', $e->errorCode);
    check_same(5, $e->params['max_mb']);
    // A mensagem sobrevive para o LOG, que é onde um código sozinho não
    // ajuda ninguém a depurar. Ela nunca chega ao visitante.
    check_same('File exceeds the field limit', $e->getMessage());
});

test('nenhum controller de visitante monta frase para o payload', function () {
    // Guarda estrutural: `'message' =>` nestes arquivos significa que alguém
    // reintroduziu cópia do engine. A única exceção legítima é o
    // success_message do site.json, que é texto do TEMA.
    $arquivos = [
        'core/Front/MemberController.php',
        'core/Front/StoreController.php',
        'core/Services/FormUploadService.php',
    ];
    foreach ($arquivos as $rel) {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        check(!str_contains($src, "'message' =>"), "{$rel} voltou a montar frase no payload");
    }

    $form = file_get_contents(dirname(__DIR__, 2) . '/core/PublicFormController.php');
    // Uma ocorrência só, e é a do success_message.
    check_same(1, substr_count($form, "\$payload['message']"));
});
