<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ClinicContext;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Session;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\PatientAccessService;
use App\Services\SmsService;

final class PatientAuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth = new AuthService(),
        private readonly PatientAccessService $access = new PatientAccessService()
    )
    {
    }

    public function showRegister(Request $request): never
    {
        $redirectTo = $this->sanitizeRedirectTo((string) $request->query('redirect_to', ''));

        $this->view('auth/patient/register', [
            'title' => 'Patient Registration',
            'redirectTo' => $redirectTo,
        ]);
    }

    public function register(Request $request): never
    {
        $data = $request->all();
        Session::flashInput($data);
        $redirectTo = $this->sanitizeRedirectTo((string) ($data['redirect_to'] ?? ''));

        $validator = new Validator();
        if (!$validator->validate($data, [
            'first_name' => ['required', 'min:2'],
            'last_name' => ['required', 'min:2'],
            'phone' => ['required', 'min:8'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8'],
        ])) {
            $this->redirect($this->withRedirectQuery('/patient/register', $redirectTo), 'Please complete all required patient details.', 'error');
        }

        if (($data['password'] ?? '') !== ($data['password_confirmation'] ?? '')) {
            $this->redirect($this->withRedirectQuery('/patient/register', $redirectTo), 'Password confirmation does not match.', 'error');
        }

        try {
            $patient = $this->auth->registerPatient($data);
            Auth::login('patient', (int) $patient['id']);
        } catch (\Throwable $exception) {
            $this->redirect($this->withRedirectQuery('/patient/register', $redirectTo), $exception->getMessage(), 'error');
        }

        $this->redirect($redirectTo !== '' ? $redirectTo : '/patient/dashboard', 'Your account is ready.');
    }

    public function showLogin(Request $request): never
    {
        $redirectTo = $this->sanitizeRedirectTo((string) $request->query('redirect_to', ''));
        $challengeToken = trim((string) $request->query('challenge', ''));
        $challenge = $challengeToken !== '' ? $this->access->challengeForDisplay($challengeToken) : null;
        $mode = (string) $request->query('mode', $challenge['channel'] ?? 'email');
        if (!in_array($mode, ['email', 'mobile', 'password'], true)) {
            $mode = $challenge['channel'] ?? 'email';
        }

        $this->view('auth/patient/login', [
            'title' => 'Patient Login',
            'redirectTo' => $redirectTo,
            'challenge' => $challenge,
            'loginMode' => $mode,
            'smsConfigured' => (new SmsService())->isConfigured(),
            'googleClientId' => trim((string) config('services.google.client_id', '')),
        ]);
    }

    public function login(Request $request): never
    {
        $email = trim((string) $request->input('email'));
        $password = (string) $request->input('password');
        $redirectTo = $this->sanitizeRedirectTo((string) $request->input('redirect_to', ''));
        Session::flashInput(['email' => $email, 'redirect_to' => $redirectTo]);

        if (!$this->auth->attemptPatientLogin($email, $password)) {
            $this->redirect($this->withLoginState('/patient/login', 'password', $redirectTo), 'Invalid login credentials.', 'error');
        }

        $this->redirect($redirectTo !== '' ? $redirectTo : '/patient/dashboard', 'Welcome back.');
    }

    public function sendOtp(Request $request): never
    {
        $data = $request->all();
        $redirectTo = $this->sanitizeRedirectTo((string) ($data['redirect_to'] ?? ''));
        $mode = (string) ($data['channel'] ?? 'email');
        Session::flashInput($data);

        try {
            $challenge = $this->access->sendLoginOtp($mode, array_merge($data, ['redirect_to' => $redirectTo]), current_clinic());
        } catch (\Throwable $exception) {
            if ($request->isJson()) {
                $this->json([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $this->redirect($this->withLoginState('/patient/login', $mode, $redirectTo), $exception->getMessage(), 'error');
        }

        if ($request->isJson()) {
            $this->json([
                'ok' => true,
                'message' => 'OTP sent successfully.',
                'challenge' => $challenge,
            ]);
        }

        $query = $this->withLoginState('/patient/login', (string) $challenge['channel'], $redirectTo);
        $separator = str_contains($query, '?') ? '&' : '?';
        $this->redirect($query . $separator . 'challenge=' . urlencode((string) $challenge['challenge_token']), 'Enter the OTP that we just sent to ' . $challenge['masked_destination'] . '.');
    }

    public function verifyOtp(Request $request): never
    {
        $challengeToken = trim((string) $request->input('challenge_token'));
        $otp = trim((string) $request->input('otp'));
        $redirectTo = $this->sanitizeRedirectTo((string) $request->input('redirect_to', ''));
        $mode = (string) $request->input('channel', 'email');

        if ($challengeToken === '' || $otp === '') {
            if ($request->isJson()) {
                $this->json([
                    'ok' => false,
                    'message' => 'Enter the OTP to continue.',
                ], 422);
            }

            $base = $this->withLoginState('/patient/login', $mode, $redirectTo);
            $separator = str_contains($base, '?') ? '&' : '?';
            $this->redirect($base . $separator . 'challenge=' . urlencode($challengeToken), 'Enter the OTP to continue.', 'error');
        }

        try {
            $patient = $this->access->verifyLoginOtp($challengeToken, $otp);
            Auth::login('patient', (int) $patient['id']);
        } catch (\Throwable $exception) {
            if ($request->isJson()) {
                $this->json([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $base = $this->withLoginState('/patient/login', $mode, $redirectTo);
            $separator = str_contains($base, '?') ? '&' : '?';
            $this->redirect($base . $separator . 'challenge=' . urlencode($challengeToken), $exception->getMessage(), 'error');
        }

        if ($request->isJson()) {
            $this->json([
                'ok' => true,
                'message' => 'You are signed in.',
                'patient' => [
                    'id' => $patient['id'],
                    'name' => trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')),
                    'email' => $patient['email'] ?? null,
                    'phone' => $patient['phone'] ?? null,
                ],
            ]);
        }

        $this->redirect($redirectTo !== '' ? $redirectTo : '/patient/dashboard', 'You are signed in.');
    }

    public function googleLogin(Request $request): never
    {
        $redirectTo = $this->sanitizeRedirectTo((string) $request->input('redirect_to', ''));
        $credential = trim((string) $request->input('credential'));

        if ($credential === '') {
            if ($request->isJson()) {
                $this->json([
                    'ok' => false,
                    'message' => 'Google sign-in response was empty. Please try again.',
                ], 422);
            }

            $this->redirect($this->withLoginState('/patient/login', 'email', $redirectTo), 'Google sign-in response was empty. Please try again.', 'error');
        }

        try {
            $patient = $this->access->loginWithGoogle($credential);
            Auth::login('patient', (int) $patient['id']);
        } catch (\Throwable $exception) {
            if ($request->isJson()) {
                $this->json([
                    'ok' => false,
                    'message' => $exception->getMessage(),
                ], 422);
            }

            $this->redirect($this->withLoginState('/patient/login', 'email', $redirectTo), $exception->getMessage(), 'error');
        }

        if ($request->isJson()) {
            $this->json([
                'ok' => true,
                'message' => 'Signed in with Google successfully.',
                'patient' => [
                    'id' => $patient['id'],
                    'name' => trim(($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '')),
                    'email' => $patient['email'] ?? null,
                    'phone' => $patient['phone'] ?? null,
                ],
            ]);
        }

        $this->redirect($redirectTo !== '' ? $redirectTo : '/patient/dashboard', 'Signed in with Google successfully.');
    }

    public function logout(): never
    {
        Auth::logout();
        $this->redirect(ClinicContext::isScoped() ? '/' : '/patient/login', 'You have been signed out.');
    }

    public function showForgotPassword(): never
    {
        $this->view('auth/patient/forgot-password', ['title' => 'Patient Password Reset']);
    }

    public function sendResetLink(Request $request): never
    {
        $email = trim((string) $request->input('email'));
        if ($email !== '') {
            $this->auth->sendPatientResetLink($email);
        }

        $this->redirect('/patient/login', 'If the account exists, a reset link has been sent.');
    }

    public function showResetPassword(Request $request): never
    {
        $this->view('auth/patient/reset-password', [
            'title' => 'Reset Patient Password',
            'token' => (string) $request->query('token', ''),
        ]);
    }

    public function resetPassword(Request $request): never
    {
        $token = (string) $request->input('token');
        $password = (string) $request->input('password');
        $confirmation = (string) $request->input('password_confirmation');

        if ($password === '' || $password !== $confirmation) {
            $this->redirect('/patient/reset-password?token=' . urlencode($token), 'Password confirmation does not match.', 'error');
        }

        if (!$this->auth->resetPatientPassword($token, $password)) {
            $this->redirect('/patient/reset-password?token=' . urlencode($token), 'Reset link is invalid or expired.', 'error');
        }

        $this->redirect('/patient/login', 'Password updated. Please sign in.');
    }

    private function sanitizeRedirectTo(string $redirectTo): string
    {
        $redirectTo = trim($redirectTo);
        if ($redirectTo === '' || !str_starts_with($redirectTo, '/')) {
            return '';
        }

        if (preg_match('#^//#', $redirectTo) === 1 || preg_match('#^https?://#i', $redirectTo) === 1) {
            return '';
        }

        return $redirectTo;
    }

    private function withRedirectQuery(string $path, string $redirectTo): string
    {
        if ($redirectTo === '') {
            return $path;
        }

        return $path . '?redirect_to=' . urlencode($redirectTo);
    }

    private function withLoginState(string $path, string $mode, string $redirectTo): string
    {
        $query = ['mode=' . urlencode($mode)];
        if ($redirectTo !== '') {
            $query[] = 'redirect_to=' . urlencode($redirectTo);
        }

        return $path . '?' . implode('&', $query);
    }
}
