<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class SuperAdmin extends Model
{
    protected string $table = 'super_admins';
    private ?bool $hasUsernameColumn = null;

    public function findByUsername(string $username): ?array
    {
        if (!$this->hasUsernameColumn()) {
            return null;
        }

        $statement = $this->db->prepare('SELECT * FROM super_admins WHERE username = :username AND deleted_at IS NULL LIMIT 1');
        $statement->execute(['username' => strtolower(trim($username))]);

        return $statement->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM super_admins WHERE email = :email AND deleted_at IS NULL LIMIT 1');
        $statement->execute(['email' => normalize_email($email)]);

        return $statement->fetch() ?: null;
    }

    public function findByUsernameOrEmail(string $identifier): ?array
    {
        $normalized = strtolower(trim($identifier));
        if (!$this->hasUsernameColumn()) {
            $email = $normalized === 'admin' ? 'admin@huviena.local' : normalize_email($identifier);
            return $this->findByEmail($email);
        }

        $statement = $this->db->prepare('SELECT * FROM super_admins WHERE (username = :username OR email = :email) AND deleted_at IS NULL LIMIT 1');
        $statement->execute([
            'username' => $normalized,
            'email' => normalize_email($identifier),
        ]);

        return $statement->fetch() ?: null;
    }

    public function countActive(): int
    {
        $statement = $this->db->query('SELECT COUNT(*) FROM super_admins WHERE deleted_at IS NULL');

        return (int) $statement->fetchColumn();
    }

    public function allActive(): array
    {
        return $this->db->query('SELECT * FROM super_admins WHERE deleted_at IS NULL ORDER BY created_at ASC')->fetchAll();
    }

    public function hasUsernameColumn(): bool
    {
        if ($this->hasUsernameColumn !== null) {
            return $this->hasUsernameColumn;
        }

        $statement = $this->db->query("SHOW COLUMNS FROM super_admins LIKE 'username'");
        $this->hasUsernameColumn = $statement !== false && $statement->fetch() !== false;

        return $this->hasUsernameColumn;
    }
}
