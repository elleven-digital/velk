<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

final class FormRecipient
{
    public int $id;
    public string $formId;
    public string $email;
    public ?string $name = null;
    public string $createdAt;

    public static function fromRow(array $row): self
    {
        $r = new self();
        $r->id = (int) $row['id'];
        $r->formId = (string) $row['form_id'];
        $r->email = (string) $row['email'];
        $r->name = $row['name'] ?? null;
        $r->createdAt = (string) $row['created_at'];
        return $r;
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM form_recipients WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    /**
     * @return list<self>
     */
    public static function forForm(string $formId): array
    {
        $rows = App::instance()->db->fetchAll(
            'SELECT * FROM form_recipients WHERE form_id = ? ORDER BY created_at ASC',
            [$formId]
        );
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function create(string $formId, string $email, ?string $name = null): self
    {
        $email = strtolower(trim($email));
        $id = App::instance()->db->insert('form_recipients', [
            'form_id' => $formId,
            'email'   => $email,
            'name'    => $name === '' ? null : $name,
        ]);
        return self::find($id) ?? throw new \RuntimeException('Failed to create form recipient.');
    }

    public function delete(): void
    {
        App::instance()->db->delete('form_recipients', ['id' => $this->id]);
    }

    /**
     * Format as "Name <email>" or just "<email>".
     */
    public function asAddress(): string
    {
        return $this->name !== null && $this->name !== ''
            ? sprintf('%s <%s>', $this->name, $this->email)
            : $this->email;
    }
}
