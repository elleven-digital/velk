<?php
/**
 * @var array $form
 * @var string $formId
 * @var string $siteName
 * @var \Velk\Models\FormSubmission $submission
 * @var array $values
 * @var array $fields
 */
$label = (string) ($form['label'] ?? $formId);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Novo envio — <?= e($label) ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f5f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;color:#0a0a0a;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f6;padding:32px 16px;">
  <tr>
    <td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e6e6e8;border-radius:12px;">

        <?php // Sem marca no topo. Havia um quadrado preto com a letra "E"
              // CHUMBADA — não derivada do nome do site — ao lado do nome:
              // num site que não começasse com E, a inicial simplesmente não
              // correspondia a nada. O nome continua no rodapé, que já o
              // trazia, e é onde a procedência basta. ?>
        <tr>
          <td style="padding:24px 32px 4px;">
            <p style="margin:0;font-size:12px;color:#6b6b6b;text-transform:uppercase;letter-spacing:0.06em;font-weight:500;">Novo preenchimento</p>
            <h1 style="margin:6px 0 0;font-size:22px;font-weight:600;letter-spacing:-0.02em;line-height:1.2;color:#0a0a0a;"><?= e($label) ?></h1>
          </td>
        </tr>

        <tr>
          <td style="padding:24px 32px 8px;">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;">
              <?php foreach ($fields as $field):
                  $name = (string) ($field['name'] ?? '');
                  if ($name === '') continue;
                  $fieldLabel = (string) ($field['label'] ?? $name);
                  $rawValue = $values[$name] ?? '';
                  // Campo de arquivo: valor é um array de metadados (marcador
                  // `_file`), não uma string — precisa ser tratado ANTES do
                  // (string) cast abaixo, senão vira "Array to string
                  // conversion". Nunca anexamos o arquivo nem expomos link
                  // público — só nome, tamanho, e um link para o painel
                  // (autenticado).
                  $isFile = is_array($rawValue) && !empty($rawValue['_file']);
                  $value = ($isFile || is_array($rawValue)) ? '' : (string) $rawValue;
              ?>
                <tr>
                  <td style="padding:10px 0;border-top:1px solid #e6e6e8;width:140px;vertical-align:top;font-size:12px;color:#6b6b6b;">
                    <?= e($fieldLabel) ?>
                  </td>
                  <td style="padding:10px 0;border-top:1px solid #e6e6e8;font-size:14px;color:#0a0a0a;white-space:pre-wrap;word-break:break-word;">
                    <?php if ($isFile): ?>
                      <?= e((string) ($rawValue['name'] ?? '')) ?>
                      <span style="color:#6b6b6b;font-size:12px;">(<?= e(human_filesize((int) ($rawValue['size'] ?? 0))) ?>)</span>
                      <br>
                      <a href="<?= e(absolute_admin_url('forms/' . $formId . '/submissions/' . $submission->id . '/file/' . $name)) ?>" style="color:#0a0a0a;">Baixar no painel</a>
                    <?php else: ?>
                      <?= $value === '' ? '<span style="color:#a3a3a3">—</span>' : e($value) ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </table>
          </td>
        </tr>

        <?php
        // Bloco de parâmetros de rastreamento — só quando houve captura.
        // Espelha o painel: separador + cabeçalho pequeno + linhas chave/valor.
        // Ajuda o destinatário a avaliar a origem do lead direto da caixa de
        // entrada. Chave crua (utm_source, gclid...) porque a whitelist é
        // aberta: rotular cada uma exigiria um mapa que envelhece a cada
        // plataforma nova.
        $paramRows = $submission->params;
        ?>
        <?php if ($paramRows !== []): ?>
          <tr>
            <td style="padding:8px 32px 0;">
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;border-top:1px solid #e6e6e8;">
                <tr>
                  <td colspan="2" style="padding:14px 0 6px;font-size:11px;color:#6b6b6b;text-transform:uppercase;letter-spacing:0.06em;font-weight:500;">
                    Origem
                  </td>
                </tr>
                <?php foreach ($paramRows as $label => $val): ?>
                  <tr>
                    <td style="padding:6px 0;width:140px;vertical-align:top;font-size:12px;color:#6b6b6b;">
                      <?= e($label) ?>
                    </td>
                    <td style="padding:6px 0;font-size:13px;color:#0a0a0a;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all;">
                      <?= e((string) $val) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </table>
            </td>
          </tr>
        <?php endif; ?>

        <tr>
          <td style="padding:16px 32px 24px;">
            <p style="margin:0;font-size:11px;color:#888;line-height:1.5;">
              Recebido em <?= e(date('d/m/Y H:i:s', strtotime($submission->createdAt))) ?>
              <?php if ($submission->ip): ?> · IP <?= e($submission->ip) ?><?php endif; ?>
              <?php if ($submission->referer): ?>
                · de <a href="<?= e($submission->referer) ?>" style="color:#0a0a0a;"><?= e($submission->referer) ?></a>
              <?php endif; ?>
            </p>
          </td>
        </tr>
      </table>

      <p style="margin:16px 0 0;font-size:11px;color:#999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
        <?= e($siteName) ?> · Envio automático pelo formulário <code style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#fff;padding:1px 4px;border-radius:3px;border:1px solid #e6e6e8;"><?= e($formId) ?></code>
      </p>
    </td>
  </tr>
</table>
</body>
</html>
