<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const DISCORD_MACROS_EMBED_COLOR = 0x8D5CFF;
const MACRO_IMAGE_MAX_BYTES = 8 * 1024 * 1024;

function default_macros_data(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'next_id' => 1,
        'macros' => [],
        'messages' => [],
    ];
}

function normalize_macro_text(string $value): string
{
    return trim(preg_replace("/\r\n?|\r/u", "\n", $value) ?? $value);
}

function normalize_macro_image_path(string $path): string
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');

    if ($path === '' || !str_starts_with($path, 'assets/macro-images/')) {
        return '';
    }

    if (str_contains($path, '..')) {
        return '';
    }

    return $path;
}

function macro_image_absolute_url(string $imagePath): string
{
    $imagePath = normalize_macro_image_path($imagePath);
    return $imagePath !== '' ? app_absolute_url($imagePath) : '';
}

function is_valid_macro_image_url(string $url): bool
{
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function discord_macros_messages(array $macros): array
{
    $messages = [];

    foreach ($macros as $macro) {
        $title = normalize_macro_text((string)($macro['title'] ?? ''));
        $description = normalize_macro_text((string)($macro['description'] ?? ''));
        $imagePath = normalize_macro_image_path((string)($macro['image_path'] ?? ''));
        $image = macro_image_absolute_url($imagePath);

        if ($title === '' || $description === '' || !is_valid_macro_image_url($image)) {
            continue;
        }

        $messages[] = [
            'content' => '',
            'embeds' => [[
                'title' => $title,
                'description' => $description,
                'color' => DISCORD_MACROS_EMBED_COLOR,
                'image' => [
                    'url' => $image,
                ],
            ]],
        ];
    }

    return $messages;
}

function normalize_macros_data(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return default_macros_data();
    }

    $normalizedMacros = [];
    $highestId = 0;
    $sourceMacros = isset($decoded['macros']) && is_array($decoded['macros'])
        ? $decoded['macros']
        : [];

    foreach ($sourceMacros as $sourceMacro) {
        if (!is_array($sourceMacro)) {
            continue;
        }

        $id = max(0, (int)($sourceMacro['id'] ?? 0));
        $title = normalize_macro_text((string)($sourceMacro['title'] ?? ''));
        $description = normalize_macro_text((string)($sourceMacro['description'] ?? ''));
        $imagePath = normalize_macro_image_path((string)($sourceMacro['image_path'] ?? ''));
        $image = macro_image_absolute_url($imagePath);

        if ($id <= 0 || $title === '' || $description === '' || !is_valid_macro_image_url($image)) {
            continue;
        }

        $highestId = max($highestId, $id);
        $normalizedMacros[] = [
            'id' => $id,
            'position' => count($normalizedMacros) + 1,
            'title' => $title,
            'description' => $description,
            'image' => $image,
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'discord' => [
                'title' => $title,
                'description' => $description,
                'image' => ['url' => $image],
            ],
        ];
    }

    $nextId = max((int)($decoded['next_id'] ?? 1), $highestId + 1, 1);
    $updatedAt = isset($decoded['updated_at']) && is_string($decoded['updated_at'])
        ? $decoded['updated_at']
        : null;

    return [
        'version' => 1,
        'updated_at' => $updatedAt,
        'next_id' => $nextId,
        'macros' => $normalizedMacros,
        'messages' => discord_macros_messages($normalizedMacros),
    ];
}

function ensure_macros_file_exists(): void
{
    $directory = dirname(MACROS_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (file_exists(MACROS_FILE)) {
        return;
    }

    $json = json_encode(
        default_macros_data(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(MACROS_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException(t('admin.macros.error.create_file'));
    }
}

function load_macros_data(): array
{
    ensure_macros_file_exists();

    $handle = fopen(MACROS_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('admin.macros.error.open_file'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('admin.macros.error.lock_file'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_macros_data();
        }

        return normalize_macros_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function save_macros_data(array $macros): array
{
    ensure_macros_file_exists();

    $handle = fopen(MACROS_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('admin.macros.error.open_file'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('admin.macros.error.lock_file'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $current = ($contents !== false && trim($contents) !== '')
            ? normalize_macros_data(json_decode($contents, true))
            : default_macros_data();

        $nextId = (int)$current['next_id'];
        $usedIds = [];
        $normalizedMacros = [];

        foreach ($macros as $macro) {
            if (!is_array($macro)) {
                continue;
            }

            $id = max(0, (int)($macro['id'] ?? 0));
            if ($id <= 0 || isset($usedIds[$id])) {
                $id = $nextId++;
            } else {
                $nextId = max($nextId, $id + 1);
            }

            $usedIds[$id] = true;
            $title = normalize_macro_text((string)($macro['title'] ?? ''));
            $description = normalize_macro_text((string)($macro['description'] ?? ''));
            $imagePath = normalize_macro_image_path((string)($macro['image_path'] ?? ''));
            $image = macro_image_absolute_url($imagePath);

            $normalizedMacros[] = [
                'id' => $id,
                'position' => count($normalizedMacros) + 1,
                'title' => $title,
                'description' => $description,
                'image' => $image,
                'image_path' => $imagePath !== '' ? $imagePath : null,
                'discord' => [
                    'title' => $title,
                    'description' => $description,
                    'image' => ['url' => $image],
                ],
            ];
        }

        $data = [
            'version' => 1,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format(DateTimeInterface::ATOM),
            'next_id' => max($nextId, 1),
            'macros' => $normalizedMacros,
            'messages' => discord_macros_messages($normalizedMacros),
        ];

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('admin.macros.error.encode_file'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('admin.macros.error.save_file'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $data;
    } finally {
        fclose($handle);
    }
}

function macro_upload_for_index(int $index): ?array
{
    $files = $_FILES['macro_images'] ?? null;
    if (!is_array($files)) {
        return null;
    }

    $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
    if ((int)$error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    return [
        'name' => (string)($files['name'][$index] ?? ''),
        'type' => (string)($files['type'][$index] ?? ''),
        'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
        'error' => (int)$error,
        'size' => (int)($files['size'][$index] ?? 0),
    ];
}

function save_macro_uploaded_image(array $file): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('admin.macros.error.upload_failed'));
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > MACRO_IMAGE_MAX_BYTES) {
        throw new RuntimeException(t('admin.macros.error.image_size'));
    }

    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_file($temporaryPath)) {
        throw new RuntimeException(t('admin.macros.error.upload_failed'));
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string)$finfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    if (!isset($extensions[$mimeType])) {
        throw new RuntimeException(t('admin.macros.error.image_type'));
    }

    if (!is_dir(MACRO_IMAGES_DIR) && !mkdir(MACRO_IMAGES_DIR, 0775, true) && !is_dir(MACRO_IMAGES_DIR)) {
        throw new RuntimeException(t('admin.macros.error.image_directory'));
    }

    $filename = 'macro_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mimeType];
    $destination = MACRO_IMAGES_DIR . '/' . $filename;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException(t('admin.macros.error.upload_failed'));
    }

    return 'assets/macro-images/' . $filename;
}

function delete_macro_uploaded_image(string $imagePath): void
{
    $imagePath = normalize_macro_image_path($imagePath);
    if ($imagePath === '') {
        return;
    }

    $filename = basename($imagePath);
    $fullPath = MACRO_IMAGES_DIR . '/' . $filename;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function delete_unused_macro_images(array $macros): void
{
    if (!is_dir(MACRO_IMAGES_DIR)) {
        return;
    }

    $usedFiles = [];
    foreach ($macros as $macro) {
        if (!is_array($macro)) {
            continue;
        }

        $path = normalize_macro_image_path((string)($macro['image_path'] ?? ''));
        if ($path !== '') {
            $usedFiles[basename($path)] = true;
        }
    }

    $files = glob(MACRO_IMAGES_DIR . '/macro_*');
    if ($files === false) {
        return;
    }

    foreach ($files as $file) {
        if (is_file($file) && !isset($usedFiles[basename($file)])) {
            @unlink($file);
        }
    }
}
