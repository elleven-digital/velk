<?php

declare(strict_types=1);

use Velk\Models\Item;

test('adminWhere: só type', function () {
    check_same(['type = ?', ['produto']], Item::adminWhere('produto', null, null));
});

test('adminWhere: status válido entra; vazio/all não', function () {
    [$sql, $params] = Item::adminWhere('produto', 'draft', null);
    check_same('type = ? AND status = ?', $sql);
    check_same(['produto', 'draft'], $params);
    check_same(['type = ?', ['produto']], Item::adminWhere('produto', '', null));
    check_same(['type = ?', ['produto']], Item::adminWhere('produto', 'all', null));
});

test('adminWhere: busca vira LIKE em título e slug', function () {
    [$sql, $params] = Item::adminWhere('produto', null, ' blusa ');
    check_same('type = ? AND (title LIKE ? OR slug LIKE ?)', $sql);
    check_same(['produto', '%blusa%', '%blusa%'], $params);
});

test('adminWhere: tudo combinado, ordem estável', function () {
    [$sql, $params] = Item::adminWhere('produto', 'published', 'x');
    check_same('type = ? AND status = ? AND (title LIKE ? OR slug LIKE ?)', $sql);
    check_same(['produto', 'published', '%x%', '%x%'], $params);
});
