<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

$language = (string)($_POST['language'] ?? $_GET['language'] ?? DEFAULT_LANGUAGE);
set_app_language($language);

$return = trim((string)($_POST['return'] ?? $_GET['return'] ?? 'index.php'));
$return = str_replace(["\r", "\n"], '', $return);

$base = app_base_path();
$allowedPrefix = $base !== '' ? $base . '/' : '/';

if ($return === '' || !str_starts_with($return, $allowedPrefix) || str_starts_with($return, '//')) {
    redirect_to('index.php');
}

header('Location: ' . $return);
exit;
