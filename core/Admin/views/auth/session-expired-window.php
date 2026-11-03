<?php
/**
 * Falha de CSRF de um formulário enviado dentro de um iframe do painel (a
 * janela de blocos do editor de texto) — quase sempre a sessão que morreu com
 * a janela aberta. Ver Controller::verifyCsrfOrFail(): ali um redirect
 * carregaria a tela inteira dentro da janela.
 */
?>
<div class="empty"><?= __('flash.session_expired_window') ?></div>
