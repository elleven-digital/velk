<?php

declare(strict_types=1);

namespace Velk;

/**
 * Lançada no boot quando o site está aplicando migrations pendentes
 * (mode 'updating') ou quando uma migration falhou e o auto-migrate está
 * travado (mode 'failed'). O `index.php` captura e renderiza Maintenance.
 */
final class MaintenanceException extends \RuntimeException
{
    public function __construct(public readonly string $mode, ?\Throwable $previous = null)
    {
        parent::__construct('maintenance: ' . $mode, 0, $previous);
    }
}
