<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const DISCORD_GUIDES_EMBED_COLOR = 0x36A9FF;
const GUIDE_IMAGE_MAX_BYTES = 8 * 1024 * 1024;

function default_guides_data(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'next_id' => 1,
        'guides' => [],
        'messages' => [],
    ];
}

function normalize_guide_text(string $value): string
{
    return trim(preg_replace("/\r\n?|\r/u", "\n", $value) ?? $value);
}

function is_valid_public_http_url(string $url): bool
{
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function normalize_guide_image_path(string $path): string
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');

    if ($path === '' || !str_starts_with($path, 'assets/guide-images/')) {
        return '';
    }

    if (str_contains($path, '..')) {
        return '';
    }

    return $path;
}

function guide_image_absolute_url(string $imagePath): string
{
    $imagePath = normalize_guide_image_path($imagePath);
    return $imagePath !== '' ? app_absolute_url($imagePath) : '';
}

function discord_guides_messages(array $guides): array
{
    $messages = [];

    foreach ($guides as $guide) {
        $title = normalize_guide_text((string)($guide['title'] ?? ''));
        $description = normalize_guide_text((string)($guide['description'] ?? ''));
        $link = trim((string)($guide['link'] ?? ''));
        $imagePath = normalize_guide_image_path((string)($guide['image_path'] ?? ''));
        $image = guide_image_absolute_url($imagePath);

        if ($title === '' || $description === '' || !is_valid_public_http_url($link) || !is_valid_public_http_url($image)) {
            continue;
        }

        $messages[] = [
            'content' => '',
            'embeds' => [[
                'title' => $title,
                'description' => $description,
                'url' => $link,
                'color' => DISCORD_GUIDES_EMBED_COLOR,
                'image' => [
                    'url' => $image,
                ],
            ]],
        ];
    }

    return $messages;
}

function normalize_guides_data(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return default_guides_data();
    }

    $normalizedGuides = [];
    $highestId = 0;
    $sourceGuides = isset($decoded['guides']) && is_array($decoded['guides'])
        ? $decoded['guides']
        : [];

    foreach ($sourceGuides as $sourceGuide) {
        if (!is_array($sourceGuide)) {
            continue;
        }

        $id = max(0, (int)($sourceGuide['id'] ?? 0));
        $title = normalize_guide_text((string)($sourceGuide['title'] ?? ''));
        $description = normalize_guide_text((string)($sourceGuide['description'] ?? ''));
        $link = trim((string)($sourceGuide['link'] ?? ($sourceGuide['url'] ?? '')));
        $imagePath = normalize_guide_image_path((string)($sourceGuide['image_path'] ?? ''));
        $image = guide_image_absolute_url($imagePath);

        if ($id <= 0 || $title === '' || $description === '' || !is_valid_public_http_url($link) || !is_valid_public_http_url($image)) {
            continue;
        }

        $highestId = max($highestId, $id);
        $normalizedGuides[] = [
            'id' => $id,
            'position' => count($normalizedGuides) + 1,
            'title' => $title,
            'description' => $description,
            'link' => $link,
            'image' => $image,
            'image_path' => $imagePath !== '' ? $imagePath : null,
            'discord' => [
                'title' => $title,
                'description' => $description,
                'url' => $link,
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
        'guides' => $normalizedGuides,
        'messages' => discord_guides_messages($normalizedGuides),
    ];
}

function ensure_guides_file_exists(): void
{
    $directory = dirname(GUIDES_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (file_exists(GUIDES_FILE)) {
        return;
    }

    $json = json_encode(
        default_guides_data(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(GUIDES_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException(t('admin.guides.error.create_file'));
    }
}

function load_guides_data(): array
{
    ensure_guides_file_exists();

    $handle = fopen(GUIDES_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('admin.guides.error.open_file'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('admin.guides.error.lock_file'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_guides_data();
        }

        return normalize_guides_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function save_guides_data(array $guides): array
{
    ensure_guides_file_exists();

    $handle = fopen(GUIDES_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('admin.guides.error.open_file'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('admin.guides.error.lock_file'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $current = ($contents !== false && trim($contents) !== '')
            ? normalize_guides_data(json_decode($contents, true))
            : default_guides_data();

        $nextId = (int)$current['next_id'];
        $usedIds = [];
        $normalizedGuides = [];

        foreach ($guides as $guide) {
            if (!is_array($guide)) {
                continue;
            }

            $id = max(0, (int)($guide['id'] ?? 0));
            if ($id <= 0 || isset($usedIds[$id])) {
                $id = $nextId++;
            } else {
                $nextId = max($nextId, $id + 1);
            }

            $usedIds[$id] = true;
            $title = normalize_guide_text((string)($guide['title'] ?? ''));
            $description = normalize_guide_text((string)($guide['description'] ?? ''));
            $link = trim((string)($guide['link'] ?? ''));
            $imagePath = normalize_guide_image_path((string)($guide['image_path'] ?? ''));
            $image = guide_image_absolute_url($imagePath);

            $normalizedGuides[] = [
                'id' => $id,
                'position' => count($normalizedGuides) + 1,
                'title' => $title,
                'description' => $description,
                'link' => $link,
                'image' => $image,
                'image_path' => $imagePath !== '' ? $imagePath : null,
                'discord' => [
                    'title' => $title,
                    'description' => $description,
                    'url' => $link,
                    'image' => ['url' => $image],
                ],
            ];
        }

        $data = [
            'version' => 1,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format(DateTimeInterface::ATOM),
            'next_id' => max($nextId, 1),
            'guides' => $normalizedGuides,
            'messages' => discord_guides_messages($normalizedGuides),
        ];

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('admin.guides.error.encode_file'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('admin.guides.error.save_file'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $data;
    } finally {
        fclose($handle);
    }
}

function guide_upload_for_index(int $index): ?array
{
    $files = $_FILES['guide_images'] ?? null;
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

function save_guide_uploaded_image(array $file): string
{
    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('admin.guides.error.upload_failed'));
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > GUIDE_IMAGE_MAX_BYTES) {
        throw new RuntimeException(t('admin.guides.error.image_size'));
    }

    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_file($temporaryPath)) {
        throw new RuntimeException(t('admin.guides.error.upload_failed'));
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
        throw new RuntimeException(t('admin.guides.error.image_type'));
    }

    if (!is_dir(GUIDE_IMAGES_DIR) && !mkdir(GUIDE_IMAGES_DIR, 0775, true) && !is_dir(GUIDE_IMAGES_DIR)) {
        throw new RuntimeException(t('admin.guides.error.image_directory'));
    }

    $filename = 'guide_' . bin2hex(random_bytes(12)) . '.' . $extensions[$mimeType];
    $destination = GUIDE_IMAGES_DIR . '/' . $filename;

    if (!move_uploaded_file($temporaryPath, $destination)) {
        throw new RuntimeException(t('admin.guides.error.upload_failed'));
    }

    return 'assets/guide-images/' . $filename;
}

function delete_guide_uploaded_image(string $imagePath): void
{
    $imagePath = normalize_guide_image_path($imagePath);
    if ($imagePath === '') {
        return;
    }

    $filename = basename($imagePath);
    $fullPath = GUIDE_IMAGES_DIR . '/' . $filename;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function delete_unused_guide_images(array $guides): void
{
    if (!is_dir(GUIDE_IMAGES_DIR)) {
        return;
    }

    $usedFiles = [];
    foreach ($guides as $guide) {
        if (!is_array($guide)) {
            continue;
        }

        $path = normalize_guide_image_path((string)($guide['image_path'] ?? ''));
        if ($path !== '') {
            $usedFiles[basename($path)] = true;
        }
    }

    $files = glob(GUIDE_IMAGES_DIR . '/guide_*');
    if ($files === false) {
        return;
    }

    foreach ($files as $file) {
        if (is_file($file) && !isset($usedFiles[basename($file)])) {
            @unlink($file);
        }
    }
}
