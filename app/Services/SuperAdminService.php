<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Models\SuperAdmin;
use RuntimeException;

final class SuperAdminService
{
    private const DEFAULT_ADMIN_NAME = 'Huviena Platform Admin';
    private const DEFAULT_ADMIN_USERNAME = 'admin';
    private const DEFAULT_ADMIN_EMAIL = 'admin@huviena.local';
    private const DEFAULT_ADMIN_PASSWORD_HASH = 'pbkdf2_sha256$210000$omSIygVwZ+h3EG/PYUnYUA==$u3sEI1Qva8Js/Py0HxQB5KRzL8WYzljRk6f0xGL3+XE=';

    public function __construct(private readonly SuperAdmin $admins = new SuperAdmin())
    {
    }

    public function hasAnyAdmin(): bool
    {
        return $this->admins->countActive() > 0;
    }

    public function createFirstAdmin(array $data): array
    {
        if ($this->hasAnyAdmin()) {
            throw new RuntimeException('Platform admin setup has already been completed.');
        }

        $email = normalize_email((string) ($data['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Enter a valid platform admin email address.');
        }

        if (strlen((string) ($data['password'] ?? '')) < 8) {
            throw new RuntimeException('Platform admin password must be at least 8 characters.');
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'name' => trim((string) ($data['name'] ?? 'Platform Admin')),
            'email' => $email,
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        if ($this->admins->hasUsernameColumn()) {
            $payload['username'] = $this->normalizeUsername((string) ($data['username'] ?? $email));
        }

        $adminId = $this->admins->insert($payload);

        return $this->admins->findActiveById($adminId) ?? [];
    }

    public function ensureDefaultAdmin(): void
    {
        $now = date('Y-m-d H:i:s');
        $hasUsernameColumn = $this->admins->hasUsernameColumn();
        $admin = $this->admins->findByUsername(self::DEFAULT_ADMIN_USERNAME);

        if ($admin) {
            $payload = [
                'name' => self::DEFAULT_ADMIN_NAME,
                'email' => self::DEFAULT_ADMIN_EMAIL,
                'password_hash' => self::DEFAULT_ADMIN_PASSWORD_HASH,
                'status' => 'active',
                'deleted_at' => null,
                'updated_at' => $now,
            ];
            if ($hasUsernameColumn) {
                $payload['username'] = self::DEFAULT_ADMIN_USERNAME;
            }
            $this->admins->updateById((int) $admin['id'], $payload);
            return;
        }

        $admin = $this->admins->findByEmail(self::DEFAULT_ADMIN_EMAIL);
        if ($admin) {
            $payload = [
                'name' => self::DEFAULT_ADMIN_NAME,
                'email' => self::DEFAULT_ADMIN_EMAIL,
                'password_hash' => self::DEFAULT_ADMIN_PASSWORD_HASH,
                'status' => 'active',
                'deleted_at' => null,
                'updated_at' => $now,
            ];
            if ($hasUsernameColumn) {
                $payload['username'] = self::DEFAULT_ADMIN_USERNAME;
            }
            $this->admins->updateById((int) $admin['id'], $payload);
            return;
        }

        $payload = [
            'name' => self::DEFAULT_ADMIN_NAME,
            'email' => self::DEFAULT_ADMIN_EMAIL,
            'password_hash' => self::DEFAULT_ADMIN_PASSWORD_HASH,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
        if ($hasUsernameColumn) {
            $payload['username'] = self::DEFAULT_ADMIN_USERNAME;
        }

        $this->admins->insert($payload);
    }

    public function attemptLogin(string $identifier, string $password): bool
    {
        $this->ensureDefaultAdmin();

        $admin = $this->admins->findByUsernameOrEmail($identifier);
        if (!$admin || ($admin['status'] ?? '') !== 'active' || !$this->passwordMatches($password, (string) $admin['password_hash'])) {
            return false;
        }

        Auth::login('super_admin', (int) $admin['id']);
        $this->admins->updateById((int) $admin['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    private function passwordMatches(string $plain, string $stored): bool
    {
        if (str_starts_with($stored, 'pbkdf2_sha256$')) {
            $parts = explode('$', $stored);
            if (count($parts) !== 4) {
                return false;
            }

            $iterations = (int) $parts[1];
            $salt = base64_decode($parts[2], true);
            $expected = base64_decode($parts[3], true);

            if ($iterations < 1 || $salt === false || $expected === false) {
                return false;
            }

            $computed = hash_pbkdf2('sha256', $plain, $salt, $iterations, strlen($expected), true);
            return hash_equals($expected, $computed);
        }

        return password_verify($plain, $stored);
    }

    private function normalizeUsername(string $value): string
    {
        $candidate = strtolower(trim($value));
        $candidate = preg_replace('/[^a-z0-9._-]+/', '-', $candidate) ?? 'admin';
        $candidate = trim($candidate, '-._');

        return $candidate !== '' ? $candidate : 'admin';
    }
}
