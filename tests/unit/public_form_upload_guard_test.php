<?php

declare(strict_types=1);

use Velk\PublicFormController;

/**
 * PublicFormController::postWasDiscarded — mesma checagem pura de
 * Velk\Admin\Controllers\MediaController::postWasDiscarded (ver
 * tests/unit/upload_error_handling_test.php), replicada no controller
 * público: quando o POST estoura post_max_size, o PHP descarta TUDO
 * (inclusive o _csrf) e, sem esta checagem, o visitante veria "sessão
 * expirada" — uma mentira — em vez de "arquivo muito grande". A checagem
 * só pode disparar nesse caso exato; nunca deve enfraquecer o CSRF em
 * nenhum outro cenário (ver segundo teste abaixo).
 */

test('postWasDiscarded: corpo grande com POST/FILES vazios = descartado pelo PHP', function () {
    check_same(true, PublicFormController::postWasDiscarded(9000000, [], []));
});

test('postWasDiscarded: casos normais não disparam (CSRF continua a única defesa)', function () {
    check_same(false, PublicFormController::postWasDiscarded(0, [], []));
    check_same(false, PublicFormController::postWasDiscarded(9000000, ['_csrf' => 'x'], []));
    check_same(false, PublicFormController::postWasDiscarded(9000000, [], ['curriculo' => ['name' => 'a.pdf']]));
    check_same(false, PublicFormController::postWasDiscarded(0, ['_csrf' => 'x'], ['curriculo' => ['name' => 'a.pdf']]));
});
