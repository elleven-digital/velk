<?php

declare(strict_types=1);

use Velk\Models\FormSubmission;

// Desde a v3.7.2 o `filterWhere` também governa o recorte spam/aprovado, e
// por isso NUNCA devolve cláusula vazia: ausência de filtro significa "a
// lista normal", que já é uma condição (`blocked_by IS NULL`). O default
// vive aqui de propósito — deixá-lo por conta de cada consulta faria
// esquecer uma encher a lista de leads do cliente com bot, em silêncio.
test('filterWhere: sem filtros → aprovadas E completas', function () {
    // Dois recortes, e os dois são o default seguro: ausência de chave
    // significa "a lista normal", que é lead aprovado e concluído. O de
    // completude entrou na v3.8.0 com o preenchimento por etapas, e vive
    // aqui pelo mesmo motivo que o de spam — deixá-lo por conta de cada
    // consulta faria esquecer uma encher a lista do cliente com lead pela
    // metade, em silêncio.
    $esperado = [' AND blocked_by IS NULL AND pending_step IS NULL', []];
    check_same($esperado, FormSubmission::filterWhere([]));
    check_same($esperado, FormSubmission::filterWhere(['date_from' => '', 'utm_source' => '']));
});

test('filterWhere: datas viram range inclusivo (início/fim de dia)', function () {
    [$sql, $params] = FormSubmission::filterWhere([
        'date_from' => '2026-07-01', 'date_to' => '2026-07-02',
    ]);
    // O recorte de completude (v3.8.0) entra entre blocked_by e as datas —
    // mesmo default seguro do teste acima, aqui convivendo com data.
    check_same(' AND blocked_by IS NULL AND pending_step IS NULL AND created_at >= ? AND created_at <= ?', $sql);
    check_same(['2026-07-01 00:00:00', '2026-07-02 23:59:59'], $params);
});

test('filterWhere: parâmetro de rastreamento NÃO filtra mais', function () {
    // UTM saiu do filtro na v4: é insumo de ferramenta de análise (GA4, Ads),
    // e o painel competia mal com quem faz melhor. Os parâmetros continuam
    // visíveis no detalhe da submissão, agora em `params` — só não são
    // dimensão de consulta, e por isso deixaram de ser coluna.
    [$sql, $params] = FormSubmission::filterWhere([
        'utm_source' => 'google', 'utm_campaign' => 'inverno', 'evil' => 'x',
    ]);
    // Idem: nenhum desses filtros é `completeness`, então o default seguro
    // (só completos) continua valendo aqui também.
    check_same(' AND blocked_by IS NULL AND pending_step IS NULL', $sql);
    check_same([], $params);
});
