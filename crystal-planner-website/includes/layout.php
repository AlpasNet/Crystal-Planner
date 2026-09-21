<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings_storage.php';
require_once __DIR__ . '/linkshell_image.php';
require_once __DIR__ . '/events_storage.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function user_landing_path(?array $user): string
{
    if ($user === null) {
        return 'index.php';
    }

    return match ((string)($user['status'] ?? '')) {
        'admin' => 'admin/index.php',
        'member' => 'home.php',
        'banned' => 'banned.php',
        default => 'pending.php',
    };
}

function user_character_name(array $user): string
{
    $name = trim((string)($user['character_name'] ?? ''));
    return $name !== '' ? $name : (string)($user['username'] ?? t('common.player'));
}

function user_character_world(array $user): string
{
    return trim((string)($user['world'] ?? ''));
}

function render_user_avatar(array $user, string $className = 'user-avatar'): void
{
    $avatar = trim((string)($user['avatar_url'] ?? ''));
    $name = user_character_name($user);

    if ($avatar !== '') {
        ?>
        <img
            class="<?= e($className) ?>"
            src="<?= e($avatar) ?>"
            alt="<?= e(t('aria.user_avatar', ['name' => $name])) ?>"
            loading="lazy"
            referrerpolicy="no-referrer"
        >
        <?php
        return;
    }

    ?>
    <span class="<?= e($className) ?> avatar-placeholder" aria-hidden="true"><?= e(app_text_initial($name)) ?></span>
    <?php
}

function render_linkshell_avatar(
    array $settings,
    string $className = 'linkshell-avatar',
    bool $lazy = false
): void {
    $name = linkshell_name($settings);
    $avatar = linkshell_avatar_filename($settings);

    if ($avatar !== '' && linkshell_image_exists($avatar)) {
        ?>
        <img
            class="<?= e($className) ?>"
            src="<?= e(linkshell_image_url($avatar)) ?>"
            alt="<?= e(t('aria.linkshell_avatar', ['name' => $name])) ?>"
            <?= $lazy ? 'loading="lazy"' : '' ?>
        >
        <?php
        return;
    }

    ?>
    <span class="<?= e($className) ?> linkshell-avatar-placeholder" aria-hidden="true">
        <?= e(app_text_initial($name)) ?>
    </span>
    <?php
}

function render_file_input(
    string $name,
    string $accept = '',
    bool $required = false,
    string $extraClass = ''
): void {
    $classes = trim('custom-file-native ' . $extraClass);
    ?>
    <span
        class="custom-file-control"
        data-custom-file
        data-empty-label="<?= e(t('file.none_selected')) ?>"
    >
        <input
            class="<?= e($classes) ?>"
            type="file"
            name="<?= e($name) ?>"
            <?= $accept !== '' ? 'accept="' . e($accept) . '"' : '' ?>
            <?= $required ? 'required' : '' ?>
            data-file-input-native
        >
        <span class="custom-file-button" aria-hidden="true"><?= e(t('file.choose')) ?></span>
        <span class="custom-file-name" data-file-name><?= e(t('file.none_selected')) ?></span>
    </span>
    <?php
}

function render_flash_messages(): void
{
    $types = [
        'flash_warning' => 'alert-warning',
        'flash_success' => 'alert-success',
        'flash_error' => 'alert-error',
    ];

    foreach ($types as $sessionKey => $className) {
        $message = trim((string)($_SESSION[$sessionKey] ?? ''));
        unset($_SESSION[$sessionKey]);

        if ($message === '') {
            continue;
        }
        ?>
        <div class="alert <?= e($className) ?>" role="status"><?= e($message) ?></div>
        <?php
    }
}


function render_cookie_information_panel(): void
{
    ?>
    <aside class="auth-cookie-card" aria-labelledby="cookie-info-heading">
        <div class="eyebrow"><?= e(t('auth.cookies.eyebrow')) ?></div>
        <h2 id="cookie-info-heading"><?= e(t('auth.cookies.heading')) ?></h2>
        <p class="muted"><?= e(t('auth.cookies.intro')) ?></p>

        <ul class="cookie-info-list">
            <li>
                <strong><?= e(t('auth.cookies.session_title')) ?></strong>
                <span><?= e(t('auth.cookies.session_text')) ?></span>
            </li>
            <li>
                <strong><?= e(t('auth.cookies.language_title')) ?></strong>
                <span><?= e(t('auth.cookies.language_text')) ?></span>
            </li>
        </ul>

        <p class="cookie-info-note"><?= e(t('auth.cookies.no_tracking')) ?></p>
    </aside>
    <?php
}

function render_header(string $title, ?array $user = null): void
{
    // Le nettoyage est exécuté à chaque page afin qu'aucun événement expiré ne reste affiché.
    purge_expired_events();

    $settings = load_app_settings();
    $siteName = linkshell_name($settings);
    $discordUrl = linkshell_discord_url($settings);
    $fullTitle = e($title . ' — ' . $siteName);
    ?>
<!doctype html>
<html lang="<?= e(current_language()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $fullTitle ?></title>
    <link rel="stylesheet" href="<?= e(app_url('assets/style.css')) ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= e(app_url(user_landing_path($user))) ?>">
        <?php render_linkshell_avatar($settings, 'brand-avatar'); ?>
        <span class="brand-name"><?= e($siteName) ?></span>
    </a>

    <?php if ($user): ?>
        <?php
        $status = (string)($user['status'] ?? '');
        $homePath = in_array($status, ['member', 'admin'], true)
            ? 'home.php'
            : user_landing_path($user);
        ?>
        <nav class="main-nav" aria-label="<?= e(t('nav.main_aria')) ?>">
            <a href="<?= e(app_url($homePath)) ?>"><?= e(t('nav.home')) ?></a>

            <?php if ($status === 'admin'): ?>
                <a href="<?= e(app_url('admin/index.php')) ?>"><?= e(t('nav.administration')) ?></a>
            <?php endif; ?>

            <?php if ($discordUrl !== ''): ?>
                <a
                    class="nav-discord"
                    href="<?= e($discordUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="<?= e(t('nav.discord')) ?>"
                >
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path fill="currentColor" d="M19.54 5.34A16.8 16.8 0 0 0 15.44 4l-.5 1.02a15.6 15.6 0 0 0-5.88 0L8.56 4a16.8 16.8 0 0 0-4.1 1.34C1.87 9.18 1.17 12.92 1.52 16.6a16.5 16.5 0 0 0 5.03 2.53l1.22-1.67a10.5 10.5 0 0 1-1.92-.92l.47-.36c3.7 1.72 7.72 1.72 11.37 0l.48.36c-.62.36-1.26.67-1.93.92l1.22 1.67a16.5 16.5 0 0 0 5.03-2.53c.41-4.27-.7-7.98-2.95-11.26ZM8.7 14.5c-1.1 0-2.01-1.02-2.01-2.27 0-1.25.89-2.27 2.01-2.27 1.13 0 2.03 1.03 2.01 2.27 0 1.25-.89 2.27-2.01 2.27Zm6.6 0c-1.1 0-2.01-1.02-2.01-2.27 0-1.25.89-2.27 2.01-2.27 1.13 0 2.03 1.03 2.01 2.27 0 1.25-.88 2.27-2.01 2.27Z"/>
                    </svg>
                    <span><?= e(t('nav.discord')) ?></span>
                </a>
            <?php endif; ?>

            <a class="nav-logout" href="<?= e(app_url('logout.php')) ?>"><?= e(t('nav.logout')) ?></a>
        </nav>
    <?php endif; ?>

    <form class="language-selector" method="post" action="<?= e(app_url('set-language.php')) ?>">
        <input type="hidden" name="return" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? app_url('index.php'))) ?>">
        <label>
            <span class="sr-only"><?= e(t('language.label')) ?></span>
            <select name="language" aria-label="<?= e(t('language.label')) ?>" onchange="this.form.submit()">
                <option value="de" <?= current_language() === 'de' ? 'selected' : '' ?>>🇩🇪 Deutsch</option>
                <option value="en" <?= current_language() === 'en' ? 'selected' : '' ?>>🇬🇧 English</option>
                <option value="es" <?= current_language() === 'es' ? 'selected' : '' ?>>🇪🇸 Español</option>
                <option value="fr" <?= current_language() === 'fr' ? 'selected' : '' ?>>🇫🇷 Français</option>
                <option value="it" <?= current_language() === 'it' ? 'selected' : '' ?>>🇮🇹 Italiano</option>
                <option value="pt" <?= current_language() === 'pt' ? 'selected' : '' ?>>🇵🇹 Português</option>
                <option value="pt-br" <?= current_language() === 'pt-br' ? 'selected' : '' ?>>🇧🇷 Português (Brasil)</option>
                <option value="ja" <?= current_language() === 'ja' ? 'selected' : '' ?>>🇯🇵 日本語</option>
                <option value="zh" <?= current_language() === 'zh' ? 'selected' : '' ?>>🇨🇳 简体中文</option>
                <option value="tl" <?= current_language() === 'tl' ? 'selected' : '' ?>>🇵🇭 Tagalog</option>
            </select>
        </label>
        <noscript><button type="submit" class="button button-small"><?= e(t('common.save')) ?></button></noscript>
    </form>
</header>

<main class="page-shell">
<?php
    render_flash_messages();
}

function render_footer(): void
{
    ?>
</main>

<footer class="site-footer">
    <p>Project &quot;Crystal Planner&quot; - ©2026 AlpasNet - Seije Eos</p>
</footer>

<script>
(() => {
    document.querySelectorAll('[data-custom-file]').forEach((control) => {
        const input = control.querySelector('[data-file-input-native]');
        const filename = control.querySelector('[data-file-name]');

        if (!input || !filename) {
            return;
        }

        const refresh = () => {
            const names = input.files
                ? Array.from(input.files).map((file) => file.name).filter(Boolean)
                : [];

            filename.textContent = names.length > 0
                ? names.join(', ')
                : (control.dataset.emptyLabel || '');

            control.classList.toggle('has-file', names.length > 0);
        };

        input.addEventListener('change', refresh);
        refresh();
    });

    const linkshellAvatarInput = document.querySelector('input[name="linkshell_avatar"]');
    const linkshellAvatarPreview = document.querySelector('[data-linkshell-avatar-preview]');
    const linkshellAvatarPreviewImage = document.querySelector('[data-linkshell-avatar-preview-image]');

    if (linkshellAvatarInput && linkshellAvatarPreview && linkshellAvatarPreviewImage) {
        const linkshellAvatarCurrent = linkshellAvatarPreview.querySelector('.linkshell-settings-avatar:not([data-linkshell-avatar-preview-image])');
        let avatarObjectUrl = '';

        const showAvatarPreview = (src) => {
            if (avatarObjectUrl !== '') {
                URL.revokeObjectURL(avatarObjectUrl);
                avatarObjectUrl = '';
            }

            if (src === '') {
                linkshellAvatarPreviewImage.hidden = true;
                linkshellAvatarPreviewImage.removeAttribute('src');

                if (linkshellAvatarCurrent) {
                    linkshellAvatarCurrent.hidden = false;
                }

                return;
            }

            linkshellAvatarPreviewImage.src = src;
            linkshellAvatarPreviewImage.hidden = false;

            if (linkshellAvatarCurrent) {
                linkshellAvatarCurrent.hidden = true;
            }
        };

        linkshellAvatarInput.addEventListener('change', () => {
            const file = linkshellAvatarInput.files && linkshellAvatarInput.files[0]
                ? linkshellAvatarInput.files[0]
                : null;

            if (!file || !file.type || !file.type.startsWith('image/')) {
                showAvatarPreview('');
                return;
            }

            if (avatarObjectUrl !== '') {
                URL.revokeObjectURL(avatarObjectUrl);
            }

            avatarObjectUrl = URL.createObjectURL(file);
            linkshellAvatarPreviewImage.src = avatarObjectUrl;
            linkshellAvatarPreviewImage.hidden = false;

            if (linkshellAvatarCurrent) {
                linkshellAvatarCurrent.hidden = true;
            }
        });
    }



    document.querySelectorAll('[data-sortable-job-list]').forEach((list) => {
        const reorderFormId = list.dataset.reorderForm || '';
        const reorderForm = reorderFormId !== '' ? document.getElementById(reorderFormId) : null;
        const orderInput = reorderForm ? reorderForm.querySelector('input[name="order"]') : null;
        const positionTemplate = list.dataset.positionTemplate || '';
        let draggedRow = null;
        let draggedJobId = '';
        let initialOrder = '';

        const rows = () => Array.from(list.querySelectorAll('[data-job-row]'));

        const currentOrder = () => rows()
            .map((row) => row.dataset.jobId || '')
            .filter(Boolean)
            .join(',');

        const updatePositions = () => {
            rows().forEach((row, index) => {
                const position = index + 1;
                const number = row.querySelector('[data-job-position-number]');
                const wrapper = row.querySelector('[data-job-position]');

                if (number) {
                    number.textContent = String(position);
                }

                if (wrapper && positionTemplate !== '') {
                    wrapper.setAttribute('aria-label', positionTemplate.replace('__POSITION__', String(position)));
                }
            });
        };

        const rowAfterPointer = (clientY) => {
            const candidates = rows().filter((row) => row !== draggedRow);

            return candidates.reduce((closest, row) => {
                const box = row.getBoundingClientRect();
                const offset = clientY - box.top - box.height / 2;

                if (offset < 0 && offset > closest.offset) {
                    return { offset, element: row };
                }

                return closest;
            }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
        };

        list.addEventListener('dragstart', (event) => {
            const handle = event.target instanceof Element
                ? event.target.closest('[data-job-drag-handle]')
                : null;

            if (!handle) {
                return;
            }

            draggedRow = handle.closest('[data-job-row]');

            if (!draggedRow || !event.dataTransfer) {
                return;
            }

            draggedJobId = draggedRow.dataset.jobId || '';
            initialOrder = currentOrder();
            draggedRow.classList.add('is-dragging');
            list.classList.add('is-dragging-list');
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', draggedJobId);
        });

        list.addEventListener('dragover', (event) => {
            if (!draggedRow) {
                return;
            }

            event.preventDefault();
            const nextRow = rowAfterPointer(event.clientY);

            if (nextRow) {
                list.insertBefore(draggedRow, nextRow);
            } else {
                list.appendChild(draggedRow);
            }

            updatePositions();
        });

        list.addEventListener('drop', (event) => {
            if (!draggedRow) {
                return;
            }

            event.preventDefault();
        });

        list.addEventListener('dragend', () => {
            if (!draggedRow) {
                return;
            }

            const finalOrder = currentOrder();
            draggedRow.classList.remove('is-dragging');
            list.classList.remove('is-dragging-list');
            updatePositions();

            if (reorderForm && orderInput && finalOrder !== '' && finalOrder !== initialOrder) {
                orderInput.value = finalOrder;
                reorderForm.action = `${window.location.pathname}${window.location.search}#job-${draggedJobId}`;
                reorderForm.submit();
            }

            draggedRow = null;
            draggedJobId = '';
            initialOrder = '';
        });
    });

    const memberBackgroundInput = document.querySelector('input[name="member_home_background"]');
    const memberBackgroundPreview = document.querySelector('[data-member-background-preview]');
    const memberBackgroundPreviewImage = document.querySelector('[data-member-background-preview-image]');

    if (memberBackgroundInput && memberBackgroundPreview && memberBackgroundPreviewImage) {
        const originalSrc = memberBackgroundPreviewImage.getAttribute('src') || '';
        let objectUrl = '';

        const showPreview = (src) => {
            if (objectUrl !== '') {
                URL.revokeObjectURL(objectUrl);
                objectUrl = '';
            }

            if (src === '') {
                memberBackgroundPreview.classList.remove('is-visible');
                memberBackgroundPreviewImage.hidden = true;
                memberBackgroundPreviewImage.removeAttribute('src');
                return;
            }

            memberBackgroundPreviewImage.src = src;
            memberBackgroundPreviewImage.hidden = false;
            memberBackgroundPreview.classList.add('is-visible');
        };

        memberBackgroundInput.addEventListener('change', () => {
            const file = memberBackgroundInput.files && memberBackgroundInput.files[0]
                ? memberBackgroundInput.files[0]
                : null;

            if (!file) {
                showPreview(originalSrc);
                return;
            }

            if (!file.type || !file.type.startsWith('image/')) {
                showPreview(originalSrc);
                return;
            }

            if (objectUrl !== '') {
                URL.revokeObjectURL(objectUrl);
            }

            objectUrl = URL.createObjectURL(file);
            memberBackgroundPreviewImage.src = objectUrl;
            memberBackgroundPreviewImage.hidden = false;
            memberBackgroundPreview.classList.add('is-visible');
        });
    }
})();
</script>
<script src="<?= e(app_url('assets/lodestone-lookup.js')) ?>"></script>
</body>
</html>
<?php
}
