<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Retourne une structure JSON valide, même si le fichier est encore vide.
 */
function default_users_data(): array
{
    return [
        'next_id' => 1,
        'users' => [],
    ];
}

function normalize_users_data(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return default_users_data();
    }

    $users = isset($decoded['users']) && is_array($decoded['users'])
        ? array_values($decoded['users'])
        : [];

    $highestId = 0;
    foreach ($users as $user) {
        $highestId = max($highestId, (int)($user['id'] ?? 0));
    }

    $nextId = max((int)($decoded['next_id'] ?? 1), $highestId + 1, 1);

    return [
        'next_id' => $nextId,
        'users' => $users,
    ];
}

function ensure_users_file_exists(): void
{
    $directory = dirname(USERS_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (!file_exists(USERS_FILE)) {
        $json = json_encode(
            default_users_data(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false || file_put_contents(USERS_FILE, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('storage.error.create_users'));
        }
    }
}

/**
 * Lecture protégée du fichier JSON.
 */
function load_users_data(): array
{
    ensure_users_file_exists();

    $handle = fopen(USERS_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_users'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('storage.error.lock_users'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_users_data();
        }

        return normalize_users_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

/**
 * Modifie puis enregistre le fichier JSON sous verrou exclusif.
 *
 * Le callback reçoit le tableau de données par référence et retourne
 * la valeur qui sera renvoyée par cette fonction.
 */
function update_users_data(callable $callback): mixed
{
    ensure_users_file_exists();

    $handle = fopen(USERS_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_users'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('storage.error.lock_users'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $decoded = ($contents !== false && trim($contents) !== '')
            ? json_decode($contents, true)
            : null;

        $data = normalize_users_data($decoded);
        $result = $callback($data);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('storage.error.encode_users'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException(t('storage.error.truncate_users'));
        }

        if (fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('storage.error.save_users'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}
