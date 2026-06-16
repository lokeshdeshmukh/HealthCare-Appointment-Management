<?php

use App\Core\Auth;

$quickDates = [];
for ($offset = 0; $offset < 14; $offset++) {
    $timestamp = strtotime('+' . $offset . ' day');
    $quickDates[] = [
        'value' => date('Y-m-d', $timestamp),
        'weekday' => date('D', $timestamp),
        'day' => date('d M', $timestamp),
    ];
}

$scopedClinic = current_clinic();
$returnTarget = $redirectTo ?? '/doctors/' . $doctor['id'] . '/book';
$googleClientId = trim((string) config('services.google.client_id', ''));
$smsConfigured = $smsConfigured ?? false;
$patient = Auth::check('patient') ? Auth::user() : null;
$patientDisplayName = $patient ? trim((string) (($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''))) : '';
$clinicName = $scopedClinic['name'] ?? $doctor['clinic_name'];
$clinicPhoneHref = !empty($doctor['clinic_phone'])
    ? 'tel:' . preg_replace('/[^0-9+]/', '', (string) $doctor['clinic_phone'])
    : (!empty($scopedClinic['phone']) ? 'tel:' . preg_replace('/[^0-9+]/', '', (string) $scopedClinic['phone']) : '');
?>
<section
    class="booking-surface"
    data-booking-experience
    data-patient-logged-in="<?= $patientLoggedIn ? '1' : '0' ?>"
    data-booking-redirect-to="<?= e((string) $returnTarget) ?>"
    data-clinic-name="<?= e((string) $clinicName) ?>"
    data-clinic-phone-href="<?= e((string) $clinicPhoneHref) ?>"
>
    <div class="booking-surface__hero">
        <div class="booking-surface__identity">
            <div class="doctor-card__avatar booking-surface__avatar">
                <?php if (!empty($doctor['profile_photo_path'])): ?>
                    <img src="<?= e(url((string) $doctor['profile_photo_path'])) ?>" alt="<?= e($doctor['name']) ?>" class="doctor-card__avatar-image">
                <?php else: ?>
                    <?= e(strtoupper(substr((string) $doctor['name'], 0, 1))) ?>
                <?php endif; ?>
            </div>
            <div class="booking-surface__copy">
                <p class="section-kicker"><?= e($clinicName) ?></p>
                <h1><?= e($doctor['name']) ?></h1>
                <div class="booking-surface__meta">
                    <span><?= e($doctor['specialization']) ?></span>
                    <span>INR <?= e(number_format((float) $doctor['consultation_fee'], 2)) ?></span>
                    <span><?= e((string) $doctor['slot_duration_minutes']) ?> min slot</span>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="<?= e(url('/doctors/' . $doctor['id'] . '/book')) ?>" class="booking-surface__form" data-booking-form>
        <?= csrf_field() ?>
        <input type="hidden" name="doctor_id" value="<?= e((string) $doctor['id']) ?>" data-slot-doctor>
        <input type="hidden" name="appointment_date" value="<?= e($quickDates[0]['value']) ?>" data-slot-date>
        <input type="hidden" name="start_time" data-slot-input required>

        <div class="booking-surface__panel">
            <div class="booking-surface__section-head">
                <div>
                    <p class="section-kicker">Select date and time</p>
                </div>
                <div class="booking-surface__section-badges">
                    <span class="section-badge section-badge--soft" data-selected-date-label><?= e(date('D, d M', strtotime($quickDates[0]['value']))) ?></span>
                    <span class="section-badge" data-date-status>Checking nearby slots</span>
                </div>
            </div>

            <div class="booking-date-pager">
                <button type="button" class="booking-date-pager__nav" data-date-scroll="prev" aria-label="Show earlier dates">‹</button>
                <div class="booking-date-pager__viewport">
                    <div class="quick-date-grid booking-surface__dates" data-date-strip>
                        <?php foreach ($quickDates as $index => $quickDate): ?>
                            <button
                                type="button"
                                class="quick-date-pill<?= $index === 0 ? ' is-active' : '' ?>"
                                data-quick-date="<?= e($quickDate['value']) ?>"
                            >
                                <span><?= e($quickDate['weekday']) ?></span>
                                <strong><?= e($quickDate['day']) ?></strong>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button type="button" class="booking-date-pager__nav" data-date-scroll="next" aria-label="Show more dates">›</button>
            </div>

            <div class="booking-surface__slot-block">
                <div data-slot-results class="slot-grid slot-grid--booking"></div>
            </div>
        </div>

        <div class="booking-surface__panel booking-surface__panel--soft">
            <details class="booking-surface__details">
                <summary>
                    <span>Optional note for clinic</span>
                    <?php if ($patientLoggedIn && $patientDisplayName !== ''): ?>
                        <span class="section-badge section-badge--soft">Signed in as <?= e($patientDisplayName) ?></span>
                    <?php else: ?>
                        <span class="section-badge section-badge--soft">Login after slot pick</span>
                    <?php endif; ?>
                </summary>
                <div class="booking-surface__details-body">
                    <textarea id="notes" name="notes" rows="3" class="booking-surface__notes" placeholder="Symptoms, reason for visit, or anything the clinic should know"></textarea>
                </div>
            </details>
        </div>

        <div class="booking-sticky-bar booking-sticky-bar--sheet">
            <div class="booking-sticky-bar__summary">
                <span data-selected-date-label-secondary><?= e(date('D, d M', strtotime($quickDates[0]['value']))) ?></span>
                <strong data-selected-time-label>No slot selected</strong>
            </div>
            <button
                class="btn-primary booking-sticky-bar__button"
                type="<?= $patientLoggedIn ? 'submit' : 'button' ?>"
                data-booking-submit
            ><?= $patientLoggedIn ? 'Confirm appointment' : 'Continue' ?></button>
        </div>
    </form>

    <?php if (!$patientLoggedIn): ?>
        <div class="booking-auth-modal" data-auth-modal hidden>
            <div class="booking-auth-modal__backdrop" data-auth-close></div>
            <div class="booking-auth-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="booking-login-title">
                <button type="button" class="booking-auth-modal__close" data-auth-close aria-label="Close login popup">×</button>
                <div class="booking-auth-modal__head">
                    <p class="section-kicker">Complete booking</p>
                    <h3 id="booking-login-title">Login to reserve this slot</h3>
                    <p data-auth-slot-copy>Select a slot first, then sign in here without leaving the page.</p>
                </div>

                <div class="auth-method-tabs booking-auth-modal__tabs">
                    <button type="button" class="auth-method-tab is-active" data-auth-tab="email">Email OTP</button>
                    <button type="button" class="auth-method-tab" data-auth-tab="mobile">Mobile OTP</button>
                    <?php if ($googleClientId !== ''): ?>
                        <button type="button" class="auth-method-tab" data-auth-tab="google">Google</button>
                    <?php endif; ?>
                </div>

                <div class="booking-auth-modal__message" data-auth-message hidden></div>

                <div class="booking-auth-modal__panel is-active" data-auth-panel="email">
                    <form class="auth-form" data-auth-send-form data-auth-channel="email">
                        <?= csrf_field() ?>
                        <input type="hidden" name="redirect_to" value="<?= e((string) $returnTarget) ?>">
                        <div>
                            <label for="booking_email">Email</label>
                            <input id="booking_email" type="email" name="email" placeholder="Enter your email" required>
                        </div>
                        <div>
                            <label for="booking_name_email">Full name</label>
                            <input id="booking_name_email" name="full_name" value="<?= e($patientDisplayName) ?>" placeholder="Helpful for first-time login">
                        </div>
                        <div>
                            <label for="booking_phone_email">Mobile (optional)</label>
                            <input id="booking_phone_email" name="phone" placeholder="Optional, for faster mobile login later">
                        </div>
                        <button class="btn-primary w-full" type="submit">Send email OTP</button>
                    </form>
                </div>

                <div class="booking-auth-modal__panel" data-auth-panel="mobile">
                    <form class="auth-form" data-auth-send-form data-auth-channel="mobile">
                        <?= csrf_field() ?>
                        <input type="hidden" name="redirect_to" value="<?= e((string) $returnTarget) ?>">
                        <div>
                            <label for="booking_phone">Mobile number</label>
                            <input id="booking_phone" name="phone" placeholder="Enter your mobile number" required>
                        </div>
                        <div>
                            <label for="booking_name_mobile">Full name</label>
                            <input id="booking_name_mobile" name="full_name" value="<?= e($patientDisplayName) ?>" placeholder="Helpful for first-time login">
                        </div>
                        <div>
                            <label for="booking_email_mobile">Email (optional)</label>
                            <input id="booking_email_mobile" type="email" name="email" placeholder="Needed if you want email confirmations">
                        </div>
                        <?php if (!$smsConfigured): ?>
                            <p class="auth-inline-note">SMS OTP becomes available after the platform SMS delivery method is configured. Until then, use email OTP or Google.</p>
                        <?php endif; ?>
                        <button class="btn-primary w-full" type="submit"<?= !$smsConfigured ? ' disabled' : '' ?>>Send mobile OTP</button>
                    </form>
                </div>

                <?php if ($googleClientId !== ''): ?>
                    <div class="booking-auth-modal__panel" data-auth-panel="google">
                        <div class="auth-google auth-google--modal">
                            <p class="auth-google__label">Use your Google account to continue instantly.</p>
                            <form method="post" action="<?= e(url('/patient/login/google')) ?>" data-google-login-form data-google-ajax="true">
                                <?= csrf_field() ?>
                                <input type="hidden" name="redirect_to" value="<?= e((string) $returnTarget) ?>">
                                <input type="hidden" name="credential" value="" data-google-credential>
                            </form>
                            <script src="https://accounts.google.com/gsi/client" async defer></script>
                            <div
                                id="g_id_onload_booking"
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
                    </div>
                <?php endif; ?>

                <div class="booking-auth-modal__panel" data-auth-panel="verify">
                    <form class="auth-form" data-auth-verify-form>
                        <?= csrf_field() ?>
                        <input type="hidden" name="redirect_to" value="<?= e((string) $returnTarget) ?>">
                        <input type="hidden" name="challenge_token" value="" data-auth-challenge-token>
                        <input type="hidden" name="channel" value="" data-auth-channel-input>
                        <div>
                            <label for="booking_otp">Enter OTP</label>
                            <input id="booking_otp" name="otp" inputmode="numeric" autocomplete="one-time-code" placeholder="Enter OTP" required>
                        </div>
                        <p class="auth-inline-note" data-auth-verify-copy>We’ll send the OTP after you submit your details.</p>
                        <button class="btn-primary w-full" type="submit">Verify and continue</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endif; ?>
</section>
