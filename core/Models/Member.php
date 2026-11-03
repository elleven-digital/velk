<?php

declare(strict_types=1);

namespace Velk\Models;

use Velk\App;

/**
 * Site member — a user of the public site, not of the admin panel.
 *
 * Members are configured in `site.json → members`. Their `type` is one
 * of the keys declared in `members.types` (NULL when the site doesn't
 * use types). Custom fields live in the `fields` JSON column and follow
 * the schema from `site.json → members.fields` (global) merged with
 * `site.json → members.types.<type>.fields` (per-type) — same pattern
 * as item fields.
 */
final class Member
{
    public int $id;
    public string $email;
    public ?string $name = null;
    public ?string $type = null;
    public array $fields = [];
    public bool $twoFactorEnabled = false;
    public ?string $emailVerifiedAt = null;
    public ?string $lastLoginAt = null;
    public string $createdAt;
    public ?string $updatedAt = null;
    public ?string $stripeCustomerId = null;

    public static function fromRow(array $row): self
    {
        $m = new self();
        $m->id = (int) $row['id'];
        $m->email = (string) $row['email'];
        $m->name = $row['name'] !== null ? (string) $row['name'] : null;
        $m->type = $row['type'] !== null ? (string) $row['type'] : null;
        $m->fields = is_string($row['fields'] ?? null)
            ? (array) (json_decode($row['fields'], true) ?? [])
            : [];
        // Null-coalesce keeps fromRow working against pre-migration rows.
        $m->twoFactorEnabled = (int) ($row['two_factor_enabled'] ?? 0) === 1;
        $m->emailVerifiedAt = $row['email_verified_at'] !== null ? (string) $row['email_verified_at'] : null;
        $m->lastLoginAt = $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null;
        $m->createdAt = (string) $row['created_at'];
        $m->updatedAt = $row['updated_at'] ?? null;
        $m->stripeCustomerId = isset($row['stripe_customer_id']) && $row['stripe_customer_id'] !== null
            ? (string) $row['stripe_customer_id']
            : null;
        return $m;
    }

    public static function find(int $id): ?self
    {
        $row = App::instance()->db->fetch('SELECT * FROM members WHERE id = ? LIMIT 1', [$id]);
        return $row === null ? null : self::fromRow($row);
    }

    public static function findByEmail(string $email): ?self
    {
        $row = App::instance()->db->fetch(
            'SELECT * FROM members WHERE email = ? LIMIT 1',
            [strtolower(trim($email))]
        );
        return $row === null ? null : self::fromRow($row);
    }

    /** @return list<self> */
    public static function all(int $limit = 100, int $offset = 0, ?string $type = null): array
    {
        $db = App::instance()->db;
        if ($type !== null) {
            $rows = $db->fetchAll(
                'SELECT * FROM members WHERE type = ? ORDER BY created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
                [$type]
            );
        } else {
            $rows = $db->fetchAll(
                'SELECT * FROM members ORDER BY created_at DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset
            );
        }
        return array_map(fn($r) => self::fromRow($r), $rows);
    }

    public static function count(?string $type = null): int
    {
        $db = App::instance()->db;
        if ($type !== null) {
            return (int) $db->fetchColumn('SELECT COUNT(*) FROM members WHERE type = ?', [$type]);
        }
        return (int) $db->fetchColumn('SELECT COUNT(*) FROM members');
    }

    /**
     * Create a new member with a plaintext password. Email is normalized
     * to lowercase + trimmed; password is bcrypt-hashed before storage.
     * Throws on duplicate email (relies on the UNIQUE index).
     */
    public static function create(string $email, string $password, ?string $name = null, ?string $type = null, array $fields = []): self
    {
        $email = strtolower(trim($email));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $id = App::instance()->db->insert('members', [
            'email' => $email,
            'password_hash' => $hash,
            'name' => $name,
            'type' => $type,
            // JSON_INVALID_UTF8_SUBSTITUTE: public registration puts POST data
            // straight in here, and a single invalid byte would make
            // json_encode return false, which MySQL rejects on a JSON column —
            // surfacing as a 500 instead of an account. Same reasoning as
            // FormSubmission::create.
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
        return self::find($id) ?? throw new \RuntimeException('Failed to create member.');
    }

    /**
     * Partial update. Pass only the keys you want changed. Email is
     * re-normalized; fields is JSON-encoded; password is NEVER updated
     * here — use setPassword() so the hashing is explicit at the call site.
     */
    public function save(array $data): void
    {
        $update = [];
        if (array_key_exists('name', $data))   $update['name']   = $data['name'];
        if (array_key_exists('email', $data))  $update['email']  = strtolower(trim((string) $data['email']));
        if (array_key_exists('type', $data))   $update['type']   = $data['type'];
        if (array_key_exists('fields', $data)) $update['fields'] = json_encode((array) $data['fields'], JSON_UNESCAPED_UNICODE);
        if (array_key_exists('two_factor_enabled', $data)) {
            $update['two_factor_enabled'] = !empty($data['two_factor_enabled']) ? 1 : 0;
        }
        if (array_key_exists('stripe_customer_id', $data)) {
            $update['stripe_customer_id'] = $data['stripe_customer_id'] !== null
                ? (string) $data['stripe_customer_id']
                : null;
        }
        if ($update !== []) {
            App::instance()->db->update('members', $update, ['id' => $this->id]);
        }
    }

    public function setPassword(string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        App::instance()->db->update('members', ['password_hash' => $hash], ['id' => $this->id]);
    }

    /** Bumps last_login_at — called by MemberAuth on successful login. */
    public function touchLogin(): void
    {
        App::instance()->db->update(
            'members',
            ['last_login_at' => date('Y-m-d H:i:s')],
            ['id' => $this->id]
        );
    }

    public function markEmailVerified(): void
    {
        App::instance()->db->update(
            'members',
            ['email_verified_at' => date('Y-m-d H:i:s')],
            ['id' => $this->id]
        );
    }

    public function delete(): void
    {
        App::instance()->db->delete('members', ['id' => $this->id]);
    }

    public function field(string $name, mixed $default = null): mixed
    {
        return $this->fields[$name] ?? $default;
    }

    /** Convenience for theme code that wants role-style checks. */
    public function isType(string $type): bool
    {
        return $this->type === $type;
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerifiedAt !== null;
    }
}
