<?php

declare(strict_types=1);

use Velk\Admin\Controllers\MediaController;

test('parseAcceptCategories: null/vazio → []', function () {
    check_same([], MediaController::parseAcceptCategories(null));
    check_same([], MediaController::parseAcceptCategories(''));
});

test('parseAcceptCategories: CSV de categorias válidas', function () {
    check_same(['image'], MediaController::parseAcceptCategories('image'));
    check_same(['image', 'video'], MediaController::parseAcceptCategories('image,video'));
    check_same(['image', 'video'], MediaController::parseAcceptCategories(' image , video '));
});

test('parseAcceptCategories: inválidas fora, dedup, ordem preservada', function () {
    check_same(['image', 'video'], MediaController::parseAcceptCategories('image,banana,video'));
    check_same(['image'], MediaController::parseAcceptCategories('image,image'));
    check_same([], MediaController::parseAcceptCategories('banana,maçã'));
});
