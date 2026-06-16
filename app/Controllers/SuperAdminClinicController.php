<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Models\Clinic;
use App\Services\AuthService;
use App\Services\EnvFileService;
use App\Services\SuperAdminService;

final class SuperAdminClinicController extends Controller
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly EnvFileService $env = new EnvFileService(),
        private readonly SuperAdminService $admins = new SuperAdminService(),
        private readonly Clinic $clinics = new Clinic()
    )
    {
    }

    public function index(): never
    {
        $this->admins->ensureDefaultAdmin();
        $deployToken = (string) env('DEPLOY_TOKEN', '');
        $smsBridgeToken = (string) env('SMS_BRIDGE_TOKEN', '');
        $this->view('super-admin/dashboard', [
            'title' => 'Platform Clinics',
            'clinics' => $this->clinics->allForPlatform(),
            'deployToken' => $deployToken,
            'deployHookUrl' => rtrim((string) config('app.url'), '/') . '/deploy/run-updates?token=' . urlencode($deployToken),
            'smsBridgeEnabled' => (bool) config('services.sms.bridge_enabled', false),
            'smsBridgeToken' => $smsBridgeToken,
            'smsBridgeBatchLimit' => (int) config('services.sms.bridge_batch_limit', 25),
            'smsPendingUrl' => rtrim((string) config('app.url'), '/') . '/api/pending-sms?token=' . urlencode($smsBridgeToken),
            'smsStatusUrl' => rtrim((string) config('app.url'), '/') . '/api/sms-status',
        ]);
    }

    public function store(Request $request): never
    {
        $data = $request->all();
        Session::flashInput($data);

        foreach (['name', 'address', 'phone', 'email', 'password'] as $field) {
            if (trim((string) ($data[$field] ?? '')) === '') {
                $this->redirect('/super-admin/dashboard', 'Complete all required clinic fields.', 'error');
            }
        }

        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $this->redirect('/super-admin/dashboard', 'Clinic password confirmation does not match.', 'error');
        }

        try {
            $this->auth->createClinicBySuperAdmin($data, $request->file('logo'));
        } catch (\Throwable $exception) {
            $this->redirect('/super-admin/dashboard', $exception->getMessage(), 'error');
        }

        $this->redirect('/super-admin/dashboard', 'Clinic created and activated successfully.');
    }

    public function updateDeployToken(Request $request): never
    {
        $token = trim((string) $request->input('deploy_token'));
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
        }

        $smsBridgeToken = trim((string) $request->input('sms_bridge_token'));
        if ($smsBridgeToken === '') {
            $smsBridgeToken = (string) env('SMS_BRIDGE_TOKEN', '');
        }

        $smsBridgeEnabled = (string) $request->input('sms_bridge_enabled', (string) ((bool) config('services.sms.bridge_enabled', false) ? '1' : '0'));
        $smsBridgeBatchLimit = max(1, (int) $request->input('sms_bridge_batch_limit', (int) config('services.sms.bridge_batch_limit', 25)));

        try {
            $this->env->set([
                'DEPLOY_TOKEN' => $token,
                'SMS_BRIDGE_ENABLED' => in_array($smsBridgeEnabled, ['1', 'true', 'on', 'yes'], true) ? 'true' : 'false',
                'SMS_BRIDGE_TOKEN' => $smsBridgeToken,
                'SMS_BRIDGE_BATCH_LIMIT' => (string) $smsBridgeBatchLimit,
            ]);
        } catch (\Throwable $exception) {
            $this->redirect('/super-admin/dashboard', $exception->getMessage(), 'error');
        }

        $this->redirect('/super-admin/dashboard', 'Platform delivery settings updated successfully.');
    }

    public function toggleStatus(Request $request, $id): never
    {
        $clinic = $this->clinics->findActiveById((int) $id);
        if (!$clinic) {
            $this->redirect('/super-admin/dashboard', 'Clinic not found.', 'error');
        }

        $nextStatus = ($clinic['status'] ?? '') === 'active' ? 'disabled' : 'active';
        $this->clinics->updateById((int) $id, [
            'status' => $nextStatus,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $message = $nextStatus === 'active'
            ? 'Clinic is now live for booking.'
            : 'Clinic has been turned off for booking and clinic admin login.';

        $this->redirect('/super-admin/dashboard', $message);
    }

    public function delete(Request $request, $id): never
    {
        $clinic = $this->clinics->findActiveById((int) $id);
        if (!$clinic) {
            $this->redirect('/super-admin/dashboard', 'Clinic not found.', 'error');
        }

        $this->clinics->updateById((int) $id, [
            'status' => 'disabled',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->clinics->softDelete((int) $id);

        $this->redirect('/super-admin/dashboard', 'Clinic deleted successfully.');
    }
}
