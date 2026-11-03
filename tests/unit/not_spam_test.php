<?php

declare(strict_types=1);

/**
 * v3.7.3: o botão "não é spam" — o caminho de volta do falso positivo.
 *
 * A v3.7.2 passou a guardar o que o antispam reprovou, mas sem caminho de
 * volta: o lead recuperado ficava preso numa lista que ninguém trata como
 * caixa de entrada. Metade da feature.
 *
 * O que estes testes seguram é a segurança da rota, porque é onde uma ação
 * de escrita no admin costuma vazar. Três guardas, e perder qualquer uma é
 * silencioso — a ação continua funcionando, só passa a funcionar para quem
 * não devia.
 */

if (!function_exists('ns_fonte')) {
    function ns_fonte(string $rel): string
    {
        return file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
    }
}

test('a rota existe e é POST', function () {
    // GET permitiria desbloquear por link — inclusive um <img src> numa
    // página que o admin abrisse. Ação de escrita é POST.
    $src = ns_fonte('core/Admin/AdminRouter.php');
    check(str_contains($src, "\$router->post(\$prefix . '/forms/{id}/submissions/{sid}/not-spam'"),
        'rota not-spam ausente ou não é POST');
});

test('a ação exige autenticação e CSRF, nessa ordem', function () {
    $src = ns_fonte('core/Admin/Controllers/FormController.php');
    $i = strpos($src, 'public function markNotSpam(');
    check($i !== false, 'markNotSpam não existe');
    $corpo = substr($src, $i, 900);

    check(str_contains($corpo, 'requireAuth'), 'markNotSpam sem requireAuth');
    check(str_contains($corpo, 'verifyCsrfOrFail'), 'markNotSpam sem verificação de CSRF');
    check(strpos($corpo, 'requireAuth') < strpos($corpo, 'verifyCsrfOrFail'),
        'autenticação tem que vir antes do CSRF');
});

test('confere o formulário da URL antes de desbloquear', function () {
    // Sem isto, o id de uma submissão de OUTRO formulário desbloquearia por
    // esta rota — quem só tem acesso a um formulário mexeria no do vizinho.
    $src = ns_fonte('core/Admin/Controllers/FormController.php');
    $i = strpos($src, 'public function markNotSpam(');
    $corpo = substr($src, $i, 900);

    check(str_contains($corpo, '$s->formId === $id'),
        'markNotSpam não confere se a submissão é deste formulário');
});

test('só age sobre o que está bloqueado', function () {
    // Chamar em submissão normal seria no-op de qualquer jeito, mas a
    // checagem explícita evita um flash de sucesso mentindo para quem clicou.
    $src = ns_fonte('core/Admin/Controllers/FormController.php');
    $i = strpos($src, 'public function markNotSpam(');
    $corpo = substr($src, $i, 900);

    check(str_contains($corpo, '$s->blockedBy !== null'),
        'markNotSpam não confere se a submissão está bloqueada');
});

test('markNotSpam limpa a coluna e o objeto em memória', function () {
    // O objeto precisa refletir a mudança: quem chamar e reusar a instância
    // na mesma requisição veria o estado antigo, e a tela mentiria.
    $src = ns_fonte('core/Models/FormSubmission.php');
    $i = strpos($src, 'public function markNotSpam(');
    check($i !== false, 'markNotSpam não existe no model');
    $corpo = substr($src, $i, 400);

    check(str_contains($corpo, "'blocked_by' => null"), 'não zera a coluna');
    check(str_contains($corpo, '$this->blockedBy = null'), 'não zera o objeto');
});

test('o botão só aparece no que foi bloqueado', function () {
    // Num preenchimento normal ele não faria sentido, e um botão inerte na
    // tela é ruído que faz a pessoa duvidar do que está vendo.
    $src = ns_fonte('core/Admin/views/forms/show.php');
    $i = strpos($src, "not-spam");
    check($i !== false, 'botão ausente na view');

    // A condição tem que estar ANTES do formulário do botão.
    $antes = substr($src, 0, $i);
    $ultimaCond = strrpos($antes, '$s->blockedBy !== null');
    check($ultimaCond !== false, 'botão não está atrás de checagem de blockedBy');
    check($ultimaCond > strrpos($antes, '<?php foreach ($submissions'),
        'a checagem precisa estar dentro do laço das submissões');
});

test('recuperar não dispara email nem webhook', function () {
    // Eles aconteceriam semanas depois do envio. Um lead caindo no CRM com
    // data de hoje e conteúdo de três semanas atrás confunde mais que ajuda.
    $src = ns_fonte('core/Admin/Controllers/FormController.php');
    $i = strpos($src, 'public function markNotSpam(');
    $corpo = substr($src, $i, 900);

    foreach (['dispatchWebhook', 'sendEmail', 'mail(', 'ClientEvents'] as $proibido) {
        check(!str_contains($corpo, $proibido), "markNotSpam não deveria chamar {$proibido}");
    }
});
