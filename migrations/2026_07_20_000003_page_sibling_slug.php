<?php

declare(strict_types=1);

use Velk\Database;

/**
 * Slugs de página custom passam a ser únicos entre IRMÃOS (mesmo pai), não
 * mais no site inteiro — páginas com pais diferentes podem repetir o slug
 * (ex.: /servicos/contato e /produtos/contato). Removemos o UNIQUE(slug)
 * global e deixamos um índice comum (slug) para casar candidatos na
 * resolução de URL.
 *
 * A identidade única continua sendo `page_key` (UNIQUE), agora DESACOPLADO
 * do slug: é gerado na criação com sufixo em colisão e não muda ao renomear
 * o slug. A unicidade entre irmãos é validada na aplicação.
 */
return function (Database $db): void {
    $db->query("ALTER TABLE pages DROP INDEX uniq_pages_slug");
    $db->query("CREATE INDEX idx_pages_slug ON pages (slug)");
};
