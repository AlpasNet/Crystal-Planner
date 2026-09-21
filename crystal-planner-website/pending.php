<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
if ($user['status'] !== 'pending_member') {
    redirect_after_login($user);
}

render_header(t('pending.page_title'), $user);
?>
<section class="content-card narrow-card">
    <div class="centered-identity">
        <?php render_user_avatar($user, 'profile-avatar profile-avatar-small'); ?>
        <div>
            <strong><?= e(user_character_name($user)) ?></strong>
            <?php if (user_character_world($user) !== ''): ?><span><?= e(user_character_world($user)) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="status-icon" aria-hidden="true">⏳</div>
    <div class="eyebrow"><?= e(t('pending.eyebrow')) ?></div>
    <h1><?= e(t('pending.heading')) ?></h1>
    <p><?= e(t('pending.message', ['username' => (string)$user['username']])) ?></p>
    <p class="muted"><?= e(t('pending.help')) ?></p>
    <div class="button-row">
        <a class="button button-primary" href="<?= e(app_url('pending.php')) ?>"><?= e(t('pending.refresh')) ?></a>
        <a class="button button-secondary" href="<?= e(app_url('logout.php')) ?>"><?= e(t('pending.logout')) ?></a>
    </div>
</section>
<?php render_footer(); ?>
