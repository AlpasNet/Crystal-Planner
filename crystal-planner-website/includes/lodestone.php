<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class LodestoneUnavailableException extends RuntimeException
{
    private string $technicalCode;

    public function __construct(string $message, string $technicalCode = 'LODESTONE')
    {
        parent::__construct($message);
        $this->technicalCode = $technicalCode;
    }

    public function technicalCode(): string
    {
        return $this->technicalCode;
    }
}

final class LodestoneProfileException extends RuntimeException
{
}

function lodestone_profile_url(string $lodestoneId): string
{
    return 'https://eu.finalfantasyxiv.com/lodestone/character/' . rawurlencode($lodestoneId) . '/';
}

/** @return list<string> */
function lodestone_official_url_candidates(string $url): array
{
    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));

    if ($scheme !== 'https' || ($host !== 'finalfantasyxiv.com' && !str_ends_with($host, '.finalfantasyxiv.com'))) {
        return [$url];
    }

    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $parts['fragment'] : '';
    $hosts = array_values(array_unique([
        $host,
        'eu.finalfantasyxiv.com',
        'na.finalfantasyxiv.com',
        'jp.finalfantasyxiv.com',
    ]));

    return array_map(
        static fn(string $candidateHost): string =>
            'https://' . $candidateHost . $path . $query . $fragment,
        $hosts
    );
}

function lodestone_ca_bundle_path(): ?string
{
    $candidates = [
        (string)ini_get('curl.cainfo'),
        (string)ini_get('openssl.cafile'),
        '/etc/ssl/certs/ca-certificates.crt',
        '/etc/pki/tls/certs/ca-bundle.crt',
        '/etc/ssl/ca-bundle.pem',
    ];

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function lodestone_response_block_code(string $body): string
{
    $needles = [
        '/lodestone/error/unsupported_browser/' => 'BROWSER_REJECTED',
        'You are using a browser not recommended' => 'BROWSER_REJECTED',
        '<title>Access Denied</title>' => 'ACCESS_DENIED',
        'The request could not be satisfied' => 'ACCESS_DENIED',
        'Request blocked' => 'ACCESS_DENIED',
        'Attention Required! | Cloudflare' => 'ACCESS_DENIED',
    ];

    foreach ($needles as $needle => $code) {
        if (stripos($body, $needle) !== false) {
            return $code;
        }
    }

    return '';
}

/**
 * @return array{success:bool,body:string,status:int,curl_errno:int,error:string,transport:string,host:string,block_code:string}
 */
function lodestone_curl_attempt(string $url, string $userAgent, bool $compatibilityMode): array
{
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? 'unknown');
    $transport = $compatibilityMode ? 'curl-ipv4-http1' : 'curl-auto';
    $handle = curl_init($url);

    if ($handle === false) {
        return [
            'success' => false,
            'body' => '',
            'status' => 0,
            'curl_errno' => 0,
            'error' => 'curl_init failed',
            'transport' => $transport,
            'host' => $host,
            'block_code' => '',
        ];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => $userAgent,
        CURLOPT_REFERER => 'https://' . $host . '/lodestone/character/',
        CURLOPT_HTTPHEADER => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-GB,en;q=0.9,fr;q=0.8',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Connection: close',
            'Upgrade-Insecure-Requests: 1',
        ],
        CURLOPT_ENCODING => '',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if (defined('CURLOPT_NOSIGNAL')) {
        $options[CURLOPT_NOSIGNAL] = true;
    }

    $caBundle = lodestone_ca_bundle_path();
    if ($caBundle !== null && defined('CURLOPT_CAINFO')) {
        $options[CURLOPT_CAINFO] = $caBundle;
    }

    if ($compatibilityMode) {
        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        }
        if (defined('CURLOPT_HTTP_VERSION') && defined('CURL_HTTP_VERSION_1_1')) {
            $options[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_1_1;
        }
    }

    curl_setopt_array($handle, $options);
    $body = curl_exec($handle);
    $statusInfo = defined('CURLINFO_RESPONSE_CODE') ? CURLINFO_RESPONSE_CODE : CURLINFO_HTTP_CODE;
    $status = (int)curl_getinfo($handle, $statusInfo);
    $errorNumber = curl_errno($handle);
    $error = trim(curl_error($handle));
    curl_close($handle);

    $body = is_string($body) ? $body : '';
    $blockCode = $body !== '' ? lodestone_response_block_code($body) : '';
    $success = $errorNumber === 0
        && $status >= 200
        && $status < 400
        && trim($body) !== ''
        && $blockCode === '';

    return [
        'success' => $success,
        'body' => $body,
        'status' => $status,
        'curl_errno' => $errorNumber,
        'error' => $error,
        'transport' => $transport,
        'host' => $host,
        'block_code' => $blockCode,
    ];
}

/**
 * @return array{success:bool,body:string,status:int,curl_errno:int,error:string,transport:string,host:string,block_code:string}
 */
function lodestone_stream_attempt(string $url, string $userAgent): array
{
    $host = (string)(parse_url($url, PHP_URL_HOST) ?? 'unknown');
    $sslOptions = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ];
    $caBundle = lodestone_ca_bundle_path();
    if ($caBundle !== null) {
        $sslOptions['cafile'] = $caBundle;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 25,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
            'protocol_version' => 1.1,
            'header' => implode("\r\n", [
                'User-Agent: ' . $userAgent,
                'Referer: https://' . $host . '/lodestone/character/',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-GB,en;q=0.9,fr;q=0.8',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
                'Connection: close',
            ]),
        ],
        'ssl' => $sslOptions,
    ]);

    error_clear_last();
    $body = @file_get_contents($url, false, $context);
    $lastError = error_get_last();
    $responseHeaders = $http_response_header ?? [];
    $status = 0;

    foreach ($responseHeaders as $headerLine) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/i', $headerLine, $matches)) {
            $status = (int)$matches[1];
        }
    }

    $body = is_string($body) ? $body : '';
    $blockCode = $body !== '' ? lodestone_response_block_code($body) : '';
    $success = $status >= 200
        && $status < 400
        && trim($body) !== ''
        && $blockCode === '';

    return [
        'success' => $success,
        'body' => $body,
        'status' => $status,
        'curl_errno' => 0,
        'error' => trim((string)($lastError['message'] ?? '')),
        'transport' => 'php-stream',
        'host' => $host,
        'block_code' => $blockCode,
    ];
}

/** @param list<array<string,mixed>> $attempts */
function lodestone_attempts_technical_code(array $attempts): string
{
    foreach ($attempts as $attempt) {
        if (($attempt['block_code'] ?? '') !== '') {
            return (string)$attempt['block_code'];
        }
    }
    foreach ($attempts as $attempt) {
        $status = (int)($attempt['status'] ?? 0);
        if ($status === 403) {
            return 'HTTP 403';
        }
        if ($status === 429) {
            return 'HTTP 429';
        }
    }
    foreach ($attempts as $attempt) {
        $curlError = (int)($attempt['curl_errno'] ?? 0);
        if ($curlError === 6) {
            return 'DNS';
        }
        if ($curlError === 60) {
            return 'SSL';
        }
        if ($curlError === 28) {
            return 'TIMEOUT';
        }
        if (in_array($curlError, [7, 35, 52, 56], true)) {
            return 'CONNECTION';
        }
    }
    foreach ($attempts as $attempt) {
        $error = strtolower((string)($attempt['error'] ?? ''));
        if (str_contains($error, 'getaddrinfo') || str_contains($error, 'resolve host')) {
            return 'DNS';
        }
        if (str_contains($error, 'certificate') || str_contains($error, 'ssl')) {
            return 'SSL';
        }
        if (str_contains($error, 'timed out') || str_contains($error, 'timeout')) {
            return 'TIMEOUT';
        }
    }
    foreach ($attempts as $attempt) {
        $status = (int)($attempt['status'] ?? 0);
        if ($status >= 500) {
            return 'HTTP ' . $status;
        }
        if ($status >= 400) {
            return 'HTTP ' . $status;
        }
    }

    return 'NETWORK';
}

/** @param list<array<string,mixed>> $attempts */
function lodestone_attempts_log_summary(array $attempts): string
{
    $parts = [];

    foreach ($attempts as $attempt) {
        $detail = (string)($attempt['host'] ?? 'unknown')
            . '/' . (string)($attempt['transport'] ?? 'unknown')
            . ':HTTP ' . (int)($attempt['status'] ?? 0)
            . ',cURL ' . (int)($attempt['curl_errno'] ?? 0);
        $blockCode = trim((string)($attempt['block_code'] ?? ''));
        $error = preg_replace('/\s+/u', ' ', trim((string)($attempt['error'] ?? ''))) ?? '';

        if ($blockCode !== '') {
            $detail .= ',' . $blockCode;
        }
        if ($error !== '') {
            $detail .= ',' . substr($error, 0, 180);
        }

        $parts[] = $detail;
    }

    return implode(' | ', $parts);
}

function lodestone_http_get(string $url): string
{
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) '
        . 'AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    $attempts = [];
    $streamAvailable = filter_var((string)ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN);

    foreach (lodestone_official_url_candidates($url) as $candidateUrl) {
        if (function_exists('curl_init')) {
            $attempt = lodestone_curl_attempt($candidateUrl, $userAgent, false);
            $attempts[] = $attempt;
            if ($attempt['success']) {
                return $attempt['body'];
            }

            // Le second essai contourne les problèmes IPv6, HTTP/2 ou de route
            // propres à certains hébergements mutualisés.
            if ((int)$attempt['status'] === 0 || (int)$attempt['curl_errno'] !== 0) {
                $compatibilityAttempt = lodestone_curl_attempt($candidateUrl, $userAgent, true);
                $attempts[] = $compatibilityAttempt;
                if ($compatibilityAttempt['success']) {
                    return $compatibilityAttempt['body'];
                }
            }
        }

        // Le wrapper PHP utilise une pile réseau différente de cURL. Il sert de
        // véritable repli lorsque cURL est présent mais mal configuré.
        if ($streamAvailable) {
            $streamAttempt = lodestone_stream_attempt($candidateUrl, $userAgent);
            $attempts[] = $streamAttempt;
            if ($streamAttempt['success']) {
                return $streamAttempt['body'];
            }
        }
    }

    if ($attempts === []) {
        throw new LodestoneUnavailableException(t('lodestone.error.php_support'), 'NO_HTTP_CLIENT');
    }

    $technicalCode = lodestone_attempts_technical_code($attempts);
    throw new LodestoneUnavailableException(
        t('lodestone.error.temporarily_inaccessible') . ' [' . lodestone_attempts_log_summary($attempts) . ']',
        $technicalCode
    );
}

function lodestone_clean_text(string $value): string
{
    $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function lodestone_extract_class_text_regex(string $html, array $classNames): ?string
{
    foreach ($classNames as $className) {
        $quoted = preg_quote($className, '~');
        $pattern = '~<([a-z0-9]+)\b[^>]*class=["\'][^"\']*\b'
            . $quoted
            . '\b[^"\']*["\'][^>]*>(.*?)</\1>~is';

        if (preg_match($pattern, $html, $matches)) {
            $value = lodestone_clean_text((string)$matches[2]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return null;
}

function lodestone_extract_attribute(string $tag, string $attribute): ?string
{
    $quoted = preg_quote($attribute, '~');

    if (preg_match('~\b' . $quoted . '\s*=\s*(["\'])(.*?)\1~is', $tag, $matches)) {
        return html_entity_decode((string)$matches[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    return null;
}

function lodestone_extract_image_regex(string $html): ?string
{
    $containerClasses = [
        'frame__chara__face',
        'character__detail__image',
        'character__face',
    ];

    foreach ($containerClasses as $className) {
        $quoted = preg_quote($className, '~');
        $pattern = '~class=["\'][^"\']*\b' . $quoted . '\b[^"\']*["\'][^>]*>'
            . '.{0,2500}?<img\b[^>]*>~is';

        if (preg_match($pattern, $html, $matches)) {
            $imgTag = (string)$matches[0];
            $source = lodestone_extract_attribute($imgTag, 'data-src')
                ?? lodestone_extract_attribute($imgTag, 'src');

            if ($source !== null && $source !== '') {
                return $source;
            }
        }
    }

    if (preg_match_all('~<meta\b[^>]*>~is', $html, $metaTags)) {
        foreach ($metaTags[0] as $metaTag) {
            $property = strtolower((string)(lodestone_extract_attribute($metaTag, 'property') ?? ''));
            if ($property === 'og:image') {
                $content = lodestone_extract_attribute($metaTag, 'content');
                if ($content !== null && $content !== '') {
                    return $content;
                }
            }
        }
    }

    return null;
}

function lodestone_xpath_first_text(DOMXPath $xpath, array $queries): ?string
{
    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            continue;
        }

        $value = lodestone_clean_text((string)$nodes->item(0)?->textContent);
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function lodestone_xpath_first_attribute(DOMXPath $xpath, array $queries): ?string
{
    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes === false || $nodes->length === 0) {
            continue;
        }

        $value = trim((string)$nodes->item(0)?->nodeValue);
        if ($value !== '') {
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return null;
}

function lodestone_parse_with_dom(string $html): array
{
    if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) {
        return [];
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($document);
    $classToken = static fn(string $class): string =>
        "contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')";

    $name = lodestone_xpath_first_text($xpath, [
        '//*[' . $classToken('frame__chara__name') . ']',
        '//*[' . $classToken('character__detail__name') . ']',
        '//*[' . $classToken('character__name') . ']',
    ]);

    $world = lodestone_xpath_first_text($xpath, [
        '//*[' . $classToken('frame__chara__world') . ']',
        '//*[' . $classToken('character__detail__world') . ']',
        '//*[' . $classToken('character__world') . ']',
    ]);

    $avatar = lodestone_xpath_first_attribute($xpath, [
        '//*[' . $classToken('frame__chara__face') . ']//img/@data-src',
        '//*[' . $classToken('frame__chara__face') . ']//img/@src',
        '//*[' . $classToken('character__detail__image') . ']//img/@data-src',
        '//*[' . $classToken('character__detail__image') . ']//img/@src',
        '//meta[translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="og:image"]/@content',
    ]);

    return [
        'character_name' => $name,
        'world' => $world,
        'avatar_url' => $avatar,
    ];
}

function lodestone_normalize_avatar_url(?string $url): string
{
    $url = trim((string)$url);

    if ($url === '') {
        return '';
    }

    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    } elseif (str_starts_with($url, '/')) {
        $url = 'https://eu.finalfantasyxiv.com' . $url;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return '';
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));

    if ($scheme !== 'https') {
        return '';
    }

    if ($host !== 'finalfantasyxiv.com' && !str_ends_with($host, '.finalfantasyxiv.com')) {
        return '';
    }

    return $url;
}

function lodestone_normalize_world(?string $world): string
{
    $world = lodestone_clean_text((string)$world);

    if (preg_match('/^(.+?)\s*\[[^\]]+\]\s*$/u', $world, $matches)) {
        $world = trim((string)$matches[1]);
    }

    return $world;
}

function parse_lodestone_profile_html(string $html, string $lodestoneId): array
{
    if (
        stripos($html, '/lodestone/error/unsupported_browser/') !== false
        || stripos($html, 'You are using a browser not recommended') !== false
    ) {
        throw new LodestoneUnavailableException(t('lodestone.error.server_rejected'));
    }

    $domData = lodestone_parse_with_dom($html);

    $name = $domData['character_name']
        ?? lodestone_extract_class_text_regex($html, [
            'frame__chara__name',
            'character__detail__name',
            'character__name',
        ]);

    $world = $domData['world']
        ?? lodestone_extract_class_text_regex($html, [
            'frame__chara__world',
            'character__detail__world',
            'character__world',
        ]);

    $avatar = $domData['avatar_url'] ?? lodestone_extract_image_regex($html);

    $name = lodestone_clean_text((string)$name);
    $world = lodestone_normalize_world($world);
    $avatar = lodestone_normalize_avatar_url($avatar);

    if (
        $name === ''
        || $world === ''
        || $avatar === ''
        || in_array(app_text_lower($name), ['character', 'player search'], true)
    ) {
        throw new LodestoneProfileException(
            t('lodestone.error.no_character', ['id' => $lodestoneId])
        );
    }

    return [
        'character_name' => $name,
        'world' => $world,
        'avatar_url' => $avatar,
        'lodestone_url' => lodestone_profile_url($lodestoneId),
    ];
}

function fetch_lodestone_profile(string $lodestoneId): array
{
    if (!preg_match('/^\d{1,20}$/', $lodestoneId)) {
        throw new LodestoneProfileException(t('lodestone.error.invalid_id'));
    }

    $html = lodestone_http_get(lodestone_profile_url($lodestoneId));

    return parse_lodestone_profile_html($html, $lodestoneId);
}

final class LodestoneSearchRateLimitException extends RuntimeException
{
}

function lodestone_search_url(string $characterName, string $world): string
{
    $query = http_build_query([
        'q' => trim($characterName),
        'worldname' => trim($world),
        'classjob' => '',
        'race_tribe' => '',
        'order' => '',
    ], '', '&', PHP_QUERY_RFC3986);

    return 'https://eu.finalfantasyxiv.com/lodestone/character/?' . $query;
}

function lodestone_search_normalize_value(string $value): string
{
    $value = lodestone_clean_text($value);
    return app_text_lower($value);
}

/**
 * Sépare "Omega [Chaos]" ou "Omega (Chaos)" en monde et centre de données.
 *
 * @return array{world:string,data_center:string}
 */
function lodestone_split_world_and_data_center(string $value): array
{
    $value = lodestone_clean_text($value);
    $world = $value;
    $dataCenter = '';

    if (preg_match('/^(.+?)\s*[\[\(]([^\]\)]+)[\]\)]\s*$/u', $value, $matches)) {
        $world = trim((string)$matches[1]);
        $dataCenter = trim((string)$matches[2]);
    }

    return [
        'world' => $world,
        'data_center' => $dataCenter,
    ];
}

function lodestone_search_xpath_first_text(DOMXPath $xpath, DOMNode $context, array $queries): string
{
    foreach ($queries as $query) {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            continue;
        }

        $value = lodestone_clean_text((string)$nodes->item(0)?->textContent);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function lodestone_search_xpath_first_attribute(
    DOMXPath $xpath,
    DOMNode $context,
    array $queries
): string {
    foreach ($queries as $query) {
        $nodes = $xpath->query($query, $context);
        if ($nodes === false || $nodes->length === 0) {
            continue;
        }

        $value = trim((string)$nodes->item(0)?->nodeValue);
        if ($value !== '') {
            return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return '';
}

/**
 * Extrait les entrées de la liste de recherche du Lodestone avec DOMDocument.
 *
 * @return list<array{lodestone_id:string,character_name:string,world:string,data_center:string,avatar_url:string,lodestone_url:string}>
 */
function lodestone_parse_search_results_with_dom(string $html): array
{
    if (!class_exists(DOMDocument::class) || !class_exists(DOMXPath::class)) {
        return [];
    }

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($document);
    $entries = $xpath->query(
        '//div[contains(concat(" ", normalize-space(@class), " "), " entry ")]'
    );

    if ($entries === false || $entries->length === 0) {
        return [];
    }

    $results = [];

    foreach ($entries as $entry) {
        $href = lodestone_search_xpath_first_attribute($xpath, $entry, [
            './/a[contains(concat(" ", normalize-space(@class), " "), " entry__bg ")]/@href',
            './/a[contains(@href, "/lodestone/character/")]/@href',
        ]);

        if (!preg_match('~/lodestone/character/(\d+)/?~', $href, $idMatches)) {
            continue;
        }

        $name = lodestone_search_xpath_first_text($xpath, $entry, [
            './/*[contains(concat(" ", normalize-space(@class), " "), " entry__name ")]',
            './/*[contains(concat(" ", normalize-space(@class), " "), " entry__chara__name ")]',
        ]);
        $worldText = lodestone_search_xpath_first_text($xpath, $entry, [
            './/*[contains(concat(" ", normalize-space(@class), " "), " entry__world ")]',
            './/*[contains(concat(" ", normalize-space(@class), " "), " entry__chara__world ")]',
        ]);
        $avatar = lodestone_search_xpath_first_attribute($xpath, $entry, [
            './/*[contains(concat(" ", normalize-space(@class), " "), " entry__chara__face ")]//img/@data-src',
            './/*[contains(concat(" ", normalize-space(@class), " "), " entry__chara__face ")]//img/@src',
            './/img/@data-src',
            './/img/@src',
        ]);

        if ($name === '' || $worldText === '') {
            continue;
        }

        $location = lodestone_split_world_and_data_center($worldText);
        $lodestoneId = (string)$idMatches[1];

        $results[$lodestoneId] = [
            'lodestone_id' => $lodestoneId,
            'character_name' => $name,
            'world' => $location['world'],
            'data_center' => $location['data_center'],
            'avatar_url' => lodestone_normalize_avatar_url($avatar),
            'lodestone_url' => lodestone_profile_url($lodestoneId),
        ];
    }

    return array_values($results);
}

/**
 * Solution de repli sans extension DOM : le Lodestone utilise actuellement des
 * blocs div.entry avec les classes entry__bg, entry__name et entry__world.
 *
 * @return list<array{lodestone_id:string,character_name:string,world:string,data_center:string,avatar_url:string,lodestone_url:string}>
 */
function lodestone_parse_search_results_with_regex(string $html): array
{
    $parts = preg_split(
        '~(?=<div\b[^>]*class=["\'][^"\']*\bentry\b[^"\']*["\'][^>]*>)~is',
        $html
    );

    if (!is_array($parts) || count($parts) < 2) {
        return [];
    }

    $results = [];

    foreach (array_slice($parts, 1) as $part) {
        $block = substr((string)$part, 0, 18000);

        if (!preg_match(
            '~<a\b[^>]*href=["\'](?:https?://[^"\']+)?/lodestone/character/(\d+)/?[^"\']*["\'][^>]*>~is',
            $block,
            $idMatches
        )) {
            continue;
        }

        $name = lodestone_extract_class_text_regex($block, [
            'entry__name',
            'entry__chara__name',
        ]);
        $worldText = lodestone_extract_class_text_regex($block, [
            'entry__world',
            'entry__chara__world',
        ]);

        if ($name === null || $worldText === null) {
            continue;
        }

        $avatar = '';
        if (preg_match(
            '~class=["\'][^"\']*\bentry__chara__face\b[^"\']*["\'][^>]*>.{0,2500}?<img\b[^>]*>~is',
            $block,
            $imageMatches
        )) {
            $avatar = lodestone_extract_attribute((string)$imageMatches[0], 'data-src')
                ?? lodestone_extract_attribute((string)$imageMatches[0], 'src')
                ?? '';
        }

        $location = lodestone_split_world_and_data_center($worldText);
        $lodestoneId = (string)$idMatches[1];

        $results[$lodestoneId] = [
            'lodestone_id' => $lodestoneId,
            'character_name' => $name,
            'world' => $location['world'],
            'data_center' => $location['data_center'],
            'avatar_url' => lodestone_normalize_avatar_url($avatar),
            'lodestone_url' => lodestone_profile_url($lodestoneId),
        ];
    }

    return array_values($results);
}

/**
 * Récupère quelques identifiants candidats si la structure HTML de la liste a
 * changé. Ils seront ensuite vérifiés sur les pages de profil individuelles.
 *
 * @return list<string>
 */
function lodestone_extract_search_candidate_ids(string $html, int $maximum = 8): array
{
    if (!preg_match_all(
        '~href=["\'](?:https?://[^"\']+)?/lodestone/character/(\d+)/?[^"\']*["\']~i',
        $html,
        $matches
    )) {
        return [];
    }

    $ids = [];
    foreach ($matches[1] as $candidate) {
        $id = (string)$candidate;
        if ($id === '' || isset($ids[$id])) {
            continue;
        }
        $ids[$id] = true;
        if (count($ids) >= $maximum) {
            break;
        }
    }

    return array_keys($ids);
}

function lodestone_search_cache_key(string $characterName, string $world): string
{
    return hash(
        'sha256',
        lodestone_search_normalize_value($characterName) . '|' . lodestone_search_normalize_value($world)
    );
}

/** @return list<array<string,string>>|null */
function load_cached_lodestone_search(string $characterName, string $world): ?array
{
    if (!defined('LODESTONE_SEARCH_CACHE_FILE') || !is_file(LODESTONE_SEARCH_CACHE_FILE)) {
        return null;
    }

    $raw = @file_get_contents(LODESTONE_SEARCH_CACHE_FILE);
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
        return null;
    }

    $entry = $data['entries'][lodestone_search_cache_key($characterName, $world)] ?? null;
    if (!is_array($entry)) {
        return null;
    }

    $createdAt = (int)($entry['created_at'] ?? 0);
    $results = $entry['results'] ?? null;
    $ttl = is_array($results) && $results === [] ? 300 : 900;

    if ($createdAt <= 0 || time() - $createdAt > $ttl || !is_array($results)) {
        return null;
    }

    return array_values(array_filter($results, 'is_array'));
}

/** @param list<array<string,string>> $results */
function save_cached_lodestone_search(string $characterName, string $world, array $results): void
{
    if (!defined('LODESTONE_SEARCH_CACHE_FILE')) {
        return;
    }

    $directory = dirname(LODESTONE_SEARCH_CACHE_FILE);
    if (!is_dir($directory) || !is_writable($directory)) {
        return;
    }

    $handle = @fopen(LODESTONE_SEARCH_CACHE_FILE, 'c+');
    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            $data = ['entries' => []];
        }
        if (!isset($data['entries']) || !is_array($data['entries'])) {
            $data['entries'] = [];
        }

        $now = time();
        foreach ($data['entries'] as $key => $entry) {
            if (!is_array($entry) || $now - (int)($entry['created_at'] ?? 0) > 86400) {
                unset($data['entries'][$key]);
            }
        }

        $data['entries'][lodestone_search_cache_key($characterName, $world)] = [
            'created_at' => $now,
            'results' => $results,
        ];

        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($encoded)) {
            return;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded . PHP_EOL);
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function enforce_lodestone_search_rate_limit(): void
{
    $now = time();
    $timestamps = $_SESSION['lodestone_search_timestamps'] ?? [];
    $timestamps = is_array($timestamps)
        ? array_values(array_filter(
            $timestamps,
            static fn($value): bool => is_numeric($value) && (int)$value > $now - 600
        ))
        : [];

    $lastSearch = $timestamps === [] ? 0 : (int)end($timestamps);

    if ($lastSearch > 0 && $now - $lastSearch < 2) {
        throw new LodestoneSearchRateLimitException(t('lodestone_lookup.error.too_fast'));
    }

    if (count($timestamps) >= 15) {
        throw new LodestoneSearchRateLimitException(t('lodestone_lookup.error.too_many'));
    }

    enforce_lodestone_search_shared_rate_limit($now);

    $timestamps[] = $now;
    $_SESSION['lodestone_search_timestamps'] = $timestamps;
}

/**
 * Limite également les recherches par adresse IP sans enregistrer l'adresse
 * en clair. Cela évite qu'un changement de cookie contourne immédiatement la
 * protection basée sur la session.
 */
function enforce_lodestone_search_shared_rate_limit(int $now): void
{
    if (!defined('LODESTONE_SEARCH_CACHE_FILE')) {
        return;
    }

    $directory = dirname(LODESTONE_SEARCH_CACHE_FILE);
    if (!is_dir($directory) || !is_writable($directory)) {
        return;
    }

    $remoteAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $identity = $remoteAddress !== '' ? 'ip:' . $remoteAddress : 'session:' . session_id();
    $identityKey = hash('sha256', $identity);
    $handle = @fopen(LODESTONE_SEARCH_CACHE_FILE, 'c+');

    if ($handle === false) {
        return;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            $data = ['entries' => [], 'rate_limits' => []];
        }
        if (!isset($data['entries']) || !is_array($data['entries'])) {
            $data['entries'] = [];
        }
        if (!isset($data['rate_limits']) || !is_array($data['rate_limits'])) {
            $data['rate_limits'] = [];
        }

        foreach ($data['rate_limits'] as $key => $storedTimestamps) {
            if (!is_array($storedTimestamps)) {
                unset($data['rate_limits'][$key]);
                continue;
            }

            $fresh = array_values(array_filter(
                $storedTimestamps,
                static fn($value): bool => is_numeric($value) && (int)$value > $now - 3600
            ));

            if ($fresh === []) {
                unset($data['rate_limits'][$key]);
            } else {
                $data['rate_limits'][$key] = $fresh;
            }
        }

        $clientTimestamps = $data['rate_limits'][$identityKey] ?? [];
        $clientTimestamps = is_array($clientTimestamps) ? $clientTimestamps : [];
        $lastMinuteCount = count(array_filter(
            $clientTimestamps,
            static fn($value): bool => (int)$value > $now - 60
        ));

        if ($lastMinuteCount >= 6 || count($clientTimestamps) >= 30) {
            throw new LodestoneSearchRateLimitException(t('lodestone_lookup.error.too_many'));
        }

        $clientTimestamps[] = $now;
        $data['rate_limits'][$identityKey] = $clientTimestamps;

        $encoded = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($encoded)) {
            return;
        }

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $encoded . PHP_EOL);
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Recherche un personnage par nom exact et monde exact, puis vérifie chaque
 * résultat sur sa page de profil avant de retourner son ID Lodestone.
 *
 * @return list<array{lodestone_id:string,character_name:string,world:string,data_center:string,avatar_url:string,lodestone_url:string}>
 */
function search_lodestone_characters(string $characterName, string $world): array
{
    $characterName = lodestone_clean_text($characterName);
    $world = lodestone_clean_text($world);

    $cached = load_cached_lodestone_search($characterName, $world);
    if ($cached !== null) {
        return $cached;
    }

    $html = lodestone_http_get(lodestone_search_url($characterName, $world));

    if (
        stripos($html, '/lodestone/error/unsupported_browser/') !== false
        || stripos($html, 'You are using a browser not recommended') !== false
    ) {
        throw new LodestoneUnavailableException(t('lodestone.error.server_rejected'));
    }

    $parsed = lodestone_parse_search_results_with_dom($html);
    if ($parsed === []) {
        $parsed = lodestone_parse_search_results_with_regex($html);
    }

    $wantedName = lodestone_search_normalize_value($characterName);
    $wantedWorld = lodestone_search_normalize_value($world);
    $results = [];

    // La page de recherche officielle contient déjà le nom, le monde et l'ID.
    // On retourne directement les correspondances exactes pour éviter une
    // seconde requête de profil susceptible d'être bloquée par le Lodestone.
    foreach ($parsed as $candidate) {
        if (
            lodestone_search_normalize_value((string)($candidate['character_name'] ?? '')) !== $wantedName
            || lodestone_search_normalize_value((string)($candidate['world'] ?? '')) !== $wantedWorld
        ) {
            continue;
        }

        $candidateId = (string)($candidate['lodestone_id'] ?? '');
        if (!preg_match('/^\d{1,20}$/', $candidateId)) {
            continue;
        }

        $results[$candidateId] = [
            'lodestone_id' => $candidateId,
            'character_name' => (string)($candidate['character_name'] ?? ''),
            'world' => (string)($candidate['world'] ?? ''),
            'data_center' => (string)($candidate['data_center'] ?? ''),
            'avatar_url' => (string)($candidate['avatar_url'] ?? ''),
            'lodestone_url' => (string)($candidate['lodestone_url'] ?? lodestone_profile_url($candidateId)),
        ];
    }

    if ($results !== []) {
        $finalResults = array_values($results);
        save_cached_lodestone_search($characterName, $world, $finalResults);
        return $finalResults;
    }

    // Repli si Square Enix modifie la structure visuelle de la liste : on
    // récupère quelques IDs présents dans la page et on vérifie leur profil.
    $lastUnavailable = null;
    foreach (lodestone_extract_search_candidate_ids($html) as $candidateId) {
        try {
            $profile = fetch_lodestone_profile($candidateId);
        } catch (LodestoneProfileException) {
            continue;
        } catch (LodestoneUnavailableException $exception) {
            $lastUnavailable = $exception;
            break;
        }

        if (
            lodestone_search_normalize_value((string)$profile['character_name']) !== $wantedName
            || lodestone_search_normalize_value((string)$profile['world']) !== $wantedWorld
        ) {
            continue;
        }

        $results[$candidateId] = [
            'lodestone_id' => $candidateId,
            'character_name' => (string)$profile['character_name'],
            'world' => (string)$profile['world'],
            'data_center' => '',
            'avatar_url' => (string)$profile['avatar_url'],
            'lodestone_url' => (string)$profile['lodestone_url'],
        ];
    }

    if ($results === [] && $lastUnavailable instanceof LodestoneUnavailableException) {
        throw $lastUnavailable;
    }

    $finalResults = array_values($results);
    save_cached_lodestone_search($characterName, $world, $finalResults);

    return $finalResults;
}
