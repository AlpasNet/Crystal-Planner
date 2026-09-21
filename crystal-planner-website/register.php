<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/lodestone_lookup.php';

if (current_user() !== null) {
    redirect_after_login(current_user());
}

$pending = $_SESSION['pending_registration'] ?? null;
if (!is_array($pending) || empty($pending['username']) || empty($pending['password_hash'])) {
    redirect_to('index.php');
}

$error = '';
$lodestoneId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $lodestoneId = trim((string)($_POST['lodestone_id'] ?? ''));

    if (!preg_match('/^\d{1,20}$/', $lodestoneId)) {
        $error = t('register.error.numeric_id');
    } else {
        try {
            $existingData = load_users_data();
            $usernameKey = app_text_lower(trim((string)$pending['username']));

            foreach ($existingData['users'] as $existingUser) {
                if (app_text_lower((string)$existingUser['username']) === $usernameKey) {
                    throw new RuntimeException(t('register.error.username_taken'));
                }
                if ((string)$existingUser['lodestone_id'] === $lodestoneId) {
                    throw new RuntimeException(t('register.error.id_taken'));
                }
            }

            $profile = fetch_lodestone_profile($lodestoneId);
            $newUser = update_users_data(function (array &$data) use ($pending, $lodestoneId, $profile): array {
                $usernameKey = app_text_lower(trim((string)$pending['username']));

                foreach ($data['users'] as $existingUser) {
                    if (app_text_lower((string)$existingUser['username']) === $usernameKey) {
                        throw new RuntimeException(t('register.error.username_taken'));
                    }
                    if ((string)$existingUser['lodestone_id'] === $lodestoneId) {
                        throw new RuntimeException(t('register.error.id_taken'));
                    }
                }

                $id = (int)$data['next_id'];
                $user = [
                    'id' => $id,
                    'username' => trim((string)$pending['username']),
                    'password_hash' => (string)$pending['password_hash'],
                    'lodestone_id' => $lodestoneId,
                    'character_name' => (string)$profile['character_name'],
                    'world' => (string)$profile['world'],
                    'avatar_url' => (string)$profile['avatar_url'],
                    'status' => count($data['users']) === 0 ? 'admin' : 'pending_member',
                ];

                $data['users'][] = $user;
                $data['next_id'] = $id + 1;
                return $user;
            });

            refresh_all_lodestone_profiles([(int)$newUser['id']]);
            $newUser = find_user_by_id((int)$newUser['id']) ?? $newUser;
            login_user((int)$newUser['id']);
            redirect_after_login($newUser);
        } catch (LodestoneProfileException) {
            $error = t('register.error.profile_not_found');
        } catch (LodestoneUnavailableException) {
            $error = t('register.error.unavailable');
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
}

render_header(t('register.page_title'));
?>

<section class="auth-card auth-combined-card">
    <div class="auth-form-column">
        <div class="eyebrow"><?= e(t('register.eyebrow')) ?></div>
        <h1><?= e(t('register.heading')) ?></h1>
        <p class="muted"><?= e(t('register.intro', ['username' => (string)$pending['username']])) ?></p>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <?php render_lodestone_lookup('registration-lodestone-id'); ?>

        <form method="post" class="stack-form">
            <?= csrf_field() ?>
            <label>
                <span><?= e(t('register.lodestone_id')) ?></span>
                <input id="registration-lodestone-id" type="text" name="lodestone_id" value="<?= e($lodestoneId) ?>" inputmode="numeric" pattern="[0-9]+" maxlength="20" autocomplete="off" required autofocus>
            </label>
            <button type="submit" class="button button-primary"><?= e(t('register.submit')) ?></button>
            <a class="button button-secondary" href="<?= e(app_url('cancel-registration.php')) ?>"><?= e(t('common.cancel')) ?></a>
        </form>
    </div>

    <?php render_cookie_information_panel(); ?>
</section>

<?php render_footer(); ?>
