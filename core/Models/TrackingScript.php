<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * Um script de rastreamento gerenciado em /admin/scripts.
 *
 * Substitui os três campos de texto do grupo de opções `tracking`. A troca
 * aconteceu porque consentimento é decisão POR SCRIPT: três textareas
 * conseguem carregar uma decisão cada, e o caso real que quebra isso é banal
 * — GTM e um monitor de erro, os dois no `<head>`, com consentimentos
 * diferentes.
 *
 * `category` é o vínculo com `consent.categories` do site.json, por string e
 * sem FK — categorias vivem no tema, não em tabela (mesma razão de
 * `form_settings.form_id`). String vazia é o padrão e significa "necessário,
 * sempre carrega": é o que um script recebe quando ninguém marcou nada, e o
 * único valor possível num site que não declara categorias.
 *
 * Esse padrão é deliberado. Script recém-criado precisa FUNCIONAR; um padrão
 * que bloqueasse faria o admin colar o GTM, salvar, e não ver nada acontecer,
 * sem erro em lugar nenhum.
 */
final class TrackingScript
{
    public int $id;
    public string $label;
    public string $code;
    public string $location;
    public string $category;
    public bool $enabled;
    public int $sortOrder;
    public string $createdAt;

    /** Posições válidas, na ordem em que aparecem na página. */
    public const LOCATIONS = ['head', 'body_start', 'body_end'];

    public static function fromRow(array $row): self
    {
        $s = new self();
        $s->id = (int) $row['id'];
        $s->label = (string) $row['label'];
        $s->code = (string) $row['code'];
        $s->location = (string) $row['location'];
        $s->category = (string) $row['category'];
        $s->enabled = (bool) $row['enabled'];
        $s->sortOrder = (int) $row['sort_order'];
        $s->createdAt = (string) $row['created_at'];
        return $s;
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM tracking_scripts WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * Todos, na ordem de exibição da tela.
     *
     * Ordena por posição primeiro (na ordem em que a página as emite, não
     * alfabética — `body_end` viria antes de `head`) e depois por
     * `sort_order`, que é o que o arrastar controla.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        $rows = App::instance()->db->fetchAll(
            "SELECT * FROM tracking_scripts
             ORDER BY FIELD(location, 'head', 'body_start', 'body_end'), sort_order ASC, id ASC"
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    /**
     * Os habilitados de UMA posição, prontos para o gate de consentimento.
     *
     * Devolve arrays e não objetos porque é isso que `Consent::renderScripts()`
     * consome — a mesma forma que as linhas do repetidor tinham. Manter o
     * formato deixa o gate independente de onde os scripts moram, e foi o que
     * permitiu trocar options por tabela sem tocar em `Consent`.
     *
     * @return list<array<string,mixed>>
     */
    public static function forOutput(string $location): array
    {
        $rows = App::instance()->db->fetchAll(
            'SELECT label, code, location, category FROM tracking_scripts
             WHERE location = ? AND enabled = 1
             ORDER BY sort_order ASC, id ASC',
            [$location]
        );
        return $rows;
    }

    /**
     * Existe ao menos um script habilitado sob consentimento?
     *
     * É o que decide se o runtime `velkConsent` entra na página. Sem esta
     * checagem todo site Velk carregaria o runtime, porque as categorias
     * efetivas nunca são vazias (as implícitas existem sempre) — 3,5 KB de
     * JS inline para gatear coisa nenhuma.
     *
     * Uma consulta por request, na mesma tabela que a emissão já lê.
     */
    public static function hasConsentGated(): bool
    {
        return (int) App::instance()->db->fetchColumn(
            "SELECT COUNT(*) FROM tracking_scripts WHERE enabled = 1 AND category <> ''"
        ) > 0;
    }

    public static function create(array $data): self
    {
        $location = self::normalizeLocation($data['location'] ?? null);
        $id = App::instance()->db->insert('tracking_scripts', [
            'label' => (string) ($data['label'] ?? ''),
            'code' => (string) ($data['code'] ?? ''),
            'location' => $location,
            'category' => (string) ($data['category'] ?? ''),
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            // Novo entra no fim da sua posição: ordem de criação é a
            // expectativa até alguém arrastar.
            'sort_order' => self::nextSortOrder($location),
        ]);
        return self::find($id) ?? throw new \RuntimeException('Failed to create tracking script.');
    }

    public function save(array $data): void
    {
        $update = [];
        if (array_key_exists('label', $data))    $update['label']    = (string) $data['label'];
        if (array_key_exists('code', $data))     $update['code']     = (string) $data['code'];
        if (array_key_exists('location', $data)) $update['location'] = self::normalizeLocation($data['location']);
        if (array_key_exists('category', $data)) $update['category'] = (string) $data['category'];
        if (array_key_exists('enabled', $data))  $update['enabled']  = !empty($data['enabled']) ? 1 : 0;
        if ($update !== []) {
            App::instance()->db->update('tracking_scripts', $update, ['id' => $this->id]);
        }
    }

    public function delete(): void
    {
        App::instance()->db->delete('tracking_scripts', ['id' => $this->id]);
    }

    /**
     * Aplica uma nova ordem a partir de uma lista de ids.
     *
     * Ids desconhecidos são ignorados e ids ausentes ficam onde estavam — a
     * chamada vem do arrastar no navegador, e uma lista desatualizada (outra
     * aba apagou uma linha) não pode virar erro nem reordenar o que não foi
     * pedido.
     *
     * @param list<int> $ids
     */
    public static function applyOrder(array $ids): void
    {
        $db = App::instance()->db;
        $position = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $db->update('tracking_scripts', ['sort_order' => $position], ['id' => $id]);
            $position++;
        }
    }

    private static function normalizeLocation(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        return in_array($value, self::LOCATIONS, true) ? $value : 'head';
    }

    private static function nextSortOrder(string $location): int
    {
        $max = App::instance()->db->fetchColumn(
            'SELECT MAX(sort_order) FROM tracking_scripts WHERE location = ?',
            [$location]
        );
        return $max === null ? 0 : ((int) $max) + 1;
    }
}
