<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const LINKSHELL_IMAGE_ALLOWED_MIME_TYPES = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
];

const LINKSHELL_IMAGE_MAX_BYTES = 2 * 1024 * 1024;

function ensure_linkshell_images_directory_exists(): void
{
    if (
        !is_dir(LINKSHELL_IMAGES_DIR)
        && !mkdir(LINKSHELL_IMAGES_DIR, 0775, true)
        && !is_dir(LINKSHELL_IMAGES_DIR)
    ) {
        throw new RuntimeException(t('upload.avatar.create_dir'));
    }
}

/**
 * Enregistre l’avatar s’il a été fourni. Retourne null si aucun fichier n’a été sélectionné.
 *
 * @param array<string,mixed>|null $upload
 */
function save_linkshell_image_upload(
    ?array $upload,
    string $filenamePrefix = 'linkshell_',
    string $failedMessageKey = 'upload.avatar.failed',
    string $maxSizeMessageKey = 'upload.avatar.max_size',
    string $saveErrorMessageKey = 'upload.avatar.save_error'
): ?string {
    if ($upload === null || !isset($upload['error'])) {
        return null;
    }

    $error = (int)$upload['error'];

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t($failedMessageKey));
    }

    $temporaryPath = (string)($upload['tmp_name'] ?? '');
    $size = (int)($upload['size'] ?? 0);

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException(t('upload.invalid'));
    }

    if ($size <= 0 || $size > LINKSHELL_IMAGE_MAX_BYTES) {
        throw new RuntimeException(t($maxSizeMessageKey));
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($temporaryPath);

    if (!is_string($mimeType) || !isset(LINKSHELL_IMAGE_ALLOWED_MIME_TYPES[$mimeType])) {
        throw new RuntimeException(t('upload.format'));
    }

    $extension = LINKSHELL_IMAGE_ALLOWED_MIME_TYPES[$mimeType];
    $filenamePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $filenamePrefix) ?: 'linkshell_';
    $filename = $filenamePrefix . bin2hex(random_bytes(12)) . '.' . $extension;

    ensure_linkshell_images_directory_exists();
    $destination = LINKSHELL_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException(t($saveErrorMessageKey));
    }

    return $filename;
}

function delete_linkshell_image_file(string $filename): void
{
    $filename = basename(trim($filename));

    if ($filename === '') {
        return;
    }

    $path = LINKSHELL_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename;

    if (is_file($path)) {
        @unlink($path);
    }
}

function linkshell_image_exists(string $filename): bool
{
    $filename = basename(trim($filename));

    return $filename !== ''
        && is_file(LINKSHELL_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename);
}

function linkshell_image_url(string $filename): string
{
    $filename = basename(trim($filename));
    return app_url('assets/linkshell/' . rawurlencode($filename));
}


function save_member_home_background_upload(?array $upload): ?string
{
    return save_linkshell_image_upload(
        $upload,
        'home_bg_',
        'upload.background.failed',
        'upload.background.max_size',
        'upload.background.save_error'
    );
}

function member_home_background_url(string $filename): string
{
    return linkshell_image_url($filename);
}
