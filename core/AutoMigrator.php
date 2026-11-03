<?php

declare(strict_types=1);

namespace Velk;

/**
 * Aplica migrations PENDENTES no boot web (sites já instalados), pra permitir
 * upgrade em hosts sem shell. Serializa com um lock nativo do MySQL e, em
 * falha, trava com um flag (sem re-tentar em loop). O Migrator fica intacto.
 */
final class AutoMigrator
{
    private const LOCK_NAME = 'velk_migrate';

    public function __construct(
        private Database $db,
        private Migrator $migrator,
        private string $storagePath,
        private bool $enabled
    ) {}

    /** Caminho do flag de falha (estado que trava o auto-migrate). */
    public static function flagFile(string $storagePath): string
    {
        return rtrim($storagePath, '/') . '/cache/migration-error.json';
    }

    /** Apaga o flag (chamado no migrate bem-sucedido — web ou CLI). */
    public static function clearFlag(string $storagePath): void
    {
        $file = self::flagFile($storagePath);
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Decisão pura do que fazer no boot web:
     *   'failed'  — travado por falha anterior (mostra página genérica)
     *   'migrate' — há pendentes e nada travando → aplicar
     *   'skip'    — seguir o boot normal
     */
    public static function decide(bool $enabled, bool $hasFailureFlag, bool $hasPending): string
    {
        if (!$enabled) {
            return 'skip';
        }
        if ($hasFailureFlag) {
            return 'failed';
        }
        return $hasPending ? 'migrate' : 'skip';
    }

    /**
     * Entrada do boot web. Lança MaintenanceException pra renderizar a página
     * "atualizando" (lock ocupado) ou "falhou" (flag/erro). Retorna normal
     * quando não há nada a fazer ou quando aplicou as pendentes com sucesso.
     */
    public function run(): void
    {
        $hasFlag = is_file(self::flagFile($this->storagePath));
        // Só consulta pending() quando importa pra decisão — desligado ou travado
        // por falha não precisa tocar o banco.
        $hasPending = (!$this->enabled || $hasFlag) ? false : $this->migrator->pending() !== [];
        $action = self::decide($this->enabled, $hasFlag, $hasPending);

        if ($action === 'skip') {
            return;
        }
        if ($action === 'failed') {
            throw new MaintenanceException('failed');
        }

        // 'migrate' — serializar com lock nativo do MySQL (não-bloqueante).
        $got = (int) $this->db->fetchColumn('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]);
        if ($got !== 1) {
            throw new MaintenanceException('updating'); // outra requisição está migrando
        }

        try {
            $this->migrator->migrate();
            self::clearFlag($this->storagePath); // sucesso → garante sem flag velho
        } catch (\Throwable $e) {
            // migrate() aplica em ordem e para na falha; a 1ª ainda-pendente é a culpada.
            // Defensivo: se a conexão caiu, pending() pode lançar — não deixar isso
            // mascarar o erro original nem pular o flag/página.
            try {
                $pending = $this->migrator->pending();
                $failing = $pending !== [] ? basename((string) $pending[0]) : '(desconhecida)';
            } catch (\Throwable) {
                $failing = '(desconhecida)';
            }
            $this->writeFailureFlag($failing, $e->getMessage());
            throw new MaintenanceException('failed', $e);
        } finally {
            $this->db->query('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }

    /** Grava o flag + um log — detalhe pro operador, NUNCA mostrado ao público. */
    private function writeFailureFlag(string $migration, string $error): void
    {
        $at = gmdate('c');
        @file_put_contents(
            self::flagFile($this->storagePath),
            (string) json_encode(
                ['migration' => $migration, 'error' => $error, 'failed_at' => $at],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
        @file_put_contents(
            rtrim($this->storagePath, '/') . '/logs/migration.log',
            '[' . $at . "] migration {$migration} falhou: {$error}\n",
            FILE_APPEND
        );
    }
}
