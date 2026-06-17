<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Model;

final class AuthOtp extends Model
{
    protected string $table = 'auth_otps';

    public function expirePendingMobileChallenges(): void
    {
        $statement = $this->db->prepare('UPDATE auth_otps
            SET delivery_status = "expired",
                consumed_at = COALESCE(consumed_at, :consumed_at),
                updated_at = :updated_at
            WHERE channel = "mobile"
              AND delivery_status = "pending"
              AND verified_at IS NULL
              AND deleted_at IS NULL
              AND expires_at < :expired_before');
        $now = date('Y-m-d H:i:s');
        $statement->execute([
            'consumed_at' => $now,
            'updated_at' => $now,
            'expired_before' => $now,
        ]);
    }

    public function findActiveChallenge(string $token): ?array
    {
        $sql = 'SELECT * FROM auth_otps
                WHERE challenge_token = :challenge_token
                  AND deleted_at IS NULL
                LIMIT 1';
        $statement = $this->db->prepare($sql);
        $statement->execute(['challenge_token' => $token]);

        return $statement->fetch() ?: null;
    }

    public function invalidatePending(string $channel, string $destination, string $purpose = 'login'): void
    {
        $sql = 'UPDATE auth_otps
                SET consumed_at = :consumed_at,
                    delivery_status = "expired",
                    updated_at = :updated_at
                WHERE channel = :channel
                  AND destination = :destination
                  AND purpose = :purpose
                  AND consumed_at IS NULL
                  AND verified_at IS NULL
                  AND deleted_at IS NULL';
        $now = date('Y-m-d H:i:s');
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'channel' => $channel,
            'destination' => $destination,
            'purpose' => $purpose,
            'consumed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function incrementAttempts(int $id, int $attempts): void
    {
        $statement = $this->db->prepare('UPDATE auth_otps SET attempts = :attempts, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'attempts' => $attempts,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markDelivery(int $id, string $status, ?string $error = null): void
    {
        $statement = $this->db->prepare('UPDATE auth_otps SET delivery_status = :delivery_status, delivery_error = :delivery_error, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'delivery_status' => $status,
            'delivery_error' => $error,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markVerified(int $id, ?int $patientId = null, ?int $superAdminId = null): void
    {
        $now = date('Y-m-d H:i:s');
        $statement = $this->db->prepare('UPDATE auth_otps
            SET patient_id = :patient_id,
                super_admin_id = :super_admin_id,
                delivery_status = "verified",
                verified_at = :verified_at,
                consumed_at = :consumed_at,
                updated_at = :updated_at
            WHERE id = :id');
        $statement->execute([
            'id' => $id,
            'patient_id' => $patientId,
            'super_admin_id' => $superAdminId,
            'verified_at' => $now,
            'consumed_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function pendingSmsQueue(int $limit = 25): array
    {
        $this->expirePendingMobileChallenges();

        $statement = $this->db->prepare('SELECT id, destination, meta_json
            FROM auth_otps
            WHERE channel = "mobile"
              AND purpose = "login"
              AND delivery_status = "pending"
              AND verified_at IS NULL
              AND consumed_at IS NULL
              AND deleted_at IS NULL
              AND expires_at >= :now
            ORDER BY created_at ASC
            LIMIT ' . max(1, $limit));
        $statement->execute([
            'now' => date('Y-m-d H:i:s'),
        ]);

        $queue = [];
        foreach ($statement->fetchAll() as $row) {
            $meta = json_decode((string) ($row['meta_json'] ?? '{}'), true);
            $meta = is_array($meta) ? $meta : [];
            $message = trim((string) ($meta['sms_message'] ?? ''));
            $phone = trim((string) ($meta['sms_phone'] ?? $row['destination']));

            if ($message === '' || $phone === '') {
                continue;
            }

            $queue[] = [
                'id' => (string) $row['id'],
                'status' => 'pending',
                'phone' => $phone,
                'message' => $message,
            ];
        }

        return $queue;
    }

    public function acknowledgeSms(int $id, string $status, ?string $error = null): bool
    {
        $allowed = ['sent', 'failed'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $statement = $this->db->prepare('UPDATE auth_otps
            SET delivery_status = :delivery_status,
                delivery_error = :delivery_error,
                last_sent_at = CASE WHEN :delivery_status = "sent" THEN :last_sent_at ELSE last_sent_at END,
                updated_at = :updated_at
            WHERE id = :id
              AND channel = "mobile"
              AND delivery_status = "pending"
              AND verified_at IS NULL
              AND deleted_at IS NULL');

        return $statement->execute([
            'id' => $id,
            'delivery_status' => $status,
            'delivery_error' => $error,
            'last_sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]) && $statement->rowCount() > 0;
    }
}
