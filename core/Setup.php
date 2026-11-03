<?php

declare(strict_types=1);

namespace Velk;

final class Setup
{
    /**
     * Render a setup-required HTML page describing what's missing and how to fix it.
     */
    public static function renderRequired(SetupException $e): string
    {
        $state = $e->state;
        $cssPath = '/' . trim(($_SERVER['SCRIPT_NAME'] ?? ''), '/');
        // Best-effort guess at base path so the page can load without DB
        $base = '';
        foreach (['/index.php', '/public/index.php'] as $suffix) {
            if (str_ends_with($cssPath, $suffix)) {
                $base = substr($cssPath, 0, -strlen($suffix));
                break;
            }
        }

        $row = function (string $label, ?bool $ok) {
            $status = $ok === true ? 'OK' : ($ok === false ? 'MISSING' : '—');
            $cls = $ok === true ? 'ok' : ($ok === false ? 'missing' : 'na');
            return sprintf(
                '<li class="row row--%s"><span class="row__label">%s</span><span class="row__status">%s</span></li>',
                $cls,
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                $status
            );
        };

        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Setup required — Velk</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; background: #fff; color: #0a0a0a; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .panel { width: 100%; max-width: 560px; border: 1px solid #e6e6e8; border-radius: 12px; padding: 32px; box-shadow: 0 8px 24px -4px rgba(0,0,0,0.08); }
  .mark { width: 36px; height: 36px; background: #0a0a0a; color: #fff; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; font-weight: 700; font-size: 16px; letter-spacing: -0.04em; }
  h1 { font-size: 22px; font-weight: 600; letter-spacing: -0.015em; margin: 16px 0 6px; }
  p { color: #5a5a5a; margin: 0 0 16px; line-height: 1.55; }
  ul.checklist { list-style: none; padding: 0; margin: 16px 0 24px; border: 1px solid #e6e6e8; border-radius: 8px; overflow: hidden; }
  ul.checklist li { display: flex; justify-content: space-between; padding: 10px 14px; font-size: 14px; }
  ul.checklist li + li { border-top: 1px solid #e6e6e8; }
  .row__status { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 11px; padding: 2px 8px; border-radius: 999px; border: 1px solid #e6e6e8; font-weight: 500; }
  .row--ok .row__status { background: #0a0a0a; color: #fff; border-color: #0a0a0a; }
  .row--missing .row__status { background: #fff; color: #c4292b; border-color: rgba(196,41,43,.4); }
  .row--na .row__status { color: #8a8a8a; }
  pre { background: #fafafa; border: 1px solid #e6e6e8; border-radius: 6px; padding: 12px 14px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 12px; overflow-x: auto; margin: 0 0 16px; }
  .error { background: rgba(196,41,43,0.05); border: 1px solid rgba(196,41,43,0.25); color: #c4292b; padding: 10px 14px; border-radius: 6px; font-size: 13px; margin: 0 0 16px; }
  h2 { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #5a5a5a; margin: 24px 0 8px; }
  .hint { font-size: 12px; color: #8a8a8a; margin-top: 8px; }
</style>
</head>
<body>
<main class="panel">
  <span class="mark">E</span>
  <h1>Setup required</h1>
  <p>Velk está instalado mas ainda não foi configurado neste servidor.</p>

  {ERROR}

  <ul class="checklist">
    {ROWS}
  </ul>

  {INSTRUCTIONS}
</main>
</body>
</html>
HTML;

        $rows = '';
        $rows .= $row('Tema instalado (theme/site.json)', $state['theme'] ?? null);
        $rows .= $row('Banco de dados acessível', $state['database'] ?? null);
        $rows .= $row('Tabelas criadas (migrations)', $state['tables'] ?? null);
        $rows .= $row('Usuário inicial', $state['users'] ?? null);

        $errorBlock = $message !== '' && !str_contains($message, 'Setup required')
            ? '<div class="error">' . $message . '</div>'
            : '';

        // If the theme is the only thing missing, swap the install instructions
        // for theme-specific guidance.
        $themeMissing = empty($state['theme']);

        return strtr($html, [
            '{ROWS}' => $rows,
            '{ERROR}' => $errorBlock,
            '{INSTRUCTIONS}' => $themeMissing
                ? self::themeInstructions()
                : self::installInstructions(),
        ]);
    }

    private static function themeInstructions(): string
    {
        return <<<'HTML'
<h2>Falta instalar um tema</h2>
<p style="font-size:13px">O Velk core não inclui tema. Cada projeto tem o seu próprio — um tema é uma pasta com <code>templates/</code>, <code>partials/</code>, <code>style.css</code> e <code>site.json</code> (o schema do site).</p>

<p style="font-size:13px;margin-top:16px">Para começar, coloque um tema em <code>theme/</code>:</p>

<pre>git clone https://github.com/seu-org/seu-tema.git theme</pre>

<p class="hint">Depois que o tema estiver presente, recarregue. O Velk lê <code>theme/site.json</code> para descobrir as páginas, tipos de itens, taxonomias e campos do site.</p>
HTML;
    }

    private static function installInstructions(): string
    {
        return <<<'HTML'
<h2>Opção 1 — via CLI</h2>
<pre>./bin/velk install</pre>

<h2>Opção 2 — pelo .env</h2>
<p style="font-size:13px">Adicione estas variáveis ao <code>.env</code> e recarregue:</p>
<pre>INITIAL_USER_EMAIL=voce@exemplo.com
INITIAL_USER_PASSWORD=sua-senha-segura
INITIAL_USER_NAME="Seu Nome"</pre>
<p class="hint">A instalação roda automaticamente no próximo acesso. As credenciais ficam ativas só até existir o primeiro usuário — depois disso, podem ser removidas do <code>.env</code>.</p>
HTML;
    }
}
