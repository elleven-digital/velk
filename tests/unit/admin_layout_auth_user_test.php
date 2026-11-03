<?php

declare(strict_types=1);

/**
 * Os layouts do admin não podem ler `$user`.
 *
 * `View::renderFile()` monta o escopo com `array_merge($this->shared, $data)`,
 * e com chaves string o ÚLTIMO vence — ou seja, o dado da página sempre
 * sobrescreve o compartilhado. O layout renderiza dentro da página, e `$user`
 * é o nome natural para "o usuário desta tela": `users/edit.php` usa
 * exatamente isso para o registro que está sendo editado.
 *
 * O resultado era a barra lateral mostrando a pessoa errada ao editar outro
 * usuário, e sumindo por completo em /admin/users/new — onde o registro é
 * null, o que também derrubava o link "Usuários" do menu.
 *
 * A correção foi renomear o compartilhado para `authUser`, um nome que
 * nenhuma página escolheria por acaso. Esta guarda existe porque a colisão é
 * invisível: nada falha, nada loga, a variável só passa a valer outra coisa.
 */
test('layouts do admin: leem $authUser, nunca $user', function () {
    $dir = dirname(__DIR__, 2) . '/core/Admin/views/layout';
    $offenders = [];

    foreach (glob($dir . '/*.php') ?: [] as $path) {
        $src = (string) file_get_contents($path);

        // Comentários fora: o docblock explica a armadilha citando `$user`,
        // e documentar o motivo não pode disparar a guarda.
        $code = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';
        $code = preg_replace('!//[^\n]*!', '', $code) ?? '';

        // \$user não seguido de mais letras — evita casar $users, $userCount
        // e o próprio $authUser (que não começa por "user").
        if (preg_match('/\$user(?![A-Za-z0-9_])/', $code) === 1) {
            $offenders[] = basename($path);
        }
    }

    check(
        $offenders === [],
        'layout lendo $user (a página sobrescreve): ' . implode(', ', $offenders)
    );
});

test('o usuário logado é compartilhado como authUser', function () {
    $src = (string) file_get_contents(
        dirname(__DIR__, 2) . '/core/Admin/Controllers/Controller.php'
    );

    check(
        str_contains($src, "share('authUser'"),
        'Controller deveria compartilhar o usuário logado como authUser'
    );
    check(
        !str_contains($src, "share('user'"),
        "share('user') voltou — é a chave que qualquer tela sobrescreve"
    );
});

test('users/edit.php continua dono do próprio $user', function () {
    // O outro lado da correção: renomear o compartilhado deixa a página livre
    // para usar `$user` como o registro que ela edita. Se alguém "consertar"
    // isso renomeando a página em vez do layout, a intenção se perde.
    $src = (string) file_get_contents(
        dirname(__DIR__, 2) . '/core/Admin/views/users/edit.php'
    );

    check(str_contains($src, '$user'), 'users/edit.php deveria usar $user para o registro editado');
    check(str_contains($src, '$currentUser'), 'users/edit.php deveria distinguir o logado via $currentUser');
});
