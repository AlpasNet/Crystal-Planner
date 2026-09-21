<?php
declare(strict_types=1);

const TRANSLATIONS_FILE = __DIR__ . '/../data/translations.json';
const SUPPORTED_LANGUAGES = ['de', 'en', 'es', 'fr', 'it', 'pt', 'pt-br', 'ja', 'zh', 'tl'];
const DEFAULT_LANGUAGE = 'en';

function normalize_language(string $language): string
{
    $language = strtolower(trim($language));
    $language = str_replace('_', '-', $language);

    // HTTP_ACCEPT_LANGUAGE may contain several locales and quality values.
    $language = trim(explode(';', explode(',', $language, 2)[0], 2)[0]);

    if (in_array($language, SUPPORTED_LANGUAGES, true)) {
        return $language;
    }

    $aliases = [
        'pt-pt' => 'pt',
        'pt-br' => 'pt-br',
        'zh-cn' => 'zh',
        'zh-sg' => 'zh',
        'zh-hans' => 'zh',
        'zh-tw' => 'zh',
        'zh-hk' => 'zh',
        'zh-hant' => 'zh',
    ];

    if (isset($aliases[$language])) {
        return $aliases[$language];
    }

    $short = substr($language, 0, 2);

    if ($short === 'pt') {
        return str_contains($language, 'br') ? 'pt-br' : 'pt';
    }

    return in_array($short, SUPPORTED_LANGUAGES, true) ? $short : DEFAULT_LANGUAGE;
}

function current_language(): string
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $sessionLanguage = isset($_SESSION['language']) ? (string)$_SESSION['language'] : '';
    if ($sessionLanguage !== '') {
        return $resolved = normalize_language($sessionLanguage);
    }

    $cookieLanguage = isset($_COOKIE['language']) ? (string)$_COOKIE['language'] : '';
    if ($cookieLanguage !== '') {
        $resolved = normalize_language($cookieLanguage);
        $_SESSION['language'] = $resolved;
        return $resolved;
    }

    $browserLanguage = isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])
        ? (string)$_SERVER['HTTP_ACCEPT_LANGUAGE']
        : '';

    $resolved = $browserLanguage !== ''
        ? normalize_language($browserLanguage)
        : DEFAULT_LANGUAGE;

    $_SESSION['language'] = $resolved;
    return $resolved;
}

function set_app_language(string $language): string
{
    $language = normalize_language($language);
    $_SESSION['language'] = $language;

    setcookie('language', $language, [
        'expires' => time() + 31536000,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

    return $language;
}

function load_translations(): array
{
    static $translations = null;

    if ($translations !== null) {
        return $translations;
    }

    if (!is_file(TRANSLATIONS_FILE)) {
        return $translations = [];
    }

    $contents = file_get_contents(TRANSLATIONS_FILE);
    if ($contents === false || trim($contents) === '') {
        return $translations = [];
    }

    $decoded = json_decode($contents, true);
    return $translations = is_array($decoded) ? $decoded : [];
}

function t(string $key, array $replacements = [], ?string $language = null): string
{
    $language = normalize_language($language ?? current_language());
    $translations = load_translations();

    $value = $translations[$language][$key]
        ?? $translations[DEFAULT_LANGUAGE][$key]
        ?? $key;

    if (!is_string($value)) {
        $value = $key;
    }

    foreach ($replacements as $name => $replacement) {
        $value = str_replace('{' . $name . '}', (string)$replacement, $value);
    }

    return $value;
}

function tp(string $singularKey, string $pluralKey, int $count, array $replacements = [], ?string $language = null): string
{
    $replacements['count'] = $count;
    return t($count === 1 ? $singularKey : $pluralKey, $replacements, $language);
}

function language_label(string $language): string
{
    return t('language.' . normalize_language($language), [], normalize_language($language));
}

function localized_number(float $number, int $decimals = 1): string
{
    $language = current_language();

    if (in_array($language, ['en', 'ja', 'zh', 'tl'], true)) {
        return number_format($number, $decimals, '.', ',');
    }

    return number_format($number, $decimals, ',', ' ');
}