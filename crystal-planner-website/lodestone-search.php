<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/lodestone.php';
require_once __DIR__ . '/includes/csrf.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function lodestone_lookup_json(int $status, array $payload): never
{
    http_response_code($status);
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if (!is_string($encoded)) {
        $encoded = '{\"success\":false,\"message\":\"Invalid JSON response.\"}';
    }

    echo $encoded;
    exit;
}

function lodestone_lookup_log_error(Throwable $exception): string
{
    $reference = strtoupper(substr(hash('sha256', microtime(true) . '|' . random_int(1, PHP_INT_MAX)), 0, 8));
    error_log(sprintf(
        '[Lodestone search %s] %s: %s in %s:%d',
        $reference,
        $exception::class,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));

    return $reference;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    lodestone_lookup_json(405, [
        'success' => false,
        'message' => t('lodestone_lookup.error.method'),
    ]);
}

$submittedToken = (string)($_POST['csrf_token'] ?? '');
$storedToken = (string)($_SESSION['csrf_token'] ?? '');

if ($storedToken === '' || !hash_equals($storedToken, $submittedToken)) {
    lodestone_lookup_json(419, [
        'success' => false,
        'message' => t('csrf.error'),
    ]);
}

$characterName = trim((string)($_POST['character_name'] ?? ''));
$world = trim((string)($_POST['world'] ?? ''));

if ($characterName === '' || $world === '') {
    lodestone_lookup_json(422, [
        'success' => false,
        'message' => t('lodestone_lookup.error.required'),
    ]);
}

if (app_text_length($characterName) < 3 || app_text_length($characterName) > 40) {
    lodestone_lookup_json(422, [
        'success' => false,
        'message' => t('lodestone_lookup.error.name_length'),
    ]);
}

if (app_text_length($world) < 2 || app_text_length($world) > 32) {
    lodestone_lookup_json(422, [
        'success' => false,
        'message' => t('lodestone_lookup.error.world_length'),
    ]);
}

if (preg_match('/[\x00-\x1F\x7F<>]/u', $characterName . $world)) {
    lodestone_lookup_json(422, [
        'success' => false,
        'message' => t('lodestone_lookup.error.invalid_characters'),
    ]);
}

try {
    enforce_lodestone_search_rate_limit();
    $results = search_lodestone_characters($characterName, $world);

    lodestone_lookup_json(200, [
        'success' => true,
        'results' => $results,
        'message' => $results === []
            ? t('lodestone_lookup.empty')
            : t('lodestone_lookup.found'),
    ]);
} catch (LodestoneSearchRateLimitException $exception) {
    lodestone_lookup_json(429, [
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (LodestoneUnavailableException $exception) {
    $reference = lodestone_lookup_log_error($exception);
    lodestone_lookup_json(503, [
        'success' => false,
        'message' => t('lodestone_lookup.error.unavailable'),
        'reference' => $reference,
        'technical_code' => $exception->technicalCode(),
    ]);
} catch (Throwable $exception) {
    $reference = lodestone_lookup_log_error($exception);
    lodestone_lookup_json(500, [
        'success' => false,
        'message' => t('lodestone_lookup.error.generic'),
        'reference' => $reference,
    ]);
}
