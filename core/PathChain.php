<?php

declare(strict_types=1);

namespace Velk;

/**
 * Monta um path de rota a partir de uma cadeia de segmentos, subindo de
 * filho para pai via uma closure de resolução. Lógica pura — sem DB nem
 * Config — compartilhada por Page (subpáginas) e Item (itens hierárquicos).
 */
final class PathChain
{
    /**
     * $resolve(string $key): ?array — devolve
     *   ['segment' => string, 'parent' => ?string] para $key, ou null se a
     *   entrada não existe. `segment` é o pedaço local da URL (sem barras;
     *   '' = raiz vazia, tipo a home). `parent` é a key do pai, ou null na raiz.
     *
     * Guarda anti-ciclo: uma key já vista encerra a subida; profundidade
     * acima de $maxDepth idem. Degrada num path finito em vez de travar.
     */
    public static function build(string $key, callable $resolve, int $maxDepth = 25): string
    {
        $segments = [];
        $seen = [];
        $current = $key;
        $depth = 0;
        while ($current !== null && !isset($seen[$current]) && $depth < $maxDepth) {
            $seen[$current] = true;
            $node = $resolve($current);
            if ($node === null) break;
            $seg = (string) ($node['segment'] ?? '');
            if ($seg !== '') array_unshift($segments, $seg);
            $parent = $node['parent'] ?? null;
            $current = $parent !== null ? (string) $parent : null;
            $depth++;
        }
        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    /**
     * Coleta as keys descendentes de $key (sem incluir $key), dado um
     * provedor de filhos $childrenOf(key): list<string>. Guarda anti-ciclo
     * (keys já vistas não reexpandem). Puro — usado por Page e Item para o
     * filtro do dropdown "pai" e a validação anti-ciclo.
     */
    public static function descendants(string $key, callable $childrenOf): array
    {
        $result = [];
        $seen = [$key => true];
        $stack = [$key];
        while ($stack !== []) {
            $current = array_pop($stack);
            foreach ($childrenOf($current) as $child) {
                $child = (string) $child;
                if (isset($seen[$child])) continue;
                $seen[$child] = true;
                $result[] = $child;
                $stack[] = $child;
            }
        }
        return $result;
    }
}
