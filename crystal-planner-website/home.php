<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_member();
$settings = load_app_settings();
$memberHomeBackground = member_home_background_filename($settings);
$hasMemberHomeBackground = $memberHomeBackground !== '' && linkshell_image_exists($memberHomeBackground);

render_header(t('home.page_title'), $user);
?>
<section class="home-top-grid">
    <article
        class="hero-card profile-hero<?= $hasMemberHomeBackground ? ' has-profile-background' : '' ?>"
        <?= $hasMemberHomeBackground ? 'style="--profile-background-image: url(\'' . e(member_home_background_url($memberHomeBackground)) . '\');"' : '' ?>
    >
        <div class="profile-identity">
            <?php render_user_avatar($user, 'profile-avatar'); ?>
            <div>
                <div class="eyebrow"><?= e(t('home.eyebrow')) ?></div>
                <h1><?= e(user_character_name($user)) ?></h1>
                <?php if (user_character_world($user) !== ''): ?><p class="character-world"><?= e(user_character_world($user)) ?></p><?php endif; ?>
                <p><?= e(t('home.welcome', ['linkshell' => linkshell_name()])) ?></p>
            </div>
        </div>
    </article>

    <article class="content-card home-info-card">
        <h2><?= e(t('home.info.title')) ?></h2>
        <dl class="profile-list">
            <div><dt><?= e(t('home.info.username')) ?></dt><dd><?= e((string)$user['username']) ?></dd></div>
            <div><dt><?= e(t('home.info.character_name')) ?></dt><dd><?= e(user_character_name($user)) ?></dd></div>
            <div><dt><?= e(t('home.info.world')) ?></dt><dd><?= e(user_character_world($user) !== '' ? user_character_world($user) : t('common.not_available')) ?></dd></div>
            <div>
                <dt><?= e(t('home.info.lodestone_id')) ?></dt>
                <dd><a class="text-link" href="<?= e(lodestone_profile_url((string)$user['lodestone_id'])) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)$user['lodestone_id']) ?></a></dd>
            </div>
            <div><dt><?= e(t('home.info.status')) ?></dt><dd><?= e(status_label((string)$user['status'])) ?></dd></div>
        </dl>
    </article>
</section>

<section class="dashboard-grid home-dashboard-grid">
    <article class="content-card">
        <h2><?= e(t('home.my_responses.title')) ?></h2>
        <p class="muted"><?= e(t('home.my_responses.text')) ?></p>
        <a class="text-link" href="<?= e(app_url('events.php')) ?>"><?= e(t('home.my_responses.link')) ?></a>
    </article>
    <article class="content-card">
        <h2><?= e(t('home.members.title')) ?></h2>
        <p class="muted"><?= e(t('home.members.text')) ?></p>
        <a class="text-link" href="<?= e(app_url('members.php')) ?>"><?= e(t('home.members.link')) ?></a>
    </article>
</section>
<?php render_footer(); ?>
