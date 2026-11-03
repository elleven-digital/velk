<?php
/**
 * A resposta do `blocks/save`: os valores do bloco num bloco de dados, que o
 * admin.js desta página entrega à página de fora (o editor de texto). Um
 * bloco de dados, e não um script: a política de conteúdo do painel não
 * aceita JavaScript embutido.
 *
 * @var array{type: string, data: array<string, mixed>} $result
 */
?>
<?= json_script('velk-block-result', $result) ?>
<p class="muted"><?= __('blocks.saved') ?></p>
