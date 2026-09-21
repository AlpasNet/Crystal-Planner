<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/event_responses_storage.php';
require_once __DIR__ . '/../includes/password_reset_storage.php';

$admin = require_admin();
$success = '';
$error = '';
$generatedResetCode = '';
$generatedResetUsername = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? 'update_member');

    if ($action === 'update_linkshell') {
        $newName = trim((string)($_POST['linkshell_name'] ?? ''));
        $newDiscordUrlInput = trim((string)($_POST['linkshell_discord_url'] ?? ''));
        $newDiscordUrl = normalize_linkshell_discord_url($newDiscordUrlInput);
        $newAvatar = null;
        $newHomeBackground = null;

        if ($newName === '') {
            $error = t('admin.members.error.name_required');
        } elseif (app_text_length($newName) > 60) {
            $error = t('admin.members.error.name_length');
        } elseif ($newDiscordUrlInput !== '' && $newDiscordUrl === '') {
            $error = t('admin.members.error.discord_url_invalid');
        } else {
            try {
                $currentSettings = load_app_settings();
                $oldAvatar = linkshell_avatar_filename($currentSettings);
                $oldHomeBackground = member_home_background_filename($currentSettings);
                $newAvatar = save_linkshell_image_upload($_FILES['linkshell_avatar'] ?? null);
                $newHomeBackground = save_member_home_background_upload($_FILES['member_home_background'] ?? null);

                $savedImages = update_app_settings(
                    function (array &$settings) use (
                        $newName,
                        $newDiscordUrl,
                        $newAvatar,
                        $newHomeBackground
                    ): array {
                        $settings['linkshell_name'] = $newName;
                        $settings['linkshell_discord_url'] = $newDiscordUrl;

                        if ($newAvatar !== null) {
                            $settings['linkshell_avatar'] = $newAvatar;
                        }

                        if ($newHomeBackground !== null) {
                            $settings['member_home_background'] = $newHomeBackground;
                        }

                        return [
                            'linkshell_avatar' => (string)$settings['linkshell_avatar'],
                            'member_home_background' => (string)$settings['member_home_background'],
                        ];
                    }
                );

                if (
                    $oldAvatar !== ''
                    && (($savedImages['linkshell_avatar'] ?? '') !== $oldAvatar)
                ) {
                    delete_linkshell_image_file($oldAvatar);
                }

                if (
                    $oldHomeBackground !== ''
                    && (($savedImages['member_home_background'] ?? '') !== $oldHomeBackground)
                ) {
                    delete_linkshell_image_file($oldHomeBackground);
                }

                $success = t('admin.members.success.linkshell');
            } catch (RuntimeException $exception) {
                if ($newAvatar !== null) {
                    delete_linkshell_image_file($newAvatar);
                }

                if ($newHomeBackground !== null) {
                    delete_linkshell_image_file($newHomeBackground);
                }

                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'delete_linkshell_avatar') {
        try {
            $deletedAvatar = update_app_settings(function (array &$settings): string {
                $currentAvatar = linkshell_avatar_filename($settings);
                $settings['linkshell_avatar'] = '';

                return $currentAvatar;
            });

            if ($deletedAvatar !== '') {
                delete_linkshell_image_file($deletedAvatar);
            }

            $success = t('admin.members.success.avatar_deleted');
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'delete_member_home_background') {
        try {
            $deletedCover = update_app_settings(function (array &$settings): string {
                $currentCover = member_home_background_filename($settings);
                $settings['member_home_background'] = '';

                return $currentCover;
            });

            if ($deletedCover !== '') {
                delete_linkshell_image_file($deletedCover);
            }

            $success = t('admin.members.success.cover_deleted');
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    } elseif ($action === 'update_member') {
        $targetId = (int)($_POST['user_id'] ?? 0);
        $newStatus = (string)($_POST['status'] ?? '');
        $newLodestoneId = trim((string)($_POST['lodestone_id'] ?? ''));

        if ($targetId <= 0 || !in_array($newStatus, USER_STATUSES, true)) {
            $error = t('admin.members.error.invalid_update');
        } elseif (!preg_match('/^\d{1,20}$/', $newLodestoneId)) {
            $error = t('admin.members.error.numeric_id');
        } else {
            try {
                $targetUser = find_user_by_id($targetId);

                if ($targetUser === null) {
                    throw new RuntimeException(t('admin.members.error.user_not_found'));
                }

                $lodestoneChanged = (string)$targetUser['lodestone_id'] !== $newLodestoneId;
                $profile = null;

                try {
                    $profile = fetch_lodestone_profile($newLodestoneId);
                } catch (Throwable $exception) {
                    if ($lodestoneChanged) {
                        throw $exception;
                    }

                    // Une panne du Lodestone ne doit pas bloquer un simple changement de statut.
                    $profile = null;
                }

                $updatedUsername = update_users_data(
                    function (array &$data) use (
                        $targetId,
                        $newStatus,
                        $newLodestoneId,
                        $admin,
                        $profile
                    ): string {
                        $targetIndex = null;

                        foreach ($data['users'] as $index => $user) {
                            if ((int)$user['id'] === $targetId) {
                                $targetIndex = $index;
                                break;
                            }
                        }

                        if ($targetIndex === null) {
                            throw new RuntimeException(t('admin.members.error.user_not_found'));
                        }

                        $currentStatus = (string)$data['users'][$targetIndex]['status'];
                        $statusIsProtected = $targetId === 1 || $targetId === (int)$admin['id'];

                        if ($statusIsProtected && $newStatus !== $currentStatus) {
                            throw new RuntimeException(t('admin.members.error.status_protected'));
                        }

                        foreach ($data['users'] as $user) {
                            if (
                                (int)$user['id'] !== $targetId
                                && (string)$user['lodestone_id'] === $newLodestoneId
                            ) {
                                throw new RuntimeException(t('admin.members.error.id_taken'));
                            }
                        }

                        $data['users'][$targetIndex]['lodestone_id'] = $newLodestoneId;
                        $data['users'][$targetIndex]['status'] = $newStatus;

                        if (is_array($profile)) {
                            apply_lodestone_profile($data['users'][$targetIndex], $profile);
                        }

                        return (string)$data['users'][$targetIndex]['username'];
                    }
                );

                $success = t('admin.members.success.updated', ['username' => $updatedUsername]);
            } catch (LodestoneProfileException $exception) {
                $error = t('admin.members.error.profile_not_found');
            } catch (LodestoneUnavailableException $exception) {
                $error = t('admin.members.error.lodestone_unavailable');
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'generate_password_reset') {
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($targetId <= 0) {
            $error = t('admin.members.password_reset.error.invalid_user');
        } else {
            try {
                $targetUser = find_user_by_id($targetId);

                if ($targetUser === null) {
                    throw new RuntimeException(t('admin.members.error.user_not_found'));
                }

                $generatedResetCode = generate_password_reset_code($targetId);
                $generatedResetUsername = (string)$targetUser['username'];
                $success = t('admin.members.password_reset.success.generated', [
                    'username' => $generatedResetUsername,
                ]);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'delete_password_reset') {
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($targetId <= 0) {
            $error = t('admin.members.password_reset.error.invalid_user');
        } else {
            try {
                delete_password_reset_request($targetId);
                $success = t('admin.members.password_reset.success.deleted');
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
    } elseif ($action === 'delete_member') {
        $targetId = (int)($_POST['user_id'] ?? 0);

        if ($targetId <= 0) {
            $error = t('admin.members.error.invalid_member');
        } elseif ($targetId === 1) {
            $error = t('admin.members.error.primary_delete');
        } elseif ($targetId === (int)$admin['id']) {
            $error = t('admin.members.error.self_delete');
        } else {
            try {
                $deletedUser = update_users_data(function (array &$data) use ($targetId): array {
                    $deleted = null;
                    $remainingUsers = [];

                    foreach ($data['users'] as $user) {
                        if ((int)($user['id'] ?? 0) === $targetId) {
                            $deleted = $user;
                            continue;
                        }

                        $remainingUsers[] = $user;
                    }

                    if ($deleted === null) {
                        throw new RuntimeException(t('admin.members.error.user_not_found'));
                    }

                    $data['users'] = array_values($remainingUsers);
                    return $deleted;
                });

                delete_responses_for_user($targetId);
                delete_password_reset_request($targetId);

                $deletedName = user_character_name($deletedUser);
                $success = t('admin.members.success.deleted', ['name' => $deletedName]);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
    } else {
        $error = t('admin.members.error.unknown_action');
    }
}

$settings = load_app_settings();
$linkshellAvatar = linkshell_avatar_filename($settings);
$hasLinkshellAvatarPreview = $linkshellAvatar !== '' && linkshell_image_exists($linkshellAvatar);
$memberHomeBackground = member_home_background_filename($settings);
$hasMemberHomeBackgroundPreview = $memberHomeBackground !== '' && linkshell_image_exists($memberHomeBackground);
$data = load_users_data();
$users = $data['users'];

usort($users, static function (array $a, array $b): int {
    $priority = ['pending_member' => 0, 'member' => 1, 'admin' => 2, 'banned' => 3];
    $statusCompare = ($priority[$a['status']] ?? 9) <=> ($priority[$b['status']] ?? 9);

    return $statusCompare !== 0
        ? $statusCompare
        : strcasecmp((string)$a['username'], (string)$b['username']);
});

$passwordResetRequests = [];
foreach (list_password_reset_requests() as $resetRequest) {
    $requestUser = find_user_by_id((int)($resetRequest['user_id'] ?? 0));

    if ($requestUser === null) {
        continue;
    }

    $resetRequest['user'] = $requestUser;
    $passwordResetRequests[] = $resetRequest;
}

render_header(t('admin.members.page_title'), $admin);
?>

<section class="page-heading">
    <div>
        <div class="eyebrow"><?= e(t('common.administration')) ?></div>
        <h1><?= e(t('admin.members.heading')) ?></h1>
        <p class="muted">
            <?= e(t('admin.members.intro')) ?>
        </p>
    </div>
    <a class="button button-secondary" href="<?= e(app_url('admin/index.php')) ?>"><?= e(t('common.back_admin_home')) ?></a>
</section>

<?php if ($success !== ''): ?>
    <div class="alert alert-success" role="status"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="linkshell-settings-card">
    <header class="linkshell-settings-header">
        <div class="linkshell-avatar-preview" data-linkshell-avatar-preview>
            <?php render_linkshell_avatar($settings, 'linkshell-settings-avatar'); ?>
            <img
                class="linkshell-settings-avatar linkshell-avatar-preview-image"
                data-linkshell-avatar-preview-image
                alt="<?= e(t('admin.members.linkshell_avatar_preview_alt')) ?>"
                hidden
            >
        </div>

        <div class="linkshell-identity-copy">
            <div class="eyebrow"><?= e(t('admin.members.linkshell_identity')) ?></div>
            <h2><?= e(linkshell_name($settings)) ?></h2>
            <p class="muted"><?= e(t('admin.members.linkshell_help')) ?></p>
        </div>
    </header>

    <form method="post" enctype="multipart/form-data" class="linkshell-settings-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_linkshell">

        <div class="linkshell-settings-fields">
            <label>
                <span><?= e(t('admin.members.linkshell_name')) ?></span>
                <input
                    type="text"
                    name="linkshell_name"
                    value="<?= e(linkshell_name($settings)) ?>"
                    maxlength="60"
                    required
                >
            </label>

            <label>
                <span><?= e(t('admin.members.discord_url')) ?></span>
                <input
                    type="url"
                    name="linkshell_discord_url"
                    value="<?= e(linkshell_discord_url($settings)) ?>"
                    maxlength="500"
                    placeholder="https://discord.gg/..."
                    inputmode="url"
                >
                <small class="field-help"><?= e(t('admin.members.discord_url_help')) ?></small>
            </label>
        </div>

        <div class="linkshell-media-grid">
            <section class="linkshell-media-card">
                <div class="linkshell-media-card-header">
                    <div>
                        <div class="linkshell-media-title"><?= e(t('admin.members.linkshell_avatar')) ?></div>
                        <div class="linkshell-media-note"><?= e(t('admin.members.linkshell_avatar_preview_alt')) ?></div>
                    </div>
                    <button
                        class="button button-small button-danger"
                        type="submit"
                        form="delete-linkshell-avatar-form"
                        <?= $hasLinkshellAvatarPreview ? '' : 'disabled' ?>
                    ><?= e(t('admin.members.delete_avatar')) ?></button>
                </div>

                <label class="linkshell-media-input">
                    <span><?= e(t('file.choose')) ?></span>
                    <?php render_file_input('linkshell_avatar', 'image/png,image/jpeg,image/webp'); ?>
                </label>
            </section>

            <section class="linkshell-media-card">
                <div class="linkshell-media-card-header">
                    <div>
                        <div class="linkshell-media-title"><?= e(t('admin.members.member_home_background')) ?></div>
                        <div class="linkshell-media-note"><?= e(t('admin.members.member_home_background_preview')) ?></div>
                    </div>
                    <button
                        class="button button-small button-danger"
                        type="submit"
                        form="delete-member-home-background-form"
                        <?= $hasMemberHomeBackgroundPreview ? '' : 'disabled' ?>
                    ><?= e(t('admin.members.delete_cover')) ?></button>
                </div>

                <span
                    class="member-background-preview<?= $hasMemberHomeBackgroundPreview ? ' is-visible' : '' ?>"
                    data-member-background-preview
                >
                    <img
                        class="member-background-preview-image"
                        data-member-background-preview-image
                        <?= $hasMemberHomeBackgroundPreview ? 'src="' . e(member_home_background_url($memberHomeBackground)) . '"' : '' ?>
                        alt="<?= e(t('admin.members.member_home_background_preview_alt')) ?>"
                        <?= $hasMemberHomeBackgroundPreview ? '' : 'hidden' ?>
                    >
                </span>

                <label class="linkshell-media-input">
                    <span><?= e(t('file.choose')) ?></span>
                    <?php render_file_input('member_home_background', 'image/png,image/jpeg,image/webp'); ?>
                </label>
            </section>
        </div>

        <div class="linkshell-settings-actions">
            <button class="button button-primary" type="submit"><?= e(t('admin.members.save_linkshell')) ?></button>
        </div>
    </form>

    <form id="delete-linkshell-avatar-form" method="post" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_linkshell_avatar">
    </form>

    <form id="delete-member-home-background-form" method="post" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete_member_home_background">
    </form>
</section>

<section class="content-card password-reset-admin-card">
    <div class="members-section-heading">
        <div>
            <div class="eyebrow"><?= e(t('admin.members.password_reset.eyebrow')) ?></div>
            <h2><?= e(t('admin.members.password_reset.heading')) ?></h2>
            <p class="muted"><?= e(t('admin.members.password_reset.intro')) ?></p>
        </div>
        <span class="members-count"><?= e(tp('admin.members.password_reset.count.one', 'admin.members.password_reset.count.other', count($passwordResetRequests))) ?></span>
    </div>

    <?php if ($generatedResetCode !== ''): ?>
        <div class="alert alert-success password-reset-admin-code" role="status">
            <span><?= e(t('admin.members.password_reset.code_for', ['username' => $generatedResetUsername])) ?></span>
            <code><?= e($generatedResetCode) ?></code>
            <small><?= e(t('admin.members.password_reset.code_warning')) ?></small>
        </div>
    <?php endif; ?>

    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th><?= e(t('admin.members.account')) ?></th>
                    <th><?= e(t('admin.members.character')) ?></th>
                    <th><?= e(t('admin.members.password_reset.requested_at')) ?></th>
                    <th><?= e(t('common.status')) ?></th>
                    <th><?= e(t('admin.members.action')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($passwordResetRequests as $resetRequest): ?>
                <?php
                $requestUser = $resetRequest['user'];
                $requestUserId = (int)$requestUser['id'];
                $expiresTimestamp = password_reset_timestamp((string)($resetRequest['expires_at'] ?? ''));
                $hasCode = trim((string)($resetRequest['code_hash'] ?? '')) !== '';
                $codeIsActive = $hasCode && $expiresTimestamp > time();
                ?>
                <tr>
                    <td>
                        <strong><?= e((string)$requestUser['username']) ?></strong>
                        <span class="small-note">#<?= $requestUserId ?></span>
                    </td>
                    <td>
                        <div class="member-character">
                            <?php render_user_avatar($requestUser, 'member-avatar'); ?>
                            <span>
                                <strong><?= e(user_character_name($requestUser)) ?></strong>
                                <small><?= e(user_character_world($requestUser)) ?></small>
                            </span>
                        </div>
                    </td>
                    <td>
                        <div class="password-reset-request-meta">
                            <strong><?= e(date('d/m/Y H:i', password_reset_timestamp((string)$resetRequest['requested_at']))) ?></strong>
                            <small><?= e((string)$requestUser['lodestone_id']) ?></small>
                        </div>
                    </td>
                    <td>
                        <?php if ($codeIsActive): ?>
                            <span class="status-badge status-member"><?= e(t('admin.members.password_reset.status.active')) ?></span>
                            <span class="small-note"><?= e(t('admin.members.password_reset.expires_at', ['date' => date('d/m/Y H:i', $expiresTimestamp)])) ?></span>
                        <?php elseif ($hasCode): ?>
                            <span class="status-badge status-banned"><?= e(t('admin.members.password_reset.status.expired')) ?></span>
                        <?php else: ?>
                            <span class="status-badge status-pending_member"><?= e(t('admin.members.password_reset.status.pending')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="member-admin-actions">
                            <form method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="generate_password_reset">
                                <input type="hidden" name="user_id" value="<?= $requestUserId ?>">
                                <button class="button button-small" type="submit">
                                    <?= e($hasCode ? t('admin.members.password_reset.regenerate') : t('admin.members.password_reset.generate')) ?>
                                </button>
                            </form>

                            <form method="post" class="inline-form" onsubmit="return confirm('<?= e(t('admin.members.password_reset.delete_confirm')) ?>');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_password_reset">
                                <input type="hidden" name="user_id" value="<?= $requestUserId ?>">
                                <button class="button button-small button-danger" type="submit"><?= e(t('admin.members.password_reset.reject')) ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($passwordResetRequests === []): ?>
                <tr>
                    <td colspan="5" class="empty-state"><?= e(t('admin.members.password_reset.empty')) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="members-section-heading">
    <div>
        <h2><?= e(t('admin.members.registered_heading')) ?></h2>
        <p class="muted">
            <?= e(t('admin.members.registered_help')) ?>
        </p>
    </div>
    <span class="members-count"><?= e(tp('admin.members.accounts.one', 'admin.members.accounts.other', count($users))) ?></span>
</section>

<div class="table-card">
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th><?= e(t('admin.members.account')) ?></th>
                    <th><?= e(t('admin.members.character')) ?></th>
                    <th><?= e(t('admin.members.lodestone_id')) ?></th>
                    <th><?= e(t('common.status')) ?></th>
                    <th><?= e(t('admin.members.action')) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $listedUser): ?>
                <?php
                $listedId = (int)$listedUser['id'];
                $isPrimaryAdmin = $listedId === 1;
                $isSelf = $listedId === (int)$admin['id'];
                $statusIsProtected = $isPrimaryAdmin || $isSelf;
                $formId = 'member-form-' . $listedId;
                ?>
                <tr>
                    <td>#<?= $listedId ?></td>
                    <td>
                        <strong><?= e((string)$listedUser['username']) ?></strong>
                        <?php if ($isPrimaryAdmin): ?>
                            <span class="small-note"><?= e(t('admin.members.primary_account')) ?></span>
                        <?php elseif ($isSelf): ?>
                            <span class="small-note"><?= e(t('admin.members.your_account')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="member-character">
                            <?php render_user_avatar($listedUser, 'member-avatar'); ?>
                            <span>
                                <strong><?= e(user_character_name($listedUser)) ?></strong>
                                <small>
                                    <?= e(user_character_world($listedUser) !== '' ? user_character_world($listedUser) : t('admin.members.data_refresh')) ?>
                                </small>
                            </span>
                        </div>
                    </td>
                    <td>
                        <div class="lodestone-field">
                            <input
                                class="table-input"
                                type="text"
                                name="lodestone_id"
                                value="<?= e((string)$listedUser['lodestone_id']) ?>"
                                inputmode="numeric"
                                pattern="[0-9]+"
                                maxlength="20"
                                aria-label="<?= e(t('admin.members.lodestone_aria', ['username' => (string)$listedUser['username']])) ?>"
                                form="<?= e($formId) ?>"
                                required
                            >
                            <a
                                class="small-link"
                                href="<?= e(lodestone_profile_url((string)$listedUser['lodestone_id'])) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                            ><?= e(t('admin.members.view_profile')) ?></a>
                        </div>
                    </td>
                    <td>
                        <?php if ($statusIsProtected): ?>
                            <span class="status-badge status-<?= e((string)$listedUser['status']) ?>">
                                <?= e(status_label((string)$listedUser['status'])) ?>
                            </span>
                            <span class="small-note"><?= e(t('admin.members.status_protected')) ?></span>
                        <?php else: ?>
                            <select
                                name="status"
                                aria-label="<?= e(t('admin.members.status_aria', ['username' => (string)$listedUser['username']])) ?>"
                                form="<?= e($formId) ?>"
                            >
                                <option value="pending_member" <?= $listedUser['status'] === 'pending_member' ? 'selected' : '' ?>>
                                    <?= e(t('auth.status.pending_member')) ?>
                                </option>
                                <option value="member" <?= $listedUser['status'] === 'member' ? 'selected' : '' ?>>
                                    <?= e(t('auth.status.member')) ?>
                                </option>
                                <option value="admin" <?= $listedUser['status'] === 'admin' ? 'selected' : '' ?>>
                                    <?= e(t('auth.status.admin')) ?>
                                </option>
                                <option value="banned" <?= $listedUser['status'] === 'banned' ? 'selected' : '' ?>>
                                    <?= e(t('auth.status.banned')) ?>
                                </option>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="member-admin-actions">
                            <form id="<?= e($formId) ?>" method="post" class="inline-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_member">
                                <input type="hidden" name="user_id" value="<?= $listedId ?>">

                                <?php if ($statusIsProtected): ?>
                                    <input type="hidden" name="status" value="<?= e((string)$listedUser['status']) ?>">
                                <?php endif; ?>

                                <button class="button button-small" type="submit"><?= e(t('admin.members.save')) ?></button>
                            </form>

                            <?php if (!$statusIsProtected): ?>
                                <form
                                    method="post"
                                    class="inline-form"
                                    onsubmit="return confirm('<?= e(t('admin.members.delete_confirm')) ?>');"
                                >
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_member">
                                    <input type="hidden" name="user_id" value="<?= $listedId ?>">
                                    <button class="button button-small button-danger" type="submit"><?= e(t('admin.members.delete')) ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if ($users === []): ?>
                <tr>
                    <td colspan="6" class="empty-state"><?= e(t('admin.members.empty')) ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
