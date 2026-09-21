<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/json_storage.php';
require_once __DIR__ . '/lodestone.php';

const USER_STATUSES = ['admin', 'member', 'pending_member', 'banned'];

function find_user_by_username(string $username): ?array
{
    $needle = app_text_lower(trim($username));
    $data = load_users_data();

    foreach ($data['users'] as $user) {
        if (app_text_lower((string)$user['username']) === $needle) {
            return $user;
        }
    }

    return null;
}

function find_user_by_id(int $id): ?array
{
    $data = load_users_data();

    foreach ($data['users'] as $user) {
        if ((int)$user['id'] === $id) {
            return $user;
        }
    }

    return null;
}



function apply_lodestone_profile(array &$user, array $profile): void
{
    $user['character_name'] = (string)($profile['character_name'] ?? '');
    $user['world'] = (string)($profile['world'] ?? '');
    $user['avatar_url'] = (string)($profile['avatar_url'] ?? '');
}

function refresh_user_lodestone_profile(int $userId, bool $strict = false): array
{
    $user = find_user_by_id($userId);

    if ($user === null) {
        throw new RuntimeException(t('admin.members.error.user_not_found'));
    }

    try {
        $profile = fetch_lodestone_profile((string)$user['lodestone_id']);
    } catch (Throwable $exception) {
        if ($strict) {
            throw $exception;
        }

        $_SESSION['flash_warning'] =
            t('lodestone.warning.unavailable');

        return $user;
    }

    return update_users_data(function (array &$data) use ($userId, $profile): array {
        foreach ($data['users'] as &$storedUser) {
            if ((int)$storedUser['id'] !== $userId) {
                continue;
            }

            apply_lodestone_profile($storedUser, $profile);
            return $storedUser;
        }

        throw new RuntimeException(t('admin.members.error.user_not_found'));
    });
}


/**
 * Actualise les données Lodestone de tous les comptes lors d'une connexion.
 *
 * Les profils impossibles à récupérer conservent leurs dernières données.
 * Le tableau $skipUserIds permet d'éviter une seconde requête pour un profil
 * qui vient juste d'être vérifié, par exemple lors de la création d'un compte.
 * Le callback optionnel sert notamment aux tests automatisés.
 *
 * @return array{total:int, updated:int, failed:int, skipped:int}
 */
function refresh_all_lodestone_profiles(
    array $skipUserIds = [],
    ?callable $profileFetcher = null
): array {
    $data = load_users_data();
    $fetcher = $profileFetcher ?? 'fetch_lodestone_profile';
    $skipMap = [];

    foreach ($skipUserIds as $skipUserId) {
        $skipMap[(int)$skipUserId] = true;
    }

    $profiles = [];
    $failed = 0;
    $skipped = 0;

    foreach ($data['users'] as $user) {
        $userId = (int)($user['id'] ?? 0);
        $lodestoneId = trim((string)($user['lodestone_id'] ?? ''));

        if ($userId <= 0) {
            $failed++;
            continue;
        }

        if (isset($skipMap[$userId])) {
            $skipped++;
            continue;
        }

        if (!preg_match('/^\\d{1,20}$/', $lodestoneId)) {
            $failed++;
            continue;
        }

        try {
            $profile = $fetcher($lodestoneId);

            if (!is_array($profile)) {
                throw new RuntimeException(t('register.error.unavailable'));
            }

            $profiles[$userId] = [
                'lodestone_id' => $lodestoneId,
                'profile' => $profile,
            ];
        } catch (Throwable) {
            $failed++;
        }
    }

    $updated = 0;

    if ($profiles !== []) {
        $updated = update_users_data(
            function (array &$storedData) use ($profiles): int {
                $updatedCount = 0;

                foreach ($storedData['users'] as &$storedUser) {
                    $storedUserId = (int)($storedUser['id'] ?? 0);

                    if (!isset($profiles[$storedUserId])) {
                        continue;
                    }

                    $candidate = $profiles[$storedUserId];

                    // Ne pas appliquer un résultat devenu obsolète si l'ID a été
                    // corrigé par un administrateur pendant la récupération.
                    if ((string)($storedUser['lodestone_id'] ?? '') !== $candidate['lodestone_id']) {
                        continue;
                    }

                    apply_lodestone_profile($storedUser, $candidate['profile']);
                    $updatedCount++;
                }

                return $updatedCount;
            }
        );
    }

    if ($failed > 0) {
        $_SESSION['flash_warning'] = $failed === 1
            ? t('lodestone.warning.single')
            : t('lodestone.warning.multiple', ['count' => $failed]);
    }

    return [
        'total' => count($data['users']),
        'updated' => $updated,
        'failed' => $failed,
        'skipped' => $skipped,
    ];
}

function character_name(array $user): string
{
    $name = trim((string)($user['character_name'] ?? ''));
    return $name !== '' ? $name : (string)($user['username'] ?? '');
}

function character_world(array $user): string
{
    return trim((string)($user['world'] ?? ''));
}

function persistent_login_cookie_options(?int $expires = null): array
{
    return [
        'expires' => $expires ?? (time() + PERSISTENT_LOGIN_COOKIE_TTL),
        'path' => app_base_path() !== '' ? app_base_path() . '/' : '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function parse_persistent_login_cookie(?string $raw = null): ?array
{
    $value = trim($raw ?? (string)($_COOKIE[PERSISTENT_LOGIN_COOKIE] ?? ''));
    if ($value === '') {
        return null;
    }

    $parts = explode(':', $value, 3);
    if (count($parts) !== 3) {
        return null;
    }

    [$userIdRaw, $tokenId, $token] = $parts;
    if (!ctype_digit($userIdRaw)
        || !preg_match('/^[a-f0-9]{16}$/', $tokenId)
        || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $userId = (int)$userIdRaw;
    return $userId > 0
        ? ['user_id' => $userId, 'token_id' => $tokenId, 'token' => $token]
        : null;
}

function clear_persistent_login_cookie(): void
{
    setcookie(PERSISTENT_LOGIN_COOKIE, '', persistent_login_cookie_options(time() - 3600));
    unset($_COOKIE[PERSISTENT_LOGIN_COOKIE]);
}

function issue_persistent_login(int $userId): void
{
    $tokenId = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);

    update_users_data(function (array &$data) use ($userId, $tokenId, $tokenHash): void {
        foreach ($data['users'] as &$storedUser) {
            if ((int)($storedUser['id'] ?? 0) !== $userId) {
                continue;
            }

            $logins = isset($storedUser['persistent_logins']) && is_array($storedUser['persistent_logins'])
                ? $storedUser['persistent_logins']
                : [];

            // Garde au maximum 10 appareils/sessions persistants par compte.
            if (count($logins) >= 10) {
                $logins = array_slice($logins, -9, null, true);
            }

            $logins[$tokenId] = [
                'token_hash' => $tokenHash,
                'created_at' => date(DATE_ATOM),
            ];
            $storedUser['persistent_logins'] = $logins;
            return;
        }

        throw new RuntimeException(t('admin.members.error.user_not_found'));
    });

    $cookieValue = $userId . ':' . $tokenId . ':' . $token;
    setcookie(PERSISTENT_LOGIN_COOKIE, $cookieValue, persistent_login_cookie_options());
    $_COOKIE[PERSISTENT_LOGIN_COOKIE] = $cookieValue;
}

function revoke_current_persistent_login(): void
{
    $parsed = parse_persistent_login_cookie();
    if ($parsed !== null) {
        $userId = (int)$parsed['user_id'];
        $tokenId = (string)$parsed['token_id'];

        update_users_data(function (array &$data) use ($userId, $tokenId): void {
            foreach ($data['users'] as &$storedUser) {
                if ((int)($storedUser['id'] ?? 0) !== $userId) {
                    continue;
                }

                if (isset($storedUser['persistent_logins']) && is_array($storedUser['persistent_logins'])) {
                    unset($storedUser['persistent_logins'][$tokenId]);
                    if ($storedUser['persistent_logins'] === []) {
                        unset($storedUser['persistent_logins']);
                    }
                }
                return;
            }
        });
    }

    clear_persistent_login_cookie();
}

function restore_persistent_login(): ?array
{
    $parsed = parse_persistent_login_cookie();
    if ($parsed === null) {
        if (!empty($_COOKIE[PERSISTENT_LOGIN_COOKIE])) {
            clear_persistent_login_cookie();
        }
        return null;
    }

    $user = find_user_by_id((int)$parsed['user_id']);
    if ($user === null) {
        clear_persistent_login_cookie();
        return null;
    }

    $logins = isset($user['persistent_logins']) && is_array($user['persistent_logins'])
        ? $user['persistent_logins']
        : [];
    $entry = $logins[(string)$parsed['token_id']] ?? null;
    $storedHash = is_array($entry) ? (string)($entry['token_hash'] ?? '') : '';
    $candidateHash = hash('sha256', (string)$parsed['token']);

    if ($storedHash === '' || !hash_equals($storedHash, $candidateHash)) {
        clear_persistent_login_cookie();
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    unset($_SESSION['pending_registration']);

    // Renouvelle l'échéance du cookie à chaque restauration réussie.
    setcookie(PERSISTENT_LOGIN_COOKIE, (string)$_COOKIE[PERSISTENT_LOGIN_COOKIE], persistent_login_cookie_options());

    return $user;
}

function login_user(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    unset($_SESSION['pending_registration']);
    issue_persistent_login($userId);
}

function logout_user(): void
{
    revoke_current_persistent_login();
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

function current_user(): ?array
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id > 0) {
        $user = find_user_by_id($id);
        if ($user !== null) {
            // Renouvelle la durée du cookie persistant tant que l'utilisateur revient sur le site.
            if (!empty($_COOKIE[PERSISTENT_LOGIN_COOKIE])) {
                setcookie(PERSISTENT_LOGIN_COOKIE, (string)$_COOKIE[PERSISTENT_LOGIN_COOKIE], persistent_login_cookie_options());
            }
            return $user;
        }
    }

    unset($_SESSION['user_id']);
    return restore_persistent_login();
}

/**
 * Retourne le chemin interne de la requête courante, avec sa query string.
 *
 * Exemple : /cozy_events/events.php?event_id=12 -> events.php?event_id=12
 * Toute URL qui ne correspond pas au chemin de l'application est refusée afin
 * d'éviter qu'une redirection de connexion puisse pointer vers un autre site.
 */
function current_internal_request_path(): ?string
{
    $requestUri = trim((string)($_SERVER['REQUEST_URI'] ?? ''));
    if ($requestUri === '' || preg_match('/[\r\n]/', $requestUri)) {
        return null;
    }

    $path = parse_url($requestUri, PHP_URL_PATH);
    $query = parse_url($requestUri, PHP_URL_QUERY);

    if (!is_string($path) || $path === '') {
        return null;
    }

    $basePath = app_base_path();
    if ($basePath !== '') {
        if ($path === $basePath || $path === $basePath . '/') {
            $relativePath = '';
        } elseif (str_starts_with($path, $basePath . '/')) {
            $relativePath = substr($path, strlen($basePath) + 1);
        } else {
            return null;
        }
    } else {
        $relativePath = ltrim($path, '/');
    }

    if ($relativePath === '' || str_starts_with($relativePath, '../')) {
        return null;
    }

    return $relativePath . (is_string($query) && $query !== '' ? '?' . $query : '');
}

function remember_login_destination(): void
{
    $destination = current_internal_request_path();
    if ($destination === null) {
        return;
    }

    $pathOnly = (string)(parse_url($destination, PHP_URL_PATH) ?? '');
    if ($pathOnly === '' || in_array($pathOnly, ['index.php', 'logout.php'], true)) {
        return;
    }

    $_SESSION['login_return_to'] = $destination;
}

function consume_login_destination(array $user): ?string
{
    $destination = trim((string)($_SESSION['login_return_to'] ?? ''));
    unset($_SESSION['login_return_to']);

    if ($destination === '' || preg_match('/[\r\n]/', $destination)) {
        return null;
    }

    // Le chemin mémorisé doit rester strictement relatif à Cozy Events.
    if (str_starts_with($destination, '/')
        || str_starts_with($destination, '\\')
        || str_contains($destination, '://')) {
        return null;
    }

    $pathOnly = (string)(parse_url($destination, PHP_URL_PATH) ?? '');
    if ($pathOnly === ''
        || str_contains($pathOnly, '..')
        || in_array($pathOnly, ['index.php', 'logout.php', 'pending.php', 'banned.php'], true)) {
        return null;
    }

    $status = (string)($user['status'] ?? '');
    if (!in_array($status, ['member', 'admin'], true)) {
        return null;
    }

    if (str_starts_with($pathOnly, 'admin/') && $status !== 'admin') {
        return null;
    }

    return $destination;
}

function require_login(): array
{
    $user = current_user();

    if ($user === null) {
        unset($_SESSION['user_id']);
        remember_login_destination();
        redirect_to('index.php');
    }

    return $user;
}

function redirect_after_login(array $user): never
{
    $destination = consume_login_destination($user);
    if ($destination !== null) {
        redirect_to($destination);
    }

    switch ($user['status']) {
        case 'admin':
            redirect_to('admin/index.php');
        case 'member':
            redirect_to('home.php');
        case 'banned':
            redirect_to('banned.php');
        case 'pending_member':
        default:
            redirect_to('pending.php');
    }
}

function require_member(): array
{
    $user = require_login();

    if ($user['status'] === 'banned') {
        redirect_to('banned.php');
    }

    if ($user['status'] === 'pending_member') {
        redirect_to('pending.php');
    }

    if (!in_array($user['status'], ['member', 'admin'], true)) {
        logout_user();
        redirect_to('index.php');
    }

    return $user;
}

function require_admin(): array
{
    $user = require_login();

    if ($user['status'] === 'banned') {
        redirect_to('banned.php');
    }

    if ($user['status'] !== 'admin') {
        redirect_to($user['status'] === 'pending_member' ? 'pending.php' : 'home.php');
    }

    return $user;
}

function status_label(string $status): string
{
    return match ($status) {
        'admin' => t('auth.status.admin'),
        'member' => t('auth.status.member'),
        'pending_member' => t('auth.status.pending_member'),
        'banned' => t('auth.status.banned'),
        default => t('auth.status.unknown'),
    };
}
