<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\Database;

/**
 * As três operações que mantêm viva a sequência de `sort_order`.
 *
 * Vive num helper porque `Item` e `Term` precisam exatamente das mesmas e só
 * diferem em tabela, coluna de escopo e ordem de semeadura. Duplicar seria
 * duplicar também a parte sutil — a permutação.
 *
 * ─── Segurança ───────────────────────────────────────────────────────────
 *
 * `$tabela`, `$colunaEscopo` e `$ordemSemente` são interpolados na SQL e
 * portanto precisam ser SEMPRE literais escritos no código chamador. Nunca
 * passe entrada de usuário neles. `$escopo` e `$ids` vêm de fora e são
 * parametrizados/convertidos.
 */
final class ManualOrder
{
    /**
     * Reordena as linhas de `$ids` entre si.
     *
     * Redistribui, entre as linhas recebidas, os valores de `sort_order` que
     * essas MESMAS linhas já ocupavam — não renumera de 1 a N.
     *
     * É o que faz paginação e filtro funcionarem sem regra especial: mexer na
     * página 2 usa só as posições que já eram da página 2, e reordenar um
     * resultado de busca mexe só nas posições daquelas linhas. Renumerar
     * colidiria com as outras páginas e embaralharia o resto em silêncio.
     *
     * O efeito colateral aceito: com filtro ativo, uma linha escondida entre
     * duas que foram arrastadas permanece onde estava.
     *
     * @param list<int|string> $ids ids na nova ordem visual
     */
    public static function apply(
        Database $db,
        string $tabela,
        string $colunaEscopo,
        string $escopo,
        array $ids
    ): void {
        // array_unique é obrigatório, não higiene: um `ids[]` com repetido
        // faria a lista de alvos ficar maior que a de posições disponíveis, e
        // a atribuição estouraria o índice.
        $ids = array_values(array_unique(
            array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)
        ));
        if ($ids === []) {
            return;
        }

        // Só as linhas que realmente pertencem ao escopo. Um id de outro tipo
        // vindo no POST não pode ser reposicionado.
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $linhas = $db->fetchAll(
            "SELECT id, sort_order FROM {$tabela}
             WHERE {$colunaEscopo} = ? AND id IN ({$marcadores})",
            array_merge([$escopo], $ids)
        );
        if ($linhas === []) {
            return;
        }

        $existentes = [];
        foreach ($linhas as $linha) {
            $existentes[(int) $linha['id']] = (int) $linha['sort_order'];
        }

        // Os ids válidos, na ordem visual recebida.
        $alvo = array_values(array_filter($ids, static fn(int $id): bool => isset($existentes[$id])));

        // As posições que essas linhas ocupam hoje, em ordem crescente.
        $posicoes = array_values($existentes);
        sort($posicoes);

        foreach ($alvo as $indice => $id) {
            if ($existentes[$id] === $posicoes[$indice]) {
                continue; // já está no lugar; poupa um UPDATE
            }
            $db->update($tabela, ['sort_order' => $posicoes[$indice]], ['id' => $id]);
        }
    }

    /**
     * Normaliza `sort_order` sempre que o escopo tem posição repetida — não
     * só quando está todo empatado no mesmo valor.
     *
     * A detecção compara a contagem total de linhas com a contagem de
     * valores distintos de `sort_order`: se forem iguais, a sequência já é
     * íntegra e o método não toca em nada. Se forem diferentes, existe
     * empate em algum ponto do escopo — não precisa ser o escopo inteiro —
     * e a sequência precisa ser corrigida. `MIN = MAX` só pegava o caso
     * "tudo em 0"; não pegava, por exemplo, `{0,0,0,1}`, que é exatamente o
     * estado que sobra quando um registro novo é criado — com `next()`
     * fornecendo seu `sort_order` — antes da primeira semeadura do escopo:
     * o registro novo entra com sort_order=1, MIN vira 0 e MAX vira 1 (logo
     * deixam de ser iguais), mas os outros três continuam empatados em 0. E
     * como `apply()` só redistribui os valores que já existem, esse empate
     * nunca se desfazia sozinho — a listagem ficava permanentemente fora de
     * ordem, por tipo, a partir do primeiro registro criado antes de ela
     * ser aberta.
     *
     * A releitura ordena por `sort_order ASC, {$ordemSemente}`: a ordem
     * atual manda sempre que já for distinta, e a semente só desempata quem
     * colidir. Com tudo em zero, `sort_order` é constante e a ordem sai
     * inteiramente da semente — o caso comum, intacto. Com `{0,0,0,1}`, os
     * três zeros são ordenados pela semente entre si e o registro novo,
     * único em sort_order=1, fica por último — onde uma posição nova deve
     * ficar. E numa lista já curada por arrasto que acumulou um empate
     * pontual, a ordem das linhas não-empatadas sobrevive: só quem está
     * empatado se reordena.
     *
     * Por isso o método não renumera cru (reatribuir 1..N direto pela
     * semente, ignorando o `sort_order` atual): isso jogaria fora qualquer
     * curadoria já feita à mão no escopo, trocando a ordem que a pessoa
     * arrastou pela ordem da semente inteira. Ordenar por `sort_order`
     * primeiro é o que torna a correção cirúrgica em vez de destrutiva.
     *
     * @param string $ordemSemente cláusula ORDER BY, literal do chamador
     */
    public static function ensureSeeded(
        Database $db,
        string $tabela,
        string $colunaEscopo,
        string $escopo,
        string $ordemSemente
    ): void {
        $resumo = $db->fetchAll(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT sort_order) AS distintos
             FROM {$tabela} WHERE {$colunaEscopo} = ?",
            [$escopo]
        );
        $total = (int) ($resumo[0]['total'] ?? 0);
        $distintos = (int) ($resumo[0]['distintos'] ?? 0);
        if ($total < 2 || $total === $distintos) {
            return;
        }

        $linhas = $db->fetchAll(
            "SELECT id, sort_order FROM {$tabela} WHERE {$colunaEscopo} = ?
             ORDER BY sort_order ASC, {$ordemSemente}",
            [$escopo]
        );

        // Mesma otimização de apply(): pular o UPDATE de quem já está no
        // valor certo. Num escopo de milhares de linhas com um empate
        // pontual, isso é a diferença entre uma UPDATE por linha (tudo em
        // autocommit, dentro de um GET) e só as poucas que de fato mudam.
        // A transação embrulha as que sobram — ou todas mudam, ou nenhuma.
        $db->transaction(function ($db) use ($tabela, $linhas): void {
            $posicao = 1;
            foreach ($linhas as $linha) {
                if ((int) $linha['sort_order'] !== $posicao) {
                    $db->update($tabela, ['sort_order' => $posicao], ['id' => (int) $linha['id']]);
                }
                $posicao++;
            }
        });
    }

    /**
     * A posição de um registro novo: no fim da lista, para não bagunçar
     * ordem curada. Escopo vazio começa em 1.
     */
    public static function next(
        Database $db,
        string $tabela,
        string $colunaEscopo,
        string $escopo
    ): int {
        $maior = $db->fetchAll(
            "SELECT MAX(sort_order) AS maior FROM {$tabela} WHERE {$colunaEscopo} = ?",
            [$escopo]
        );

        return ((int) ($maior[0]['maior'] ?? 0)) + 1;
    }
}
