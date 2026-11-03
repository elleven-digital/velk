<?php

declare(strict_types=1);

namespace Velk;

/**
 * Página HTML standalone (sem tema/admin, que podem estar quebrados durante
 * um upgrade) pros estados de manutenção do auto-migrate. A página de falha é
 * DELIBERADAMENTE genérica — detalhe do erro vive no log/flag, nunca aqui.
 */
final class Maintenance
{
    public static function render(string $mode): string
    {
        $updating = $mode === 'updating';
        $title = $updating ? 'Atualizando…' : 'Atualização falhou';
        $body = $updating
            ? 'Estamos finalizando uma atualização do site. Recarregue em instantes.'
            : 'Não foi possível concluir a atualização. Tente novamente em instantes.';
        $refresh = $updating ? '<meta http-equiv="refresh" content="3">' : '';

        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $b = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{$refresh}
<title>{$t}</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
         background:#f5f5f5; color:#1a1a1a; }
  .card { max-width:420px; padding:40px 32px; text-align:center; background:#fff;
          border-radius:12px; box-shadow:0 1px 3px rgba(0,0,0,.1); }
  h1 { margin:0 0 12px; font-size:20px; }
  p  { margin:0; color:#555; line-height:1.5; }
</style>
</head>
<body>
  <div class="card">
    <h1>{$t}</h1>
    <p>{$b}</p>
  </div>
</body>
</html>
HTML;
    }
}
