<?php

declare(strict_types=1);

/**
 * v3.7.5 — `manual_order` no site.json.
 *
 * A chave é booleana e opcional. O `schema:validate` precisa reclamar de
 * valor não-booleano pela mesma razão que já reclama de `hierarchical`:
 * `"manual_order": "true"` (string) é um erro de digitação silencioso — a
 * comparação `!== false` aceitaria a string e o recurso ligaria sozinho.
 *
 * O validador vive dentro de bin/velk como closure, então o teste roda o
 * comando de verdade contra um site.json temporário.
 */

if (!function_exists('mos_validate')) {
    /**
     * Roda `bin/velk schema:validate` contra uma cópia do site.json do tema
     * com o patch aplicado, e devolve a saída.
     *
     * A restauração do arquivo original fica num `finally`: se qualquer
     * coisa der errado entre escrever o patch e rodar o comando — uma
     * exceção, um erro — o site.json de verdade (fora do .gitignore só por
     * não estar versionado, não por deixar de importar) ainda volta ao
     * normal antes da função devolver o controle. Sem isso um teste que
     * falhasse no meio deixaria o arquivo corrompido para todos os testes
     * seguintes. Também se auto-cura no início: se um backup ficou para
     * trás de uma execução anterior interrompida (kill -9, timeout), ele é
     * restaurado antes de começar, em vez de ficar ignorado para sempre.
     *
     * As três operações de disco (o rename de auto-cura, o copy do backup,
     * o rename de restauração no finally) têm o retorno checado e lançam se
     * falharem: sem isso, uma falha de filesystem escaparia em silêncio e
     * deixaria o site.json do desenvolvedor com o patch de teste
     * permanentemente aplicado. `item_types`/`taxonomies` vazios (`{}`, a
     * forma que o próprio schema:validate recomenda quando o tema não tem
     * nenhuma) também são tratados explicitamente: sem o guard,
     * `array_key_first([])` devolve null e o `array_merge` seguinte morre
     * com TypeError em vez de uma mensagem legível.
     *
     * Ainda exige um `theme/site.json` real — não roda em árvore limpa,
     * onde `theme/` (gitignored, é `user_path`) não existe.
     *
     * @param array<string,mixed> $patchItemType  mesclado no primeiro item_type
     * @param array<string,mixed> $patchTaxonomy  mesclado na primeira taxonomia
     */
    function mos_validate(array $patchItemType = [], array $patchTaxonomy = []): string
    {
        $raiz = dirname(__DIR__, 2);
        $origem = $raiz . '/theme/site.json';
        $backup = $origem . '.mos-backup';

        if (is_file($backup) && !rename($backup, $origem)) {
            throw new \RuntimeException("mos_validate: falha ao restaurar backup de uma execução anterior ({$backup} → {$origem}).");
        }

        $site = json_decode((string) file_get_contents($origem), true);
        if (!is_array($site)) {
            throw new \RuntimeException("mos_validate: {$origem} não existe ou não é JSON válido — este teste precisa de um theme/ real.");
        }

        if ($patchItemType !== []) {
            $chave = array_key_first($site['item_types'] ?? []);
            if ($chave === null) {
                throw new \RuntimeException('mos_validate: site.json não tem nenhum item_type para aplicar o patch.');
            }
            $site['item_types'][$chave] = array_merge($site['item_types'][$chave], $patchItemType);
        }
        if ($patchTaxonomy !== []) {
            $chave = array_key_first($site['taxonomies'] ?? []);
            if ($chave === null) {
                throw new \RuntimeException('mos_validate: site.json não tem nenhuma taxonomy para aplicar o patch.');
            }
            $site['taxonomies'][$chave] = array_merge($site['taxonomies'][$chave], $patchTaxonomy);
        }

        if (!copy($origem, $backup)) {
            throw new \RuntimeException("mos_validate: falha ao criar backup ({$origem} → {$backup}) — abortando antes de tocar no site.json real.");
        }

        try {
            file_put_contents($origem, json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $saida = shell_exec(
                escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($raiz . '/bin/velk') . ' schema:validate 2>&1'
            );

            return is_string($saida) ? $saida : '';
        } finally {
            if (!rename($backup, $origem)) {
                throw new \RuntimeException("mos_validate: falha ao restaurar {$origem} a partir do backup — o site.json real pode estar corrompido; restaure manualmente de {$backup}.");
            }
        }
    }
}

test('schema:validate: manual_order booleano passa', function () {
    $saida = mos_validate(['manual_order' => true], ['manual_order' => true]);
    check(str_contains($saida, 'site.json is valid'), "deveria validar; saída:\n{$saida}");
});

test('schema:validate: manual_order ausente continua válido', function () {
    // Retrocompatibilidade: todo site.json existente não tem a chave.
    $saida = mos_validate();
    check(str_contains($saida, 'site.json is valid'), "sem a chave deveria validar; saída:\n{$saida}");
});

test('schema:validate: manual_order string é reprovado no item_type', function () {
    $saida = mos_validate(['manual_order' => 'true']);
    check(str_contains($saida, 'manual_order'), "deveria citar manual_order; saída:\n{$saida}");
    check(!str_contains($saida, 'site.json is valid'), 'não pode passar com string');
});

test('schema:validate: manual_order string é reprovado na taxonomia', function () {
    $saida = mos_validate([], ['manual_order' => 'sim']);
    check(str_contains($saida, 'manual_order'), "deveria citar manual_order; saída:\n{$saida}");
    check(!str_contains($saida, 'site.json is valid'), 'não pode passar com string');
});
