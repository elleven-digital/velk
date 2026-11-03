<?php

declare(strict_types=1);

use Velk\Models\Item;

/**
 * A forma orientada a objeto como alternativa completa ao contexto ambiente.
 *
 * `field()` nos models passou a resolver o IDIOMA ATIVO, delegando para
 * `fieldIn()` — que já caía no valor padrão quando não há tradução. É essa
 * queda que torna a mudança segura: campo não traduzido (preço, por exemplo)
 * devolve exatamente o que devolvia antes, e é disso que OrderService depende.
 */

if (!function_exists('oo_item')) {
    function oo_item(array $fields, array $traducoes = []): Item
    {
        $i = new Item();
        $i->title = 'Padrão';
        $i->fields = $fields;
        $i->fieldsTranslations = $traducoes;
        $i->titleTranslations = [];
        return $i;
    }
}

test('fieldIn cai no valor padrão quando não há tradução', function () {
    // A propriedade que sustenta a mudança inteira. Se isto deixar de valer,
    // `$item->field("price")` passa a devolver null num site multi-idioma e
    // o checkout quebra em silêncio.
    $i = oo_item(['price' => '49,90']);
    check_same('49,90', $i->fieldIn('price', 'en'));
    check_same('49,90', $i->fieldIn('price', 'pt-BR'));
});

test('tradução vazia não vence o valor padrão', function () {
    // Campo traduzido mas deixado em branco no painel: cair no padrão é o
    // certo — melhor o texto original que um espaço vazio na página.
    $i = oo_item(['titulo' => 'Original'], ['en' => ['titulo' => '']]);
    check_same('Original', $i->fieldIn('titulo', 'en'));

    $comTraducao = oo_item(['titulo' => 'Original'], ['en' => ['titulo' => 'Translated']]);
    check_same('Translated', $comTraducao->fieldIn('titulo', 'en'));
});

test('titleIn segue a mesma regra de queda', function () {
    $i = oo_item([]);
    check_same('Padrão', $i->titleIn('en'));

    $i->titleTranslations = ['en' => 'Default'];
    check_same('Default', $i->titleIn('en'));

    $i->titleTranslations = ['en' => ''];
    check_same('Padrão', $i->titleIn('en'), 'tradução vazia deveria cair no padrão');
});

test('field() e title() existem como método de instância', function () {
    // O que a forma OO precisa para não depender de with_context().
    check(method_exists(Item::class, 'field'), 'Item::field');
    check(method_exists(Item::class, 'title'), 'Item::title');
    check(method_exists(Item::class, 'url'), 'Item::url');
    check(method_exists(\Velk\Models\Page::class, 'field'), 'Page::field');
    check(method_exists(\Velk\Models\Page::class, 'title'), 'Page::title');
    check(method_exists(\Velk\Models\Page::class, 'url'), 'Page::url — era o buraco');
    check(method_exists(\Velk\Models\Term::class, 'field'), 'Term::field');
    check(method_exists(\Velk\Models\Term::class, 'name'), 'Term::name');
    check(method_exists(\Velk\Models\Term::class, 'url'), 'Term::url');
});
