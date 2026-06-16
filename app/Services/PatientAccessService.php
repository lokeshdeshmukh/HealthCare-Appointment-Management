<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuthOtp;
use App\Models\Patient;
use App\Models\PatientIdentity;
use RuntimeException;

final class PatientAccessService
{
    public function __construct(
        private readonly Patient $patients = new Patient(),
        private readonly PatientIdentity $identities = new PatientIdentity(),
        private readonly AuthOtp $otps = new AuthOtp(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly SmsService $sms = new SmsService(),
        private readonly GoogleTokenService $google = new GoogleTokenService(),
        private readonly AnalyticsService $analytics = new AnalyticsService()
    ) {
    }

    public function sendLoginOtp(string $channel, array $data, ?array $clinic = null): array
    {
        $channel = $channel === 'mobile' ? 'mobile' : 'email';
        $destination = $channel === 'mobile'
            ? normalize_phone((string) ($data['phone'] ?? ''))
            : normalize_email((string) ($data['email'] ?? ''));

        if ($destination === '') {
            throw new RuntimeException($channel === 'mobile' ? 'Enter a valid mobile number.' : 'Enter a valid email address.');
        }

        $codeLength = max(4, (int) config('services.otp.length', 6));
        $otp = str_pad((string) random_int(0, (10 ** $codeLength) - 1), $codeLength, '0', STR_PAD_LEFT);
        $token = bin2hex(random_bytes(32));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . (int) config('services.otp.ttl_minutes', 10) . ' minutes'));

        $metadata = [
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'email' => normalize_email((string) ($data['email'] ?? '')),
            'phone' => normalize_phone((string) ($data['phone'] ?? '')),
            'phone_raw' => trim((string) ($data['phone'] ?? '')),
            'redirect_to' => (string) ($data['redirect_to'] ?? ''),
            'clinic_name' => $clinic['name'] ?? null,
        ];

        if ($channel === 'mobile') {
            $bridgePayload = $this->sms->buildBridgePayload($destination, $otp, (string) ($data['phone'] ?? ''));
            $metadata['sms_phone'] = $bridgePayload['phone'];
            $metadata['sms_message'] = $bridgePayload['message'];
        }

        $patient = $channel === 'mobile'
            ? $this->patients->findByPhone($destination)
            : $this->patients->findByEmail($destination);

        $this->otps->invalidatePending($channel, $destination, 'login');
        $otpId = $this->otps->insert([
            'clinic_id' => $clinic['id'] ?? null,
            'patient_id' => $patient['id'] ?? null,
            'super_admin_id' => null,
            'purpose' => 'login',
            'channel' => $channel,
            'challenge_token' => $token,
            'destination' => $destination,
            'otp_hash' => hash('sha256', $otp),
            'delivery_status' => 'pending',
            'delivery_error' => null,
            'expires_at' => $expiresAt,
            'verified_at' => null,
            'consumed_at' => null,
            'attempts' => 0,
            'max_attempts' => (int) config('services.otp.max_attempts', 5),
            'last_sent_at' => $now,
            'meta_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        if ($channel === 'email') {
            $sent = $this->notifications->sendPatientLoginOtp($destination, $otp, $clinic);
            $this->otps->markDelivery($otpId, $sent ? 'sent' : 'failed', $sent ? null : 'Unable to send OTP email.');
            if (!$sent) {
                throw new RuntimeException('Unable to send the OTP email right now. Please try again in a moment.');
            }
        } else {
            $result = $this->sms->sendOtp($destination, $otp, (string) ($data['phone'] ?? ''));
            $deliveryStatus = (string) ($result['delivery_status'] ?? ($result['success'] ? 'sent' : 'failed'));
            $this->otps->markDelivery($otpId, $deliveryStatus, $result['success'] ? null : $result['message']);

            if (!$result['success']) {
                throw new RuntimeException($result['message']);
            }
        }

        return [
            'challenge_token' => $token,
            'channel' => $channel,
            'masked_destination' => $this->maskDestination($channel, $destination),
            'expires_at' => $expiresAt,
        ];
    }

    public function challengeForDisplay(string $token): ?array
    {
        $challenge = $this->otps->findActiveChallenge($token);
        if (!$challenge || (string) $challenge['purpose'] !== 'login') {
            return null;
        }

        if (!empty($challenge['consumed_at']) || strtotime((string) $challenge['expires_at']) < time()) {
            return null;
        }

        return [
            'challenge_token' => $challenge['challenge_token'],
            'channel' => $challenge['channel'],
            'masked_destination' => $this->maskDestination((string) $challenge['channel'], (string) $challenge['destination']),
            'expires_at' => $challenge['expires_at'],
        ];
    }

    public function verifyLoginOtp(string $challengeToken, string $otp): array
    {
        $challenge = $this->otps->findActiveChallenge($challengeToken);
        if (!$challenge || (string) $challenge['purpose'] !== 'login') {
            throw new RuntimeException('This login code is invalid or has already been used.');
        }

        if (!empty($challenge['consumed_at'])) {
            throw new RuntimeException('This login code has already been used.');
        }

        if (strtotime((string) $challenge['expires_at']) < time()) {
            $this->otps->markDelivery((int) $challenge['id'], 'expired', 'OTP expired.');
            throw new RuntimeException('This login code has expired. Please request a new one.');
        }

        if ((int) $challenge['attempts'] >= (int) $challenge['max_attempts']) {
            throw new RuntimeException('Too many invalid attempts. Please request a fresh OTP.');
        }

        if (!hash_equals((string) $challenge['otp_hash'], hash('sha256', trim($otp)))) {
            $this->otps->incrementAttempts((int) $challenge['id'], (int) $challenge['attempts'] + 1);
            throw new RuntimeException('The OTP you entered is incorrect.');
        }

        $meta = json_decode((string) ($challenge['meta_json'] ?? '{}'), true);
        $meta = is_array($meta) ? $meta : [];

        $patient = $this->resolvePatientFromChallenge($challenge, $meta);
        $this->otps->markVerified((int) $challenge['id'], (int) $patient['id']);
        $this->patients->touchLogin((int) $patient['id']);

        return $this->patients->findActiveById((int) $patient['id']) ?? $patient;
    }

    public function loginWithGoogle(string $credential): array
    {
        $payload = $this->google->verify($credential);
        $googleSub = trim((string) ($payload['sub'] ?? ''));
        $email = normalize_email((string) ($payload['email'] ?? ''));

        if ($googleSub === '' || $email === '') {
            throw new RuntimeException('Google did not return a valid identity payload.');
        }

        $identity = $this->identities->findByProviderUserId('google', $googleSub)
            ?? $this->identities->findByProviderEmail('google', $email);

        if ($identity) {
            $patientId = (int) $identity['patient_id'];
            $patient = $this->patients->findActiveById($patientId);
            if (!$patient) {
                throw new RuntimeException('The patient account linked to this Google login no longer exists.');
            }

            $this->patients->touchLogin($patientId);
            $this->syncGoogleIdentity($patientId, $payload, $identity);

            return $this->patients->findActiveById($patientId) ?? $patient;
        }

        $patient = $this->patients->findByEmail($email);
        if (!$patient) {
            $patient = $this->createGooglePatient($payload);
        } else {
            $patient = $this->updatePatientFromGoogle($patient, $payload);
        }

        $this->syncGoogleIdentity((int) $patient['id'], $payload, null);
        $this->patients->touchLogin((int) $patient['id']);

        return $this->patients->findActiveById((int) $patient['id']) ?? $patient;
    }

    private function resolvePatientFromChallenge(array $challenge, array $meta): array
    {
        $patient = null;
        $destination = (string) $challenge['destination'];
        $channel = (string) $challenge['channel'];

        if (!empty($challenge['patient_id'])) {
            $patient = $this->patients->findActiveById((int) $challenge['patient_id']);
        }

        if (!$patient) {
            $patient = $channel === 'mobile'
                ? $this->patients->findByPhone($destination)
                : $this->patients->findByEmail($destination);
        }

        if ($patient) {
            $this->enrichPatientFromMeta($patient, $meta, $channel, $destination);

            return $this->patients->findActiveById((int) $patient['id']) ?? $patient;
        }

        return $this->createPatientFromChallenge($challenge, $meta);
    }

    private function createPatientFromChallenge(array $challenge, array $meta): array
    {
        $channel = (string) $challenge['channel'];
        $destination = (string) $challenge['destination'];
        $name = split_name((string) ($meta['full_name'] ?? ''), $channel === 'email' ? 'Patient' : 'Mobile Patient');
        $email = $channel === 'email' ? $destination : normalize_email((string) ($meta['email'] ?? ''));
        $phone = $channel === 'mobile' ? $destination : normalize_phone((string) ($meta['phone'] ?? ''));
        $now = date('Y-m-d H:i:s');

        if ($email !== '') {
            $existingByEmail = $this->patients->findByEmail($email);
            if ($existingByEmail) {
                $email = '';
            }
        }

        if ($phone !== '') {
            $existingByPhone = $this->patients->findByPhone($phone);
            if ($existingByPhone) {
                $phone = '';
            }
        }

        $patientId = $this->patients->insert([
            'first_name' => $name['first_name'],
            'last_name' => $name['last_name'],
            'email' => $email !== '' ? $email : null,
            'email_verified_at' => $channel === 'email' && $email !== '' ? $now : null,
            'phone' => $phone !== '' ? $phone : null,
            'phone_verified_at' => $channel === 'mobile' && $phone !== '' ? $now : null,
            'password_hash' => null,
            'date_of_birth' => null,
            'gender' => null,
            'reset_token' => null,
            'reset_token_expires_at' => null,
            'last_login_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $patient = $this->patients->findActiveById($patientId);
        if ($patient && !empty($patient['email'])) {
            $this->notifications->sendPatientWelcome($patient);
        }

        $this->analytics->track('patient_registered', [
            'patient_id' => $patientId,
            'event_date' => date('Y-m-d'),
            'metadata' => ['channel' => $channel],
        ]);

        return $patient ?? [];
    }

    private function enrichPatientFromMeta(array $patient, array $meta, string $channel, string $destination): void
    {
        $updates = [];

        if ($channel === 'email' && empty($patient['email_verified_at']) && !empty($patient['email'])) {
            $updates['email_verified_at'] = date('Y-m-d H:i:s');
        }

        if ($channel === 'mobile' && empty($patient['phone_verified_at']) && !empty($patient['phone'])) {
            $updates['phone_verified_at'] = date('Y-m-d H:i:s');
        }

        if (empty($patient['phone']) && !empty($meta['phone'])) {
            $existingByPhone = $this->patients->findByPhone((string) $meta['phone']);
            if (!$existingByPhone || (int) $existingByPhone['id'] === (int) $patient['id']) {
                $updates['phone'] = normalize_phone((string) $meta['phone']);
            }
        }

        if (empty($patient['email']) && !empty($meta['email'])) {
            $existingByEmail = $this->patients->findByEmail((string) $meta['email']);
            if (!$existingByEmail || (int) $existingByEmail['id'] === (int) $patient['id']) {
                $updates['email'] = normalize_email((string) $meta['email']);
                if ($channel === 'email') {
                    $updates['email_verified_at'] = date('Y-m-d H:i:s');
                }
            }
        }

        if (empty($patient['first_name']) || in_array((string) $patient['first_name'], ['Patient', 'Mobile'], true)) {
            $name = split_name((string) ($meta['full_name'] ?? ''), $channel === 'email' ? 'Patient' : 'Mobile Patient');
            $updates['first_name'] = $name['first_name'];
            $updates['last_name'] = $name['last_name'];
        }

        if ($channel === 'email' && empty($patient['email'])) {
            $updates['email'] = $destination;
            $updates['email_verified_at'] = date('Y-m-d H:i:s');
        }

        if ($channel === 'mobile' && empty($patient['phone'])) {
            $updates['phone'] = $destination;
            $updates['phone_verified_at'] = date('Y-m-d H:i:s');
        }

        if ($updates !== []) {
            $updates['updated_at'] = date('Y-m-d H:i:s');
            $this->patients->updateById((int) $patient['id'], $updates);
        }
    }

    private function createGooglePatient(array $payload): array
    {
        $name = split_name((string) ($payload['name'] ?? $payload['given_name'] ?? 'Google Patient'), 'Google Patient');
        $now = date('Y-m-d H:i:s');
        $patientId = $this->patients->insert([
            'first_name' => $name['first_name'],
            'last_name' => $name['last_name'],
            'email' => normalize_email((string) ($payload['email'] ?? '')),
            'email_verified_at' => $now,
            'phone' => null,
            'phone_verified_at' => null,
            'password_hash' => null,
            'date_of_birth' => null,
            'gender' => null,
            'reset_token' => null,
            'reset_token_expires_at' => null,
            'last_login_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $patient = $this->patients->findActiveById($patientId);
        if ($patient) {
            $this->notifications->sendPatientWelcome($patient);
            $this->analytics->track('patient_registered', [
                'patient_id' => $patientId,
                'event_date' => date('Y-m-d'),
                'metadata' => ['channel' => 'google'],
            ]);
        }

        return $patient ?? [];
    }

    private function updatePatientFromGoogle(array $patient, array $payload): array
    {
        $updates = [
            'email_verified_at' => $patient['email_verified_at'] ?: date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if (empty($patient['first_name']) || empty($patient['last_name'])) {
            $name = split_name((string) ($payload['name'] ?? $payload['given_name'] ?? 'Google Patient'), 'Google Patient');
            $updates['first_name'] = $name['first_name'];
            $updates['last_name'] = $name['last_name'];
        }

        $this->patients->updateById((int) $patient['id'], $updates);

        return $this->patients->findActiveById((int) $patient['id']) ?? $patient;
    }

    private function syncGoogleIdentity(int $patientId, array $payload, ?array $identity): void
    {
        $data = [
            'patient_id' => $patientId,
            'provider' => 'google',
            'provider_user_id' => (string) $payload['sub'],
            'provider_email' => normalize_email((string) ($payload['email'] ?? '')),
            'provider_name' => trim((string) ($payload['name'] ?? '')) ?: null,
            'avatar_url' => trim((string) ($payload['picture'] ?? '')) ?: null,
            'metadata_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($identity) {
            $this->identities->updateById((int) $identity['id'], $data);
            return;
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $data['deleted_at'] = null;
        $this->identities->insert($data);
    }

    private function maskDestination(string $channel, string $destination): string
    {
        if ($channel === 'email') {
            [$name, $domain] = array_pad(explode('@', $destination, 2), 2, '');
            if ($domain === '') {
                return $destination;
            }

            $visible = substr($name, 0, 2);
            return $visible . str_repeat('*', max(1, strlen($name) - 2)) . '@' . $domain;
        }

        $length = strlen($destination);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($destination, -4);
    }
}
