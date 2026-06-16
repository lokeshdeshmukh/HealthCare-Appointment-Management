<section class="grid gap-6">
    <div class="panel">
        <p class="text-sm uppercase tracking-[0.22em] text-slate-500">Platform dashboard</p>
        <h1 class="mt-2 text-3xl font-semibold">Manage clinics and deployment access</h1>
        <p class="mt-3 max-w-3xl text-slate-600">Use the fixed super admin account to provision clinics, switch them on or off, remove old clinics, and control the deploy token from one place.</p>
    </div>

    <div class="grid gap-6 xl:grid-cols-[0.88fr_1.12fr]">
        <div class="panel">
            <p class="text-sm uppercase tracking-[0.22em] text-slate-500">Deployment control</p>
            <h2 class="mt-2 text-2xl font-semibold">Deploy and SMS bridge settings</h2>
            <p class="mt-3 text-sm text-slate-500">Keep deployment access and mobile OTP delivery settings at platform level. Your Android app can poll pending OTP SMS requests from here.</p>
            <form method="post" action="<?= e(url('/super-admin/deploy-token')) ?>" class="mt-6 grid gap-4">
                <?= csrf_field() ?>
                <div>
                    <label for="deploy_token">Deploy token</label>
                    <input id="deploy_token" name="deploy_token" value="<?= e($deployToken) ?>" placeholder="Leave blank and save to generate a new token">
                </div>
                <div>
                    <label for="deploy_hook_url">Deploy hook URL</label>
                    <input id="deploy_hook_url" value="<?= e($deployHookUrl) ?>" readonly>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="sms_bridge_enabled">SMS bridge</label>
                        <select id="sms_bridge_enabled" name="sms_bridge_enabled">
                            <option value="0" <?= !$smsBridgeEnabled ? 'selected' : '' ?>>Disabled</option>
                            <option value="1" <?= $smsBridgeEnabled ? 'selected' : '' ?>>Enabled</option>
                        </select>
                    </div>
                    <div>
                        <label for="sms_bridge_batch_limit">Pending SMS batch size</label>
                        <input id="sms_bridge_batch_limit" type="number" min="1" max="100" name="sms_bridge_batch_limit" value="<?= e((string) $smsBridgeBatchLimit) ?>">
                    </div>
                </div>
                <div>
                    <label for="sms_bridge_token">SMS bridge token</label>
                    <input id="sms_bridge_token" name="sms_bridge_token" value="<?= e($smsBridgeToken) ?>" placeholder="Set a secret token used by the Android sender app">
                </div>
                <div>
                    <label for="sms_pending_url">Pending SMS API</label>
                    <input id="sms_pending_url" value="<?= e($smsPendingUrl) ?>" readonly>
                </div>
                <div>
                    <label for="sms_status_url">SMS status API</label>
                    <input id="sms_status_url" value="<?= e($smsStatusUrl) ?>" readonly>
                </div>
                <button class="btn-primary" type="submit">Save platform settings</button>
            </form>
        </div>

        <div class="panel">
            <p class="text-sm uppercase tracking-[0.22em] text-slate-500">New clinic</p>
            <h2 class="mt-2 text-2xl font-semibold">Provision clinic admin</h2>
            <form method="post" action="<?= e(url('/super-admin/clinics')) ?>" enctype="multipart/form-data" class="mt-6 grid gap-4">
                <?= csrf_field() ?>
                <div>
                    <label for="name">Clinic name</label>
                    <input id="name" name="name" value="<?= e((string) old('name')) ?>" required>
                </div>
                <div>
                    <label for="slug">Slug</label>
                    <input id="slug" name="slug" value="<?= e((string) old('slug')) ?>" placeholder="Optional. Leave blank to auto-generate from clinic name.">
                </div>
                <div>
                    <label for="address">Address</label>
                    <textarea id="address" name="address" rows="3" required><?= e((string) old('address')) ?></textarea>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="phone">Phone</label>
                        <input id="phone" name="phone" value="<?= e((string) old('phone')) ?>" required>
                    </div>
                    <div>
                        <label for="email">Admin email</label>
                        <input id="email" type="email" name="email" value="<?= e((string) old('email')) ?>" required>
                    </div>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="password">Temporary password</label>
                        <input id="password" type="password" name="password" required>
                    </div>
                    <div>
                        <label for="password_confirmation">Confirm password</label>
                        <input id="password_confirmation" type="password" name="password_confirmation" required>
                    </div>
                </div>
                <div>
                    <label for="logo">Clinic logo</label>
                    <input id="logo" type="file" name="logo" accept="image/*">
                </div>
                <button class="btn-primary" type="submit">Create clinic</button>
            </form>
        </div>
    </div>

    <div class="table-wrap">
        <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
            <thead class="bg-slate-50 text-slate-500">
            <tr>
                <th class="px-4 py-3 font-medium">Clinic</th>
                <th class="px-4 py-3 font-medium">Booking URL</th>
                <th class="px-4 py-3 font-medium">Doctors</th>
                <th class="px-4 py-3 font-medium">Status</th>
                <th class="px-4 py-3 font-medium">Admin email</th>
                <th class="px-4 py-3 font-medium">Actions</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-200 bg-white">
            <?php foreach ($clinics as $clinic): ?>
                <?php
                $clinicUrl = rtrim((string) config('app.url'), '/') . '/clinics/' . $clinic['slug'];
                $isActive = (string) $clinic['status'] === 'active';
                ?>
                <tr>
                    <td class="px-4 py-3 align-top">
                        <strong class="block text-slate-900"><?= e($clinic['name']) ?></strong>
                        <span class="text-slate-500"><?= e($clinic['phone']) ?></span>
                    </td>
                    <td class="px-4 py-3 align-top">
                        <a class="font-medium text-brand-700" href="<?= e($clinicUrl) ?>" target="_blank" rel="noreferrer"><?= e($clinic['slug']) ?></a>
                    </td>
                    <td class="px-4 py-3 align-top"><?= e((string) $clinic['doctor_count']) ?></td>
                    <td class="px-4 py-3 align-top">
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $isActive ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' ?>">
                            <?= e($isActive ? 'live' : 'off') ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 align-top"><?= e($clinic['email']) ?></td>
                    <td class="px-4 py-3 align-top">
                        <div class="grid gap-2 sm:grid-cols-2">
                            <form method="post" action="<?= e(url('/super-admin/clinics/' . $clinic['id'] . '/status')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn-secondary w-full" type="submit"><?= e($isActive ? 'Turn off' : 'Turn on') ?></button>
                            </form>
                            <form method="post" action="<?= e(url('/super-admin/clinics/' . $clinic['id'] . '/delete')) ?>" onsubmit="return confirm('Delete this clinic? The clinic booking page and clinic admin access will stop working.');">
                                <?= csrf_field() ?>
                                <button class="danger-button w-full" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
