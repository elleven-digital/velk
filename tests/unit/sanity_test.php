<?php

declare(strict_types=1);

test('harness runs and asserts', function () {
    check_same(4, 2 + 2);
    check(true, 'true is true');
});
