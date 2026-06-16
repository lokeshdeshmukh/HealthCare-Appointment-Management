<?php

$clinic = current_clinic();
$redirectTo = $redirectTo ?? (string) old('redirect_to');
$challenge = $challenge ?? null;
$loginMode = $loginMode ?? 'email';
$smsConfigured = $smsConfigured ?? false;
$googleClientId = $googleClientId ?? '';
?>
<section class="auth-shell">
    <aside class="auth-aside">
        <span class="eyebrow-pill"><?= $clinic ? 'Continue clinic booking' : 'Patient sign in' ?></span>
        <h1><?= $clinic ? 'Resume booking with ' . e($clinic['name']) : 'Welcome back to your appointment dashboard' ?></h1>
        <p><?= $clinic ? 'Use email OTP, mobile OTP, or Google to re-enter the booking flow from the same WhatsApp-friendly clinic link.' : 'Patients can sign in without a password, continue booking quickly, and manage upcoming appointments from one place.' ?></p>

        <?php if ($clinic): ?>
            <div class="auth-clinic-card">
                <strong><?= e($clinic['name']) ?></strong>
                <span><?= e($clinic['address']) ?></span>
                <span><?= e($clinic['phone']) ?></span>
            </div>
        <?php endif; ?>
    </aside>

    <div class="auth-card auth-card--wide">
        <p class="section-kicker">Patient login</p>
        <h2>Passwordless access</h2>

        <div class="auth-method-tabs">
            <a href="<?= e(url('/patient/login?mode=email' . ($redirectTo !== '' ? '&redirect_to=' . urlencode((string) $redirectTo) : '') . ($challenge ? '&challenge=' . urlencode((string) $challenge['challenge_token']) : ''))) ?>" class="auth-method-tab<?= $loginMode === 'email' ? ' is-active' : '' ?>">Email OTP</a>
            <a href="<?= e(url('/patient/login?mode=mobile' . ($redirectTo !== '' ? '&redirect_to=' . urlencode((string) $redirectTo) : '') . ($challenge ? '&challenge=' . urlencode((string) $challenge['challenge_token']) : ''))) ?>" class="auth-method-tab<?= $loginMode === 'mobile' ? ' is-active' : '' ?>">Mobile OTP</a>
            <a href="<?= e(url('/patient/login?mode=password' . ($redirectTo !== '' ? '&redirect_to=' . urlencode((string) $redirectTo) : ''))) ?>" class="auth-method-tab<?= $loginMode === 'password' ? ' is-active' : '' ?>">Password</a>
        </div>

        <?php if ($challenge): ?>
            <div class="auth-otp-card">
                <div>
                    <p class="section-kicker">Step 2</p>
                    <h3>Enter the code</h3>
                    <p>We sent a secure OTP to <strong><?= e($challenge['masked_destination']) ?></strong>.</p>
                </div>
                <form method="post" action="<?= e(url('/patient/login/otp/verify')) ?>" class="auth-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="challenge_token" value="<?= e((string) $challenge['challenge_token']) ?>">
                    <input type="hidden" name="redirect_to" value="<?= e((string) $redirectTo) ?>">
                    <input type="hidden" name="channel" value="<?= e((string) $challenge['channel']) ?>">
                    <div>
                        <label for="otp">One-time password</label>
                        <input id="otp" name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter 6-digit OTP" required>
                    </div>
                    <button class="btn-primary w-full" type="submit">Verify and continue</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($loginMode === 'mobile'): ?>
            <form method="post" action="<?= e(url('/patient/login/otp/send')) ?>" class="auth-form">
                <?= csrf_field() ?>
                <input type="hidden" name="channel" value="mobile">
                <input type="hidden" name="redirect_to" value="<?= e((string) $redirectTo) ?>">
                <div class="form-grid-2">
                    <div>
                        <label for="phone">Mobile number</label>
                        <input id="phone" name="phone" value="<?= e((string) old('phone')) ?>" placeholder="10-digit or international mobile" required>
                    </div>
                    <div>
                        <label for="full_name_mobile">Full name</label>
                        <input id="full_name_mobile" name="full_name" value="<?= e((string) old('full_name')) ?>" placeholder="Helpful for first-time sign-in">
                    </div>
                </div>
                <div>
                    <label for="email_mobile">Email (optional)</label>
                    <input id="email_mobile" type="email" name="email" value="<?= e((string) old('email')) ?>" placeholder="Used for appointment confirmations if available">
                </div>
                <?php if (!$smsConfigured): ?>
                    <p class="auth-inline-note">SMS OTP becomes available after the platform SMS delivery method is configured. You can still use email OTP or Google right away.</p>
                <?php endif; ?>
                <button class="btn-primary w-full" type="submit"<?= !$smsConfigured ? ' disabled' : '' ?>>Send mobile OTP</button>
            </form>
        <?php elseif ($loginMode === 'password'): ?>
            <form method="post" action="<?= e(url('/patient/login')) ?>" class="auth-form">
                <?= csrf_field() ?>
                <input type="hidden" name="redirect_to" value="<?= e((string) $redirectTo) ?>">
                <div>
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" value="<?= e((string) old('email')) ?>" required>
                </div>
                <div>
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required>
                </div>
                <button class="btn-primary w-full" type="submit">Login with password</button>
            </form>
        <?php else: ?>
            <form method="post" action="<?= e(url('/patient/login/otp/send')) ?>" class="auth-form">
                <?= csrf_field() ?>
                <input type="hidden" name="channel" value="email">
                <input type="hidden" name="redirect_to" value="<?= e((string) $redirectTo) ?>">
                <div class="form-grid-2">
                    <div>
                        <label for="email_otp">Email</label>
                        <input id="email_otp" type="email" name="email" value="<?= e((string) old('email')) ?>" required>
                    </div>
                    <div>
                        <label for="full_name_email">Full name</label>
                        <input id="full_name_email" name="full_name" value="<?= e((string) old('full_name')) ?>" placeholder="Helpful for first-time sign-in">
                    </div>
                </div>
                <div>
                    <label for="phone_email">Mobile (optional)</label>
                    <input id="phone_email" name="phone" value="<?= e((string) old('phone')) ?>" placeholder="Used for faster mobile sign-in next time">
                </div>
                <button class="btn-primary w-full" type="submit">Send email OTP</button>
            </form>
        <?php endif; ?>

        <?php if ($googleClientId !== ''): ?>
            <div class="auth-google">
                <p class="auth-google__label">Google sign-in</p>
                <form method="post" action="<?= e(url('/patient/login/google')) ?>" data-google-login-form>
                    <?= csrf_field() ?>
                    <input type="hidden" name="redirect_to" value="<?= e((string) $redirectTo) ?>">
                    <input type="hidden" name="credential" value="" data-google-credential>
                </form>
                <script src="https://accounts.google.com/gsi/client" async defer></script>
                <div
                    id="g_id_onload"
                    data-client_id="<?= e($googleClientId) ?>"
                    data-context="signin"
                    data-ux_mode="popup"
                    data-callback="handleGooglePatientSignIn"
                    data-auto_prompt="false"
                ></div>
                <div
                    class="g_id_signin"
                    data-type="standard"
                    data-shape="pill"
                    data-theme="outline"
                    data-text="continue_with"
                    data-size="large"
                    data-logo_alignment="left"
                ></div>
            </div>
        <?php endif; ?>

        <div class="auth-card__footer">
            <a href="<?= e(url('/patient/forgot-password')) ?>">Forgot password?</a>
            <a href="<?= e(url('/patient/register' . ($redirectTo !== '' ? '?redirect_to=' . urlencode((string) $redirectTo) : ''))) ?>">Create account</a>
        </div>
    </div>
</section>
