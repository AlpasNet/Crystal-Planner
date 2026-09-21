<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const PASSWORD_RESET_CODE_TTL = 86400;
const PASSWORD_RESET_REQUEST_TTL = 2592000;

function default_password_resets_data(): array
{
    return ['requests' => []];
}

function normalize_password_resets_data(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return default_password_resets_data();
    }

    $requests = isset($decoded['requests']) && is_array($decoded['requests'])
        ? array_values(array_filter($decoded['requests'], 'is_array'))
        : [];

    return ['requests' => $requests];
}

function ensure_password_resets_file_exists(): void
{
    $directory = dirname(PASSWORD_RESETS_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (!file_exists(PASSWORD_RESETS_FILE)) {
        $json = json_encode(
            default_password_resets_data(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false || file_put_contents(PASSWORD_RESETS_FILE, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('password_reset.error.storage'));
        }
    }
}

function load_password_resets_data(): array
{
    ensure_password_resets_file_exists();

    $handle = fopen(PASSWORD_RESETS_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('password_reset.error.storage'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('password_reset.error.storage'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_password_resets_data();
        }

        return normalize_password_resets_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function update_password_resets_data(callable $callback): mixed
{
    ensure_password_resets_file_exists();

    $handle = fopen(PASSWORD_RESETS_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('password_reset.error.storage'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('password_reset.error.storage'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $decoded = ($contents !== false && trim($contents) !== '')
            ? json_decode($contents, true)
            : null;

        $data = normalize_password_resets_data($decoded);
        $result = $callback($data);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('password_reset.error.storage'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('password_reset.error.storage'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function password_reset_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function password_reset_timestamp(string $value): int
{
    $timestamp = strtotime($value);
    return $timestamp === false ? 0 : $timestamp;
}

function purge_stale_password_reset_requests(): void
{
    $now = time();

    update_password_resets_data(function (array &$data) use ($now): void {
        $data['requests'] = array_values(array_filter(
            $data['requests'],
            static function (array $request) use ($now): bool {
                $requestedAt = password_reset_timestamp((string)($request['requested_at'] ?? ''));
                return $requestedAt > 0 && ($now - $requestedAt) <= PASSWORD_RESET_REQUEST_TTL;
            }
        ));
    });
}

function request_password_reset_for_user(array $user): void
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $now = password_reset_now();

    update_password_resets_data(function (array &$data) use ($user, $userId, $now): void {
        $newRequest = [
            'user_id' => $userId,
            'username' => (string)($user['username'] ?? ''),
            'lodestone_id' => (string)($user['lodestone_id'] ?? ''),
            'requested_at' => $now->format(DATE_ATOM),
            'code_hash' => '',
            'code_created_at' => '',
            'expires_at' => '',
        ];

        foreach ($data['requests'] as $index => $request) {
            if ((int)($request['user_id'] ?? 0) === $userId) {
                $lastRequest = password_reset_timestamp((string)($request['requested_at'] ?? ''));
                $expiresAt = password_reset_timestamp((string)($request['expires_at'] ?? ''));
                $hasActiveCode = trim((string)($request['code_hash'] ?? '')) !== '' && $expiresAt > time();

                // Un nouveau clic ne doit pas invalider un code qui vient d'être transmis.
                if ($hasActiveCode || ($lastRequest > 0 && (time() - $lastRequest) < 60)) {
                    return;
                }

                $data['requests'][$index] = $newRequest;
                return;
            }
        }

        $data['requests'][] = $newRequest;
    });
}

function list_password_reset_requests(): array
{
    purge_stale_password_reset_requests();
    $data = load_password_resets_data();
    $requests = $data['requests'];

    usort($requests, static function (array $a, array $b): int {
        return strcmp((string)($b['requested_at'] ?? ''), (string)($a['requested_at'] ?? ''));
    });

    return $requests;
}

function find_password_reset_request(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $data = load_password_resets_data();

    foreach ($data['requests'] as $request) {
        if ((int)($request['user_id'] ?? 0) === $userId) {
            return $request;
        }
    }

    return null;
}

function generate_password_reset_code(int $userId): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';

    for ($i = 0; $i < 10; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    $now = password_reset_now();
    $expiresAt = $now->modify('+' . PASSWORD_RESET_CODE_TTL . ' seconds');
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    update_password_resets_data(function (array &$data) use ($userId, $now, $expiresAt, $codeHash): void {
        foreach ($data['requests'] as &$request) {
            if ((int)($request['user_id'] ?? 0) !== $userId) {
                continue;
            }

            $request['code_hash'] = $codeHash;
            $request['code_created_at'] = $now->format(DATE_ATOM);
            $request['expires_at'] = $expiresAt->format(DATE_ATOM);
            return;
        }

        throw new RuntimeException(t('admin.members.password_reset.error.request_not_found'));
    });

    return $code;
}

function delete_password_reset_request(int $userId): void
{
    update_password_resets_data(function (array &$data) use ($userId): void {
        $data['requests'] = array_values(array_filter(
            $data['requests'],
            static fn(array $request): bool => (int)($request['user_id'] ?? 0) !== $userId
        ));
    });
}

function normalize_password_reset_code(string $code): string
{
    $code = strtoupper(trim($code));
    return preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
}

function verify_password_reset_code(int $userId, string $code): string
{
    $request = find_password_reset_request($userId);

    if ($request === null || trim((string)($request['code_hash'] ?? '')) === '') {
        return 'invalid';
    }

    $expiresAt = password_reset_timestamp((string)($request['expires_at'] ?? ''));
    if ($expiresAt <= 0 || $expiresAt < time()) {
        return 'expired';
    }

    $normalizedCode = normalize_password_reset_code($code);
    if ($normalizedCode === '' || !password_verify($normalizedCode, (string)$request['code_hash'])) {
        return 'invalid';
    }

    return 'valid';
}
