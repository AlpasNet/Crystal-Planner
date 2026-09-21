<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/password_reset_storage.php';
require_once __DIR__ . '/includes/lodestone_lookup.php';

$alreadyConnected = current_user();
if ($alreadyConnected !== null) {
    redirect_after_login($alreadyConnected);
}

$requestError = '';
$requestSuccess = '';
$resetError = '';
$requestUsername = '';
$requestLodestoneId = '';
$resetUsername = '';
$resetCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request_reset') {
        $requestUsername = trim((string)($_POST['username'] ?? ''));
        $requestLodestoneId = trim((string)($_POST['lodestone_id'] ?? ''));

        if ($requestUsername === '' || $requestLodestoneId === '') {
            $requestError = t('password_reset.request.error.required');
        } elseif (app_text_length($requestUsername) < 3 || app_text_length($requestUsername) > 32) {
            $requestError = t('login.error.username_length');
        } elseif (!preg_match('/^\d{1,20}$/', $requestLodestoneId)) {
            $requestError = t('password_reset.request.error.lodestone');
        } else {
            try {
                $user = find_user_by_username($requestUsername);

                if (
                    $user !== null
                    && hash_equals((string)($user['lodestone_id'] ?? ''), $requestLodestoneId)
                ) {
                    request_password_reset_for_user($user);
                }

                // Réponse volontairement identique, que le compte existe ou non.
                $requestSuccess = t('password_reset.request.success');
                $requestUsername = '';
                $requestLodestoneId = '';
            } catch (RuntimeException) {
                $requestError = t('password_reset.error.storage');
            }
        }
    } elseif ($action === 'complete_reset') {
        $resetUsername = trim((string)($_POST['username'] ?? ''));
        $resetCode = normalize_password_reset_code((string)($_POST['reset_code'] ?? ''));
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($resetUsername === '' || $resetCode === '' || $newPassword === '' || $confirmPassword === '') {
            $resetError = t('password_reset.complete.error.required');
        } elseif (app_text_length($resetUsername) < 3 || app_text_length($resetUsername) > 32) {
            $resetError = t('login.error.username_length');
        } elseif (strlen($newPassword) < 8) {
            $resetError = t('login.error.password_length');
        } elseif (strlen($newPassword) > 255) {
            $resetError = t('password_reset.complete.error.password_too_long');
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $resetError = t('password_reset.complete.error.mismatch');
        } else {
            try {
                $user = find_user_by_username($resetUsername);
                $verification = $user === null
                    ? 'invalid'
                    : verify_password_reset_code((int)$user['id'], $resetCode);

                if ($verification === 'expired') {
                    $resetError = t('password_reset.complete.error.expired');
                } elseif ($verification !== 'valid' || $user === null) {
                    $resetError = t('password_reset.complete.error.invalid_code');
                } else {
                    $userId = (int)$user['id'];
                    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

                    update_users_data(function (array &$data) use ($userId, $passwordHash): void {
                        foreach ($data['users'] as &$storedUser) {
                            if ((int)($storedUser['id'] ?? 0) !== $userId) {
                                continue;
                            }

                            $storedUser['password_hash'] = $passwordHash;
                            // Un changement de mot de passe révoque toutes les connexions persistantes existantes.
                            unset($storedUser['persistent_logins']);
                            return;
                        }

                        throw new RuntimeException(t('admin.members.error.user_not_found'));
                    });

                    delete_password_reset_request($userId);
                    $_SESSION['flash_success'] = t('password_reset.complete.success');
                    redirect_to('index.php');
                }
            } catch (RuntimeException) {
                $resetError = t('password_reset.error.storage');
            }
        }
    }
}

render_header(t('password_reset.page_title'));
?>

<section class="page-heading password-reset-heading">
    <div>
        <div class="eyebrow"><?= e(t('password_reset.eyebrow')) ?></div>
        <h1><?= e(t('password_reset.heading')) ?></h1>
        <p class="muted"><?= e(t('password_reset.intro')) ?></p>
    </div>
    <a class="button button-secondary" href="<?= e(app_url('index.php')) ?>"><?= e(t('password_reset.back_login')) ?></a>
</section>

<div class="password-reset-grid">
    <section class="content-card password-reset-card">
        <div class="password-reset-step">1</div>
        <h2><?= e(t('password_reset.request.heading')) ?></h2>
        <p class="muted"><?= e(t('password_reset.request.intro')) ?></p>

        <?php if ($requestSuccess !== ''): ?>
            <div class="alert alert-success" role="status"><?= e($requestSuccess) ?></div>
        <?php endif; ?>

        <?php if ($requestError !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($requestError) ?></div>
        <?php endif; ?>

        <?php render_lodestone_lookup('password-reset-lodestone-id'); ?>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="request_reset">

            <label>
                <span><?= e(t('login.username')) ?></span>
                <input type="text" name="username" value="<?= e($requestUsername) ?>" minlength="3" maxlength="32" autocomplete="username" required>
            </label>

            <label>
                <span><?= e(t('admin.members.lodestone_id')) ?></span>
                <input id="password-reset-lodestone-id" type="text" name="lodestone_id" value="<?= e($requestLodestoneId) ?>" inputmode="numeric" pattern="[0-9]+" maxlength="20" required>
            </label>

            <button type="submit" class="button button-primary"><?= e(t('password_reset.request.submit')) ?></button>
        </form>
    </section>

    <section class="content-card password-reset-card">
        <div class="password-reset-step">2</div>
        <h2><?= e(t('password_reset.complete.heading')) ?></h2>
        <p class="muted"><?= e(t('password_reset.complete.intro')) ?></p>

        <?php if ($resetError !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($resetError) ?></div>
        <?php endif; ?>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="complete_reset">

            <label>
                <span><?= e(t('login.username')) ?></span>
                <input type="text" name="username" value="<?= e($resetUsername) ?>" minlength="3" maxlength="32" autocomplete="username" required>
            </label>

            <label>
                <span><?= e(t('password_reset.complete.code')) ?></span>
                <input class="reset-code-input" type="text" name="reset_code" value="<?= e($resetCode) ?>" minlength="10" maxlength="14" autocomplete="one-time-code" spellcheck="false" required>
            </label>

            <label>
                <span><?= e(t('password_reset.complete.new_password')) ?></span>
                <input type="password" name="new_password" minlength="8" maxlength="255" autocomplete="new-password" required>
            </label>

            <label>
                <span><?= e(t('password_reset.complete.confirm_password')) ?></span>
                <input type="password" name="confirm_password" minlength="8" maxlength="255" autocomplete="new-password" required>
            </label>

            <button type="submit" class="button button-primary"><?= e(t('password_reset.complete.submit')) ?></button>
        </form>
    </section>
</div>

<?php render_footer(); ?>
