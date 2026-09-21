<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/layout.php';

$alreadyConnected = current_user();
if ($alreadyConnected !== null) {
    redirect_after_login($alreadyConnected);
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = t('login.error.required');
    } elseif (app_text_length($username) < 3 || app_text_length($username) > 32) {
        $error = t('login.error.username_length');
    } elseif (strlen($password) < 8) {
        $error = t('login.error.password_length');
    } else {
        $user = find_user_by_username($username);

        if ($user !== null) {
            if (!password_verify($password, (string)$user['password_hash'])) {
                $error = t('login.error.invalid_credentials');
            } else {
                refresh_all_lodestone_profiles();
                $user = find_user_by_id((int)$user['id']) ?? $user;
                login_user((int)$user['id']);
                redirect_after_login($user);
            }
        } else {
            $_SESSION['pending_registration'] = [
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ];

            redirect_to('register.php');
        }
    }
}

render_header(t('login.page_title'));
?>

<section class="auth-card auth-combined-card">
    <div class="auth-form-column">
        <div class="eyebrow"><?= e(t('login.eyebrow')) ?></div>
        <h1><?= e(t('login.heading')) ?></h1>
        <p class="muted"><?= e(t('login.intro')) ?></p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>

            <label>
                <span><?= e(t('login.username')) ?></span>
                <input type="text" name="username" value="<?= e($username) ?>" minlength="3" maxlength="32" autocomplete="username" required autofocus>
            </label>

            <label>
                <span><?= e(t('login.password')) ?></span>
                <input type="password" name="password" minlength="8" autocomplete="current-password" required>
            </label>

            <button type="submit" class="button button-primary"><?= e(t('login.continue')) ?></button>
        </form>

        <div class="auth-help-link">
            <a href="<?= e(app_url('forgot-password.php')) ?>"><?= e(t('login.forgot_password')) ?></a>
        </div>
    </div>

    <?php render_cookie_information_panel(); ?>
</section>

<?php render_footer(); ?>
