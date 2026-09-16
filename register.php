<?php
/**
 * EcoCycle — guided multi-step registration.
 */
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$neighborhoodOptions = ['Greendale', 'Maple Ridge', 'Cedar Heights', 'Oakview', 'South Park', 'Riverbend', 'Northfield', 'Lakeside'];
try {
    $neighborhoodOptions = db()->query('SELECT name FROM neighborhoods WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) ?: $neighborhoodOptions;
} catch (Throwable $e) {
    // Use the built-in list while an older database is being upgraded.
}
$avatarOptions = ['🌱', '🌿', '🌳', '🍃', '☀️', '💧', '🌍', '♻️'];
$interestOptions = ['Plastic', 'Paper', 'Glass', 'Metal', 'E-waste', 'Organic Waste'];

$errors = [];
$old = [
    'auth_method' => '',
    'name' => '',
    'email' => '',
    'neighborhood' => 'Greendale',
    'password' => '',
    'confirm' => '',
    'avatar' => '🌱',
    'interests' => ['Plastic', 'Paper'],
    'referral_code' => '',
    'terms' => false,
    'newsletter' => false,
    'otp' => '',
    'step' => 1,
];
$old = array_replace($old, $_SESSION['signup_wizard'] ?? []);
$old['interests'] = is_array($old['interests']) ? $old['interests'] : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $step = max(1, min(6, (int) ($_POST['step'] ?? 1)));
        $old['step'] = $step;
        foreach (['auth_method', 'name', 'email', 'neighborhood', 'password', 'confirm', 'avatar', 'referral_code', 'otp'] as $field) {
            if (array_key_exists($field, $_POST)) {
                $old[$field] = trim((string) $_POST[$field]);
            }
        }
        if ($step === 3) {
            $old['newsletter'] = !empty($_POST['newsletter']);
        }
        if ($step === 5) {
            $old['terms'] = !empty($_POST['terms']);
            $old['interests'] = isset($_POST['interests']) ? array_values((array) $_POST['interests']) : [];
        }

        if (!empty($_POST['back'])) {
            $old['step'] = max(1, $step - 1);
        }

        if (!empty($_POST['back'])) {
            // Keep collected fields while moving backward.
        } elseif ($step === 1) {
            if (!in_array($old['auth_method'], ['google', 'apple', 'email'], true)) {
                $errors[] = 'Choose a sign-up method to continue.';
            } else {
                $old['step'] = 2;
            }
        }

        if (empty($_POST['back']) && $step === 2) {
            if ($old['name'] === '' || mb_strlen($old['name']) > 120) {
                $errors[] = 'Please enter your full name.';
            }
            if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'That email looks a little off — please check for typos.';
            }
            if ($old['neighborhood'] === '') {
                $errors[] = 'Please enter your neighborhood.';
            }
            if (!$errors) {
                $exists = db()->prepare('SELECT id FROM users WHERE email = ?');
                $exists->execute([$old['email']]);
                if ($exists->fetch()) {
                    $errors[] = 'Looks like you already have an account. <a class="font-semibold text-emerald-600 underline" href="login.php">Log in instead?</a>';
                }
            }
        }

        if (empty($_POST['back']) && $step === 3) {
            if (mb_strlen($old['password']) < 8) {
                $errors[] = 'Password must be at least 8 characters.';
            } elseif (!preg_match('/\d/', $old['password']) || !preg_match('/[^A-Za-z0-9]/', $old['password'])) {
                $errors[] = 'Use at least one number and one special character.';
            }
            if ($old['password'] !== $old['confirm']) {
                $errors[] = 'Passwords do not match.';
            }
        }

        if (empty($_POST['back']) && $step === 4) {
            if (!preg_match('/^\d{6}$/', $old['otp'])) {
                $errors[] = 'Please enter the 6-digit verification code.';
            }
        }

        if (empty($_POST['back']) && $step === 5) {
            if (empty($old['avatar'])) {
                $errors[] = 'Please choose an avatar.';
            }
            if (empty($old['interests'])) {
                $errors[] = 'Choose at least one recycling interest.';
            }
            if (!$old['terms']) {
                $errors[] = 'You must accept the Terms of Service and Privacy Policy.';
            }
        }

        if (!$errors && empty($_POST['back']) && $step === 3) {
            $old['step'] = 4;
        }

        if (!$errors && empty($_POST['back']) && $step === 2) {
            $old['step'] = 3;
        }

        if (!$errors && empty($_POST['back']) && $step === 4) {
            $old['step'] = 5;
        }

        if (!$errors && empty($_POST['back']) && $step === 5) {
            $old['step'] = 6;
        }

        if (!$errors && empty($_POST['back']) && $step === 6) {
            $name = trim($old['name']);
            $email = trim($old['email']);
            $neighborhood = trim($old['neighborhood']) ?: 'Greendale';
            $password = $old['password'];
            $avatar = $old['avatar'];
            $interests = implode(',', array_map('trim', $old['interests']));
            $referral = trim($old['referral_code']);

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, neighborhood, auth_provider, avatar_id, interests, referral_code_used, newsletter_opt_in) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    $neighborhood,
                    $old['auth_method'],
                    $avatar,
                    $interests,
                    $referral !== '' ? $referral : null,
                    $old['newsletter'] ? 1 : 0,
                ]);

                $userId = (int) $pdo->lastInsertId();
                createWallet($userId);
                applyWelcomeBonus($userId);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'We could not finish setting up your account. Please try again.';
            }

            if ($errors) {
                $old['step'] = 5;
            } else {
                unset($_SESSION['signup_wizard']);
                loginUser($userId);
                setFlash('success', 'Welcome to EcoCycle — your EcoWallet is ready!');
                header('Location: dashboard.php');
                exit;
            }
        }

        $_SESSION['signup_wizard'] = $old;
    }
}

$pageTitle = 'Create your account';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-4xl mx-auto px-4 py-10">
    <div class="mx-auto max-w-2xl text-center">
        <div class="eco-badge mb-5">
            <span>Step <?= min(6, $old['step']) ?> of 6</span>
        </div>
        <h1 class="text-3xl font-extrabold text-slate-900">Create your EcoCycle account</h1>
        <p class="mt-2 text-slate-600">A few quick steps and your recycling journey starts now.</p>
    </div>

    <div class="eco-panel mx-auto mt-8 max-w-2xl p-6 md:p-8">
        <div class="mb-8 flex items-center justify-between gap-3">
            <?php foreach ([1,2,3,4,5,6] as $step): ?>
                <div class="flex-1">
                    <div class="eco-step-dot <?php echo $old['step'] >= $step ? 'active' : ''; ?>"></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($errors): ?>
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                <?php foreach ($errors as $err): ?>
                    <div class="mb-1 last:mb-0"><?= $err ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" class="space-y-6" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="step" value="<?= e((string) $old['step']) ?>">

            <?php if ($old['step'] === 1): ?>
                <div class="space-y-5">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">Get started</h2>
                        <p class="mt-1 text-sm text-slate-600">Choose how you want to join EcoCycle.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <button type="button" data-auth="google" aria-pressed="false" class="auth-option rounded-2xl p-4 text-left">
                            <div class="text-2xl">🔵</div>
                            <div class="mt-2 font-bold text-slate-900">Google</div>
                        </button>
                        <button type="button" data-auth="apple" aria-pressed="false" class="auth-option rounded-2xl p-4 text-left">
                            <div class="text-2xl" aria-hidden="true">Apple</div>
                            <div class="mt-2 font-bold text-slate-900">Apple</div>
                        </button>
                        <button type="button" data-auth="email" aria-pressed="false" class="auth-option rounded-2xl p-4 text-left">
                            <div class="text-2xl">✉️</div>
                            <div class="mt-2 font-bold text-slate-900">Email</div>
                        </button>
                    </div>
                    <input type="hidden" id="auth_method" name="auth_method" value="">
                    <div class="flex items-center justify-end gap-3 pt-2">
                        <a href="login.php" class="text-sm font-semibold text-slate-600 hover:text-emerald-700">Already have an account?</a>
                        <button id="authContinue" type="submit" disabled class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition disabled:cursor-not-allowed disabled:opacity-50">Continue</button>
                    </div>
                </div>
            <?php elseif ($old['step'] === 2): ?>
                <div class="space-y-5">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">Basic info</h2>
                        <p class="mt-1 text-sm text-slate-600">Tell us a little about yourself.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Full name</label>
                        <input name="name" type="text" value="<?= e($old['name']) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Email</label>
                        <input name="email" type="email" value="<?= e($old['email']) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Neighborhood</label>
                        <input list="neighborhood-list" name="neighborhood" value="<?= e($old['neighborhood']) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                        <datalist id="neighborhood-list">
                            <?php foreach ($neighborhoodOptions as $item): ?>
                                <option value="<?= e($item) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="flex items-center justify-between gap-3 pt-2">
                        <button type="submit" name="back" value="1" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">Back</button>
                        <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition">Continue</button>
                    </div>
                </div>
            <?php elseif ($old['step'] === 3): ?>
                <div class="space-y-5">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">Secure your account</h2>
                        <p class="mt-1 text-sm text-slate-600">Create a strong password to protect your EcoWallet.</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Password</label>
                        <input id="password" name="password" type="password" value="<?= e($old['password']) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                        <div class="mt-3 h-2 rounded-full bg-slate-200">
                            <div id="password-meter" class="h-2 rounded-full bg-red-500" style="width: 10%"></div>
                        </div>
                        <ul id="password-checks" class="mt-3 space-y-1 text-xs text-slate-600">
                            <li data-check="length">• 8+ characters</li>
                            <li data-check="number">• 1 number</li>
                            <li data-check="special">• 1 special character</li>
                            <li data-check="common">• Not a common password</li>
                        </ul>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Confirm password</label>
                        <input id="confirm" name="confirm" type="password" value="<?= e($old['confirm']) ?>" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                    </div>
                    <label class="flex items-center gap-3 text-sm text-slate-700">
                        <input type="checkbox" name="newsletter" value="1" <?= $old['newsletter'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span>Send me eco updates and community news</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                        <input type="checkbox" name="terms" value="1" <?= $old['terms'] ? 'checked' : '' ?> class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span>I accept the <a href="#" class="font-semibold text-emerald-600 hover:underline">Terms of Service</a> and <a href="#" class="font-semibold text-emerald-600 hover:underline">Privacy Policy</a>.</span>
                    </label>
                    <div class="flex items-center justify-between gap-3 pt-2">
                        <button type="submit" name="back" value="2" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">Back</button>
                        <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition">Continue</button>
                    </div>
                </div>
            <?php elseif ($old['step'] === 4): ?>
                <div class="space-y-5">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">Verify your email</h2>
                        <p class="mt-1 text-sm text-slate-600">We sent a 6-digit code to <span class="font-semibold text-slate-900"><?= e($old['email']) ?></span>.</p>
                    </div>
                    <div class="flex gap-2 justify-center">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text" maxlength="1" class="otp-digit w-12 h-12 rounded-xl text-center text-xl font-bold text-slate-900 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200" inputmode="numeric">
                        <?php endfor; ?>
                    </div>
                    <input type="hidden" name="otp" id="otp-hidden" value="<?= e($old['otp']) ?>">
                    <div class="text-center text-sm text-slate-600">
                        <span>Didn't get it?</span>
                        <button type="button" class="ml-2 font-semibold text-emerald-600 hover:underline">Resend code</button>
                    </div>
                    <div class="flex items-center justify-between gap-3 pt-2">
                        <button type="submit" name="back" value="3" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">Back</button>
                        <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition">Verify</button>
                    </div>
                </div>
            <?php elseif ($old['step'] === 5): ?>
                <div class="space-y-5">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900">Personalize your profile</h2>
                        <p class="mt-1 text-sm text-slate-600">Pick an avatar and interests that match how you recycle.</p>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Choose an avatar</label>
                        <div class="grid grid-cols-4 gap-3">
                            <?php foreach ($avatarOptions as $avatar): ?>
                                <label class="avatar-option cursor-pointer rounded-2xl p-4 text-center text-3xl <?php echo ($old['avatar'] === $avatar) ? 'selected' : ''; ?>">
                                    <input type="radio" name="avatar" value="<?= e($avatar) ?>" <?= ($old['avatar'] === $avatar) ? 'checked' : '' ?> class="sr-only">
                                    <span><?= e($avatar) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-slate-700">Recycling interests</label>
                        <div class="grid grid-cols-2 gap-2">
                            <?php foreach ($interestOptions as $interest): ?>
                                <label class="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">
                                    <input type="checkbox" name="interests[]" value="<?= e($interest) ?>" <?= in_array($interest, $old['interests'], true) ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                                    <span><?= e($interest) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-semibold text-slate-700">Optional referral code</label>
                        <input name="referral_code" type="text" value="<?= e($old['referral_code']) ?>" placeholder="ECO-START" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-200">
                    </div>
                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                        <input type="checkbox" name="terms" value="1" <?= $old['terms'] ? 'checked' : '' ?> class="mt-1 h-4 w-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span>I accept the <a href="#" class="font-semibold text-emerald-600 hover:underline">Terms of Service</a> and <a href="#" class="font-semibold text-emerald-600 hover:underline">Privacy Policy</a>.</span>
                    </label>
                    <div class="flex items-center justify-between gap-3 pt-2">
                        <button type="submit" name="back" value="4" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 transition">Back</button>
                        <button type="submit" class="rounded-xl bg-emerald-600 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 transition">Finish setup</button>
                    </div>
                </div>
            <?php elseif ($old['step'] === 6): ?>
                <div class="space-y-6 text-center">
                    <div class="mx-auto flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 text-5xl">🎉</div>
                    <div>
                        <h2 class="text-3xl font-extrabold text-slate-900">Welcome to EcoCycle</h2>
                        <p class="mt-2 text-slate-600">Your EcoWallet is ready and your welcome bonus is on the way.</p>
                    </div>
                    <div class="eco-summary-card p-5 text-left">
                        <div class="text-sm font-semibold uppercase tracking-wide text-emerald-700">EcoWallet bonus</div>
                        <div class="mt-2 flex items-center justify-between text-xl font-extrabold text-slate-900">
                            <span>+20 pts</span>
                            <span>Welcome bonus</span>
                        </div>
                    </div>
                    <button type="submit" class="rounded-xl bg-emerald-600 px-6 py-3 text-base font-bold text-white hover:bg-emerald-700 transition">Go to my dashboard</button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</section>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const authButtons = document.querySelectorAll('.auth-option');
        const authInput = document.getElementById('auth_method');
        if (authButtons.length && authInput) {
            const authContinue = document.getElementById('authContinue');
            authButtons.forEach(button => {
                button.addEventListener('click', function () {
                    authButtons.forEach(item => {
                        item.classList.remove('selected', 'border-emerald-400', 'bg-emerald-50');
                        item.setAttribute('aria-pressed', 'false');
                    });
                    button.classList.add('selected');
                    button.setAttribute('aria-pressed', 'true');
                    authInput.value = button.dataset.auth;
                    if (authContinue) authContinue.disabled = false;
                });
                button.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        button.click();
                    }
                });
            });
        }

        const password = document.getElementById('password');
        const confirm = document.getElementById('confirm');
        const meter = document.getElementById('password-meter');
        const checks = document.querySelectorAll('[data-check]');
        if (password) {
            password.addEventListener('input', function () {
                const value = password.value;
                let score = 0;
                if (value.length >= 8) { score += 1; }
                if (/\d/.test(value)) { score += 1; }
                if (/[^A-Za-z0-9]/.test(value)) { score += 1; }
                if (!['password', '12345678', 'welcome', 'ecocycle'].includes(value.toLowerCase())) { score += 1; }

                let color = '#ef4444';
                let width = 25;
                if (score >= 2) { color = '#f59e0b'; width = 50; }
                if (score >= 3) { color = '#22c55e'; width = 75; }
                if (score >= 4) { color = '#16a34a'; width = 100; }
                if (meter) { meter.style.width = width + '%'; meter.style.background = color; }

                const rules = {
                    length: value.length >= 8,
                    number: /\d/.test(value),
                    special: /[^A-Za-z0-9]/.test(value),
                    common: !['password', '12345678', 'welcome', 'ecocycle'].includes(value.toLowerCase())
                };
                checks.forEach(item => {
                    const key = item.dataset.check;
                    const valid = rules[key];
                    item.style.color = valid ? '#16a34a' : '#475569';
                    item.textContent = valid ? item.textContent.replace('•', '✓') : item.textContent.replace('✓', '•');
                });
            });
        }

        if (confirm) {
            confirm.addEventListener('input', function () {
                if (confirm.value && password.value !== confirm.value) {
                    confirm.setCustomValidity('Passwords do not match');
                    confirm.style.borderColor = '#ef4444';
                } else {
                    confirm.setCustomValidity('');
                    confirm.style.borderColor = '#a7f3d0';
                }
            });
        }

        const otpInputs = document.querySelectorAll('.otp-digit');
        const otpHidden = document.getElementById('otp-hidden');
        otpInputs.forEach((input, index) => {
            input.addEventListener('input', function () {
                this.value = this.value.replace(/\D/g, '').slice(0, 1);
                if (this.value && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
                const code = Array.from(otpInputs).map(el => el.value).join('');
                if (otpHidden) otpHidden.value = code;
            });
            input.addEventListener('keydown', function (event) {
                if (event.key === 'Backspace' && !this.value && index > 0) {
                    otpInputs[index - 1].focus();
                }
            });
        });
    });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
