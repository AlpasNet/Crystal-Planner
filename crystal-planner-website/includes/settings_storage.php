<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';


function normalize_linkshell_discord_url(string $url): string
{
    $url = trim($url);

    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return '';
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));

    if ($scheme !== 'https' || $host === '') {
        return '';
    }

    $isDiscordHost = $host === 'discord.gg'
        || $host === 'discord.com'
        || str_ends_with($host, '.discord.com')
        || $host === 'discordapp.com'
        || str_ends_with($host, '.discordapp.com');

    return $isDiscordHost ? $url : '';
}

function default_app_settings(): array
{
    return [
        'linkshell_name' => APP_NAME,
        'linkshell_avatar' => '',
        'linkshell_discord_url' => '',
        'member_home_background' => '',
    ];
}

function normalize_app_settings(mixed $decoded): array
{
    $default = default_app_settings();

    if (!is_array($decoded)) {
        return $default;
    }

    $name = trim((string)($decoded['linkshell_name'] ?? ''));
    $avatar = basename(trim((string)($decoded['linkshell_avatar'] ?? '')));
    $discordUrl = normalize_linkshell_discord_url((string)($decoded['linkshell_discord_url'] ?? ''));
    $memberHomeBackground = basename(trim((string)($decoded['member_home_background'] ?? '')));

    return [
        'linkshell_name' => $name !== '' ? $name : APP_NAME,
        'linkshell_avatar' => $avatar,
        'linkshell_discord_url' => $discordUrl,
        'member_home_background' => $memberHomeBackground,
    ];
}

function ensure_settings_file_exists(): void
{
    $directory = dirname(SETTINGS_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (file_exists(SETTINGS_FILE)) {
        return;
    }

    $json = json_encode(
        default_app_settings(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(SETTINGS_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException(t('storage.error.create_settings'));
    }
}

function load_app_settings(): array
{
    ensure_settings_file_exists();

    $handle = fopen(SETTINGS_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_settings'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('storage.error.lock_settings'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_app_settings();
        }

        return normalize_app_settings(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function update_app_settings(callable $callback): mixed
{
    ensure_settings_file_exists();

    $handle = fopen(SETTINGS_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_settings'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('storage.error.lock_settings'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $decoded = ($contents !== false && trim($contents) !== '')
            ? json_decode($contents, true)
            : null;

        $settings = normalize_app_settings($decoded);
        $result = $callback($settings);
        $settings = normalize_app_settings($settings);

        $json = json_encode(
            $settings,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('storage.error.encode_settings'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException(t('storage.error.truncate_settings'));
        }

        if (fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('storage.error.save_settings'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function linkshell_name(?array $settings = null): string
{
    $settings ??= load_app_settings();
    $name = trim((string)($settings['linkshell_name'] ?? ''));

    return $name !== '' ? $name : APP_NAME;
}

function linkshell_avatar_filename(?array $settings = null): string
{
    $settings ??= load_app_settings();
    return basename(trim((string)($settings['linkshell_avatar'] ?? '')));
}



function linkshell_discord_url(?array $settings = null): string
{
    $settings ??= load_app_settings();
    return normalize_linkshell_discord_url((string)($settings['linkshell_discord_url'] ?? ''));
}

function member_home_background_filename(?array $settings = null): string
{
    $settings ??= load_app_settings();
    return basename(trim((string)($settings['member_home_background'] ?? '')));
}
