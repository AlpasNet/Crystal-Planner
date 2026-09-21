<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_member();
$data = load_users_data();
$members = array_values(array_filter(
    $data['users'],
    static fn(array $registeredUser): bool => in_array((string)($registeredUser['status'] ?? ''), ['member', 'admin'], true)
));
usort($members, static function (array $left, array $right): int {
    $nameCompare = strcasecmp(user_character_name($left), user_character_name($right));
    return $nameCompare !== 0 ? $nameCompare : ((int)$left['id'] <=> (int)$right['id']);
});

render_header(t('members.page_title'), $user);
?>
<section class="page-heading linkshell-members-heading">
    <div>
        <div class="eyebrow"><?= e(t('common.linkshell')) ?></div>
        <h1><?= e(t('members.heading', ['linkshell' => linkshell_name()])) ?></h1>
        <p class="muted"><?= e(t('members.intro')) ?></p>
    </div>
    <div class="page-heading-actions page-heading-actions-stacked">
        <a class="button button-secondary" href="<?= e(app_url('home.php')) ?>"><?= e(t('common.back_home')) ?></a>
        <span class="members-count"><?= e(tp('members.count.one', 'members.count.other', count($members))) ?></span>
    </div>
</section>

<?php if ($members === []): ?>
    <section class="content-card member-events-empty">
        <h2><?= e(t('members.empty.title')) ?></h2>
        <p class="muted"><?= e(t('members.empty.text')) ?></p>
    </section>
<?php else: ?>
    <section class="member-directory" aria-label="<?= e(t('members.list_aria')) ?>">
        <div class="member-directory-header" aria-hidden="true">
            <span><?= e(t('common.player')) ?></span>
            <span><?= e(t('common.world')) ?></span>
            <span><?= e(t('common.role')) ?></span>
            <span><?= e(t('common.profile')) ?></span>
        </div>
        <div class="member-directory-list">
            <?php foreach ($members as $listedMember): ?>
                <article class="member-directory-row">
                    <div class="member-directory-identity">
                        <?php render_user_avatar($listedMember, 'member-directory-avatar'); ?>
                        <div><strong><?= e(user_character_name($listedMember)) ?></strong></div>
                    </div>
                    <div class="member-directory-world" data-label="<?= e(t('common.world')) ?>"><?= e(user_character_world($listedMember) !== '' ? user_character_world($listedMember) : t('common.not_available')) ?></div>
                    <div class="member-directory-role" data-label="<?= e(t('common.role')) ?>">
                        <span class="member-role-text <?= (string)$listedMember['status'] === 'admin' ? 'is-admin' : 'is-member' ?>"><?= e((string)$listedMember['status'] === 'admin' ? t('common.administrator') : t('common.member')) ?></span>
                    </div>
                    <div class="member-directory-action">
                        <a class="button button-secondary button-small" href="<?= e(lodestone_profile_url((string)$listedMember['lodestone_id'])) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('members.lodestone')) ?></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php render_footer(); ?>
