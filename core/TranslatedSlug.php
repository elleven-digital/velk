<?php

declare(strict_types=1);

namespace Velk;

/**
 * Busca de um registro pelo slug num idioma que não é o padrão.
 *
 * Num idioma não-padrão, o endereço de um item, termo ou página usa o slug
 * traduzido (`slug_translations.<idioma>`) — ou, quando esse idioma não tem
 * slug traduzido (ausente ou em branco), o slug do idioma padrão. Cada
 * registro tem um só endereço por idioma: quem tem slug traduzido não abre
 * pelo slug padrão naquele idioma.
 *
 * Num conflito — o slug traduzido de um registro é o slug padrão de outro
 * sem tradução —, o traduzido ganha: a primeira busca é pelo traduzido, e só
 * sem resultado entra a segunda, pelo slug padrão de quem não tem tradução.
 *
 * Em tabelas com `status` (itens), quem chama pede `$publishedFirst`: um
 * rascunho nunca toma o endereço de um registro publicado — o publicado
 * ganha antes de tudo, e entre dois de mesmo status vale a regra acima.
 */
final class TranslatedSlug
{
    /**
     * SQL: o registro não tem slug traduzido no idioma do caminho `?` —
     * chave ausente, `null` ou texto vazio. Compara o JSON cru (CAST AS
     * CHAR): no MySQL, `JSON_UNQUOTE` de um `null` do JSON é o texto 'null',
     * igual a um slug "null" de verdade; o JSON cru distingue `null` de
     * `"null"`.
     */
    public const UNTRANSLATED = "COALESCE(CAST(JSON_EXTRACT(slug_translations, ?) AS CHAR), 'null') IN ('null', '\"\"')";

    /**
     * A linha de `$table` que responde por `$slug` em `$language`, ou null.
     * `$where` e `$params` restringem a busca (tipo, taxonomia, pai…).
     *
     * @param list<mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetch(string $table, string $where, array $params, string $slug, string $language, bool $publishedFirst = false): ?array
    {
        if ($publishedFirst) {
            return self::candidates($table, $where, $params, $slug, $language, true)[0] ?? null;
        }
        $db = App::instance()->db;
        $path = '$."' . $language . '"';
        return $db->fetch(
            "SELECT * FROM {$table} WHERE {$where}
               AND JSON_UNQUOTE(JSON_EXTRACT(slug_translations, ?)) = ? AND NOT " . self::UNTRANSLATED . " LIMIT 1",
            [...$params, $path, $slug, $path]
        ) ?? $db->fetch(
            "SELECT * FROM {$table} WHERE {$where} AND slug = ? AND " . self::UNTRANSLATED . " LIMIT 1",
            [...$params, $slug, $path]
        );
    }

    /**
     * Todas as linhas de `$table` que respondem por `$slug` em `$language`,
     * na ordem de prioridade: as que têm esse slug traduzido, depois as que
     * têm esse slug padrão e nenhuma tradução. É o que a descida de uma
     * hierarquia precisa: prefere o ramo traduzido, mas volta para o outro
     * quando o traduzido não tem o resto do caminho. Com `$publishedFirst`,
     * os publicados vêm antes dos rascunhos, cada grupo nessa mesma ordem.
     *
     * @param list<mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function candidates(string $table, string $where, array $params, string $slug, string $language, bool $publishedFirst = false): array
    {
        $db = App::instance()->db;
        $path = '$."' . $language . '"';
        $rows = [
            ...$db->fetchAll(
                "SELECT * FROM {$table} WHERE {$where}
                   AND JSON_UNQUOTE(JSON_EXTRACT(slug_translations, ?)) = ? AND NOT " . self::UNTRANSLATED,
                [...$params, $path, $slug, $path]
            ),
            ...$db->fetchAll(
                "SELECT * FROM {$table} WHERE {$where} AND slug = ? AND " . self::UNTRANSLATED,
                [...$params, $slug, $path]
            ),
        ];
        return $publishedFirst ? self::publishedFirst($rows) : $rows;
    }

    /**
     * As linhas publicadas antes das outras, sem mudar a ordem dentro de
     * cada grupo.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function publishedFirst(array $rows): array
    {
        $published = static fn(array $row): bool => ($row['status'] ?? null) === 'published';
        return [
            ...array_values(array_filter($rows, $published)),
            ...array_values(array_filter($rows, static fn(array $row): bool => !$published($row))),
        ];
    }

    /**
     * Desce uma hierarquia (itens ou termos) segmento a segmento, a partir
     * da raiz, e devolve a linha da folha — ou null. Em cada nível tenta
     * os candidatos na ordem de candidates(): o traduzido ganha o nível,
     * mas um ramo sem o resto do caminho não esconde o outro, que é onde o
     * link da folha aponta.
     *
     * `$where` + `$params` restringem a tabela (tipo, taxonomia); o pai de
     * cada nível entra como `parent_id`. `$publishedFirst` como em
     * candidates(), em cada nível.
     *
     * @param list<mixed> $params
     * @param list<string> $segments
     * @return array<string, mixed>|null
     */
    public static function descend(string $table, string $where, array $params, array $segments, string $language, string $defaultLanguage, bool $publishedFirst = false, ?int $parentId = null): ?array
    {
        if ($segments === []) return null;
        $slug = (string) array_shift($segments);
        $parentCond = $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';
        $scope = $parentId === null ? $params : [...$params, $parentId];
        $rows = $language === $defaultLanguage
            ? App::instance()->db->fetchAll(
                "SELECT * FROM {$table} WHERE {$where} AND {$parentCond} AND slug = ?",
                [...$scope, $slug]
            )
            : self::candidates($table, "{$where} AND {$parentCond}", $scope, $slug, $language);
        if ($publishedFirst) {
            $rows = self::publishedFirst($rows);
        }
        foreach ($rows as $row) {
            if ($segments === []) return $row;
            $found = self::descend($table, $where, $params, $segments, $language, $defaultLanguage, $publishedFirst, (int) $row['id']);
            if ($found !== null) return $found;
        }
        return null;
    }
}
