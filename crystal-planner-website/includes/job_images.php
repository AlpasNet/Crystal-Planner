<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const JOB_IMAGE_ALLOWED_MIME_TYPES = [
    'image/png' => 'png',
    'image/jpeg' => 'jpg',
    'image/webp' => 'webp',
];

const JOB_IMAGE_MAX_BYTES = 2 * 1024 * 1024;

function ensure_job_images_directory_exists(): void
{
    if (!is_dir(JOB_IMAGES_DIR) && !mkdir(JOB_IMAGES_DIR, 0775, true) && !is_dir(JOB_IMAGES_DIR)) {
        throw new RuntimeException(t('upload.job.create_dir'));
    }
}

/**
 * Enregistre une image téléversée et retourne uniquement son nom de fichier.
 *
 * @param array<string,mixed>|null $upload
 */
function save_job_image_upload(?array $upload): string
{
    if ($upload === null || !isset($upload['error'])) {
        throw new RuntimeException(t('upload.job.required'));
    }

    $error = (int)$upload['error'];

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(t('upload.job.required'));
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('upload.failed'));
    }

    $temporaryPath = (string)($upload['tmp_name'] ?? '');
    $size = (int)($upload['size'] ?? 0);

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException(t('upload.invalid'));
    }

    if ($size <= 0 || $size > JOB_IMAGE_MAX_BYTES) {
        throw new RuntimeException(t('upload.job.max_size'));
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($temporaryPath);

    if (!is_string($mimeType) || !isset(JOB_IMAGE_ALLOWED_MIME_TYPES[$mimeType])) {
        throw new RuntimeException(t('upload.format'));
    }

    $extension = JOB_IMAGE_ALLOWED_MIME_TYPES[$mimeType];
    $filename = 'job_' . bin2hex(random_bytes(12)) . '.' . $extension;

    ensure_job_images_directory_exists();
    $destination = JOB_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException(t('upload.job.save_error'));
    }

    return $filename;
}

function delete_job_image_file(string $filename): void
{
    $filename = basename(trim($filename));

    if ($filename === '') {
        return;
    }

    $path = JOB_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename;

    if (is_file($path)) {
        @unlink($path);
    }
}

function job_image_exists(string $filename): bool
{
    $filename = basename(trim($filename));

    return $filename !== '' && is_file(JOB_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename);
}

function job_image_url(string $filename): string
{
    $filename = basename(trim($filename));
    return app_url('assets/job-images/' . rawurlencode($filename));
}
