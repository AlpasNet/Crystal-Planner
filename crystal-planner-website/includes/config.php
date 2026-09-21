<?php
declare(strict_types=1);

const APP_NAME = 'Game Events';
const USERS_FILE = __DIR__ . '/../data/users.json';
const PASSWORD_RESETS_FILE = __DIR__ . '/../data/password_resets.json';
const LODESTONE_SEARCH_CACHE_FILE = __DIR__ . '/../data/lodestone_search_cache.json';
const JOBS_FILE = __DIR__ . '/../data/jobs.json';
const SETTINGS_FILE = __DIR__ . '/../data/settings.json';
const EVENTS_FILE = __DIR__ . '/../data/events.json';
const EVENT_RESPONSES_FILE = __DIR__ . '/../data/event_responses.json';
const RULES_FILE = __DIR__ . '/../discord-rules.json';
const GUIDES_FILE = __DIR__ . '/../discord-guides.json';
const MACROS_FILE = __DIR__ . '/../discord-macros.json';
const JOB_IMAGES_DIR = __DIR__ . '/../assets/job-images';
const LINKSHELL_IMAGES_DIR = __DIR__ . '/../assets/linkshell';
const EVENT_IMAGES_DIR = __DIR__ . '/../assets/event-images';
const GUIDE_IMAGES_DIR = __DIR__ . '/../assets/guide-images';
const MACRO_IMAGES_DIR = __DIR__ . '/../assets/macro-images';
const APP_TIMEZONE = 'Europe/Paris';
const PERSISTENT_LOGIN_COOKIE = 'cozy_events_auth';
// Longue durée + renouvellement à chaque visite authentifiée : pas de déconnexion automatique après 1 h.
const PERSISTENT_LOGIN_COOKIE_TTL = 315360000; // 10 ans

date_default_timezone_set(APP_TIMEZONE);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Ne pas dépendre de la durée de session par défaut de l'hébergement (souvent 1 h ou moins).
    // Le cookie PHP est également rendu persistant ; l'authentification durable est assurée
    // dans auth.php par un jeton distinct, même si le serveur purge malgré tout le fichier de session.
    @ini_set('session.gc_maxlifetime', (string)PERSISTENT_LOGIN_COOKIE_TTL);
    session_set_cookie_params([
        'lifetime' => PERSISTENT_LOGIN_COOKIE_TTL,
        'path' => '/',
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/i18n.php';


/**
 * Détecte automatiquement le chemin web du projet.
 *
 * Exemples :
 * - projet à la racine du serveur : ""
 * - projet dans /game-events-json : "/game-events-json"
 */
function app_base_path(): string
{
    static $basePath = null;

    if ($basePath !== null) {
        return $basePath;
    }

    $projectRoot = realpath(__DIR__ . '/..');
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT'])
        ? realpath((string)$_SERVER['DOCUMENT_ROOT'])
        : false;

    if ($projectRoot !== false && $documentRoot !== false) {
        $projectRootNormalized = str_replace('\\', '/', $projectRoot);
        $documentRootNormalized = rtrim(str_replace('\\', '/', $documentRoot), '/');

        if ($projectRootNormalized === $documentRootNormalized) {
            return $basePath = '';
        }

        if (str_starts_with($projectRootNormalized, $documentRootNormalized . '/')) {
            $relative = substr($projectRootNormalized, strlen($documentRootNormalized));
            return $basePath = '/' . trim($relative, '/');
        }
    }

    // Solution de repli lorsque DOCUMENT_ROOT n’est pas exploitable.
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFilename = realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''));

    if ($projectRoot !== false && $scriptFilename !== false) {
        $projectRootNormalized = rtrim(str_replace('\\', '/', $projectRoot), '/');
        $scriptFilenameNormalized = str_replace('\\', '/', $scriptFilename);

        if (str_starts_with($scriptFilenameNormalized, $projectRootNormalized . '/')) {
            $relativeScript = substr($scriptFilenameNormalized, strlen($projectRootNormalized));

            if ($relativeScript !== '' && str_ends_with($scriptName, $relativeScript)) {
                return $basePath = rtrim(substr($scriptName, 0, -strlen($relativeScript)), '/');
            }
        }
    }

    return $basePath = '';
}

function app_url(string $path = ''): string
{
    $base = app_base_path();
    $path = ltrim($path, '/');

    if ($path === '') {
        return $base !== '' ? $base . '/' : '/';
    }

    return ($base !== '' ? $base : '') . '/' . $path;
}


function app_absolute_url(string $path = ''): string
{
    $httpsEnabled = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $scheme = ($httpsEnabled || $forwardedProto === 'https') ? 'https' : 'http';

    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === null || $host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host . app_url($path);
}

function redirect_to(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function app_text_length(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function app_text_lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}
function app_text_initial(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '?';
    }

    $firstCharacter = function_exists('mb_substr')
        ? mb_substr($value, 0, 1, 'UTF-8')
        : substr($value, 0, 1);

    return function_exists('mb_strtoupper')
        ? mb_strtoupper($firstCharacter, 'UTF-8')
        : strtoupper($firstCharacter);
}

