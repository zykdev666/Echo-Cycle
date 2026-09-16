<?php
/**
 * EcoCycle — login.
 */
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $error = 'Your session expired. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            loginUser((int) $user['id']);
            setFlash('success', 'Welcome back, ' . $user['name'] . '!');
            header('Location: dashboard.php');
            exit;
        }
        $error = 'Incorrect email or password.';
    }
}

$pageTitle = 'Log in';
require __DIR__ . '/includes/header.php';
?>
<section class="max-w-md mx-auto px-4 py-12">
    <div class="text-center">
        <h1 class="text-3xl font-extrabold text-slate-900">Welcome back 👋</h1>
        <p class="mt-2 text-slate-600">Log in to keep your streak going.</p>
    </div>

    <?php if ($error): ?>
        <div class="mt-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm" role="alert"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" class="mt-6 bg-white rounded-2xl shadow-sm border border-eco-100 p-6 space-y-4" novalidate>
        <?= csrfField() ?>
        <div>
            <label for="email" class="block text-sm font-semibold text-slate-700">Email</label>
            <input id="email" name="email" type="email" required value="<?= e($email) ?>"
                   class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
        </div>
        <div>
            <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
            <input id="password" name="password" type="password" required
                   class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 focus:border-eco-500 focus:ring-eco-500">
        </div>
        <button type="submit" class="w-full py-3 rounded-xl bg-eco-600 text-white font-bold hover:bg-eco-700 transition">Log in</button>
    </form>

    <p class="mt-4 text-center text-sm text-slate-600">
        New here? <a href="register.php" class="font-semibold text-eco-700 hover:underline">Create an account</a>
    </p>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
