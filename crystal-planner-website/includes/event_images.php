<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const EVENT_IMAGE_LIBRARY_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

function ensure_event_images_directory_exists(): void
{
    if (!is_dir(EVENT_IMAGES_DIR) && !mkdir(EVENT_IMAGES_DIR, 0775, true) && !is_dir(EVENT_IMAGES_DIR)) {
        throw new RuntimeException(t('upload.event.create_dir'));
    }
}

/**
 * Retourne les images disponibles dans la bibliothèque assets/event-images/.
 *
 * @return string[]
 */
function list_event_images(): array
{
    ensure_event_images_directory_exists();

    $entries = scandir(EVENT_IMAGES_DIR);
    if ($entries === false) {
        return [];
    }

    $images = [];

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $filename = basename($entry);
        $extension = app_text_lower((string)pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($extension, EVENT_IMAGE_LIBRARY_EXTENSIONS, true)) {
            continue;
        }

        if (!is_file(EVENT_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename)) {
            continue;
        }

        $images[] = $filename;
    }

    natcasesort($images);
    return array_values($images);
}

function event_image_exists(string $filename): bool
{
    $filename = basename(trim($filename));
    return $filename !== '' && is_file(EVENT_IMAGES_DIR . DIRECTORY_SEPARATOR . $filename);
}

function event_image_is_selectable(string $filename): bool
{
    $filename = basename(trim($filename));
    if ($filename === '') {
        return false;
    }

    $extension = app_text_lower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, EVENT_IMAGE_LIBRARY_EXTENSIONS, true) && event_image_exists($filename);
}

function event_image_label(string $filename): string
{
    $label = (string)pathinfo(basename($filename), PATHINFO_FILENAME);
    $label = str_replace(['_', '-'], ' ', $label);
    $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
    return trim($label);
}

function event_image_url(string $filename): string
{
    return app_url('assets/event-images/' . rawurlencode(basename(trim($filename))));
}
