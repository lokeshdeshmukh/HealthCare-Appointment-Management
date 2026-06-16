<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Models\AuthOtp;
use App\Services\SmsService;

final class SmsBridgeApiController extends Controller
{
    public function __construct(
        private readonly AuthOtp $otps = new AuthOtp(),
        private readonly SmsService $sms = new SmsService()
    )
    {
    }

    public function pending(Request $request): never
    {
        $this->guardBridgeAccess($request);

        $this->json($this->otps->pendingSmsQueue($this->sms->bridgeBatchLimit()));
    }

    public function status(Request $request): never
    {
        $this->guardBridgeAccess($request);

        $id = (int) $request->input('id');
        $status = strtolower(trim((string) $request->input('status')));
        $error = trim((string) $request->input('error', '')) ?: null;

        if ($id < 1 || !in_array($status, ['sent', 'failed'], true)) {
            $this->json([
                'ok' => false,
                'message' => 'Valid id and status are required.',
            ], 422);
        }

        $updated = $this->otps->acknowledgeSms($id, $status, $error);
        if (!$updated) {
            $this->json([
                'ok' => false,
                'message' => 'SMS record was not found or is no longer pending.',
            ], 404);
        }

        $this->json([
            'ok' => true,
            'message' => 'SMS status updated.',
        ]);
    }

    private function guardBridgeAccess(Request $request): void
    {
        if (!$this->sms->bridgeEnabled()) {
            $this->json([
                'ok' => false,
                'message' => 'SMS bridge mode is disabled.',
            ], 503);
        }

        $expectedToken = $this->sms->bridgeToken();
        if ($expectedToken === '') {
            $this->json([
                'ok' => false,
                'message' => 'SMS bridge token is not configured.',
            ], 503);
        }

        $authorization = trim((string) ($request->server['HTTP_AUTHORIZATION'] ?? ''));
        $bearerToken = str_starts_with(strtolower($authorization), 'bearer ')
            ? trim(substr($authorization, 7))
            : '';
        $providedToken = trim((string) ($request->server['HTTP_X_SMS_BRIDGE_TOKEN'] ?? ''));
        if ($providedToken === '') {
            $providedToken = $bearerToken !== '' ? $bearerToken : trim((string) $request->query('token', ''));
        }

        if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
            $this->json([
                'ok' => false,
                'message' => 'SMS bridge token is invalid.',
            ], 403);
        }
    }
}
