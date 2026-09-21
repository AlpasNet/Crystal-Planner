<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/layout.php';

$user = require_admin();
$data = load_users_data();
$counts = ['admin' => 0, 'member' => 0, 'pending_member' => 0, 'banned' => 0];
foreach ($data['users'] as $registeredUser) {
    $status = (string)($registeredUser['status'] ?? '');
    if (array_key_exists($status, $counts)) $counts[$status]++;
}

render_header(t('admin.dashboard.page_title'), $user);
?>
<section class="hero-card">
    <div>
        <div class="eyebrow"><?= e(t('admin.dashboard.eyebrow')) ?></div>
        <h1><?= e(t('admin.dashboard.heading')) ?></h1>
        <p><?= e(t('admin.dashboard.intro')) ?></p>
    </div>
    <div class="button-row admin-hero-actions">
        <a class="button button-primary" href="<?= e(app_url('admin/events.php')) ?>"><?= e(t('admin.dashboard.events')) ?></a>
        <a class="button button-secondary" href="<?= e(app_url('admin/members.php')) ?>"><?= e(t('admin.dashboard.members')) ?></a>
    </div>
</section>
<section class="stats-grid">
    <article class="stat-card"><span class="stat-number"><?= $counts['pending_member'] ?></span><span><?= e(t('admin.dashboard.pending')) ?></span></article>
    <article class="stat-card"><span class="stat-number"><?= $counts['member'] ?></span><span><?= e(t('admin.dashboard.members_count')) ?></span></article>
    <article class="stat-card"><span class="stat-number"><?= $counts['admin'] ?></span><span><?= e(t('admin.dashboard.admins')) ?></span></article>
    <article class="stat-card"><span class="stat-number"><?= $counts['banned'] ?></span><span><?= e(t('admin.dashboard.banned')) ?></span></article>
</section>
<section class="dashboard-grid">
    <article class="content-card"><h2><?= e(t('admin.dashboard.members_card.title')) ?></h2><p class="muted"><?= e(t('admin.dashboard.members_card.text')) ?></p><a class="text-link" href="<?= e(app_url('admin/members.php')) ?>"><?= e(t('admin.dashboard.members_card.link')) ?></a></article>
    <article class="content-card"><h2><?= e(t('admin.dashboard.jobs_card.title')) ?></h2><p class="muted"><?= e(t('admin.dashboard.jobs_card.text')) ?></p><a class="text-link" href="<?= e(app_url('admin/jobs.php')) ?>"><?= e(t('admin.dashboard.jobs_card.link')) ?></a></article>
    <article class="content-card"><h2><?= e(t('admin.dashboard.events_card.title')) ?></h2><p class="muted"><?= e(t('admin.dashboard.events_card.text')) ?></p><a class="text-link" href="<?= e(app_url('admin/events.php')) ?>"><?= e(t('admin.dashboard.events_card.link')) ?></a></article>
    <article class="content-card"><h2><?= e(t('admin.dashboard.rules_card.title')) ?></h2><p class="muted"><?= e(t('admin.dashboard.rules_card.text')) ?></p><a class="text-link" href="<?= e(app_url('admin/rules.php')) ?>"><?= e(t('admin.dashboard.rules_card.link')) ?></a></article>
    <article class="content-card"><h2><?= e(t('admin.dashboard.guides_card.title')) ?></h2><p class="muted"><?= e(t('admin.dashboard.guides_card.text')) ?></p><a class="text-link" href="<?= e(app_url('admin/guides.php')) ?>"><?= e(t('admin.dashboard.guides_card.link')) ?></a></article>
    <article class="content-card"><h2><?= e(t('admin.dashboard.macros_card.title')) ?></h2><p class="muted"><?= e(t('admin.dashboard.macros_card.text')) ?></p><a class="text-link" href="<?= e(app_url('admin/macros.php')) ?>"><?= e(t('admin.dashboard.macros_card.link')) ?></a></article>
</section>
<?php render_footer(); ?>
