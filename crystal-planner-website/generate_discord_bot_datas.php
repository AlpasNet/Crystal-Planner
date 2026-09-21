<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/settings_storage.php';
require_once __DIR__ . '/includes/linkshell_image.php';
require_once __DIR__ . '/includes/events_storage.php';
require_once __DIR__ . '/includes/event_images.php';
require_once __DIR__ . '/includes/event_responses_storage.php';
require_once __DIR__ . '/includes/jobs_storage.php';
require_once __DIR__ . '/includes/json_storage.php';

if (isset($_GET['lang'])) {
    $_SESSION['language'] = normalize_language((string)$_GET['lang']);
}
else
{
$_SESSION['language'] = "en";
}

const DISCORD_BOT_DATA_FILE = __DIR__ . '/discord-bot-datas.json';
const DISCORD_EMBED_COLOR_DEFAULT = 0x8D5CFF;
const DISCORD_EMBED_COLOR_POLL = 0x8D5CFF;
const DISCORD_EMBED_COLOR_PVE = 0x36A9FF;
const DISCORD_EMBED_COLOR_PVP = 0xFF6E85;
const DISCORD_EMBED_COLOR_CRAFT_GATHER = 0x64D7A5;
const DISCORD_EMBED_COLOR_OTHER = 0xFFCC6A;

/**
 * Retourne l’origine publique du site en tenant compte d’un éventuel proxy HTTPS.
 */
function discord_public_origin(): string
{
    $forwardedProto = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]);
    $scheme = $forwardedProto !== ''
        ? strtolower($forwardedProto)
        : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');

    if (!in_array($scheme, ['http', 'https'], true)) {
        $scheme = 'https';
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '' || preg_match('/^[a-z0-9.\-:\[\]]+$/i', $host) !== 1) {
        $host = 'localhost';
    }

    return $scheme . '://' . $host;
}

function discord_public_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptName !== '') {
        $directory = str_replace('\\', '/', dirname($scriptName));

        if ($directory === '/' || $directory === '.' || $directory === '\\') {
            return '';
        }

        return '/' . trim($directory, '/');
    }

    return app_base_path();
}

function discord_absolute_app_url(string $path = ''): string
{
    $basePath = discord_public_base_path();
    $path = ltrim($path, '/');
    $relativeUrl = $path === ''
        ? ($basePath !== '' ? $basePath . '/' : '/')
        : ($basePath !== '' ? $basePath : '') . '/' . $path;

    return discord_public_origin() . $relativeUrl;
}

function discord_text_limit(string $value, int $maximum): string
{
    $value = trim(preg_replace("/\r\n?|\r/u", "\n", $value) ?? $value);

    if (app_text_length($value) <= $maximum) {
        return $value;
    }

    if (function_exists('mb_substr')) {
        return rtrim(mb_substr($value, 0, max(0, $maximum - 3), 'UTF-8')) . '...';
    }

    return rtrim(substr($value, 0, max(0, $maximum - 3))) . '...';
}

/**
 * @param string[] $lines
 * @return string[]
 */
function discord_chunk_lines(array $lines, int $maximumLength = 1024): array
{
    $chunks = [];
    $current = '';

    foreach ($lines as $line) {
        $line = discord_text_limit(trim($line), $maximumLength);
        if ($line === '') {
            continue;
        }

        $candidate = $current === '' ? $line : $current . "\n" . $line;

        if (app_text_length($candidate) <= $maximumLength) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $current = $line;
    }

    if ($current !== '') {
        $chunks[] = $current;
    }

    return $chunks;
}

function discord_event_color(array $event): int
{
    if (is_poll_event($event)) {
        return DISCORD_EMBED_COLOR_POLL;
    }

    return match ((string)($event['response_list'] ?? '')) {
        'pve' => DISCORD_EMBED_COLOR_PVE,
        'pvp' => DISCORD_EMBED_COLOR_PVP,
        'craft_gather' => DISCORD_EMBED_COLOR_CRAFT_GATHER,
        'other' => DISCORD_EMBED_COLOR_OTHER,
        default => DISCORD_EMBED_COLOR_DEFAULT,
    };
}

function discord_event_datetime_value(string $value): string
{
    try {
        $date = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(APP_TIMEZONE));
        $timestamp = $date->getTimestamp();

        return '<t:' . $timestamp . ':F>';
    } catch (Throwable) {
        return discord_text_limit($value, 1024);
    }
}

function discord_character_name(array $user): string
{
    $name = trim((string)($user['character_name'] ?? ''));
    return $name !== '' ? $name : trim((string)($user['username'] ?? t('common.player')));
}

function discord_character_world(array $user): string
{
    return trim((string)($user['world'] ?? ''));
}

function discord_character_identity(array $user): string
{
    $name = discord_character_name($user);
    $world = discord_character_world($user);

    return $world !== '' ? $name . ' — ' . $world : $name;
}

/**
 * @return array<int,array{name:string,value:string,inline:bool}>
 */
function discord_job_response_fields(array $event, array $responsesData, array $usersById): array
{
    $eventResponses = find_event_responses((int)$event['id'], $responsesData);
    $participants = [];

    foreach ($eventResponses as $response) {
        if ((string)($response['response_type'] ?? 'job') !== 'job') {
            continue;
        }

        $user = $usersById[(int)($response['user_id'] ?? 0)] ?? null;
        if ($user === null) {
            continue;
        }

        $name = discord_character_name($user);
        $world = discord_character_world($user);
        $jobName = trim((string)($response['job_name'] ?? ''));
        $positions = normalize_event_positions($response['positions'] ?? []);
        $positionText = format_event_positions($positions);

        $identity = $world !== '' ? '**' . $name . '** · ' . $world : '**' . $name . '**';
        $selection = $jobName !== '' ? ' — ' . $jobName : '';
        if ($positionText !== '') {
            $selection .= ' · ' . $positionText;
        }
        $participants[] = $identity . $selection;
    }

    natcasesort($participants);
    $participants = array_values($participants);

    if ($participants === []) {
        return [[
            'name' => t('discord.participants', ['count' => 0]),
            'value' => t('discord.participants.none'),
            'inline' => false,
        ]];
    }

    $fields = [];
    $chunks = discord_chunk_lines($participants, 1024);

    foreach (array_slice($chunks, 0, 21) as $index => $chunk) {
        $fields[] = [
            'name' => $index === 0
                ? t('discord.participants', ['count' => count($participants)])
                : t('discord.participants.more', ['page' => $index + 1]),
            'value' => $chunk,
            'inline' => false,
        ];
    }

    return $fields;
}

/**
 * @return array<int,array{name:string,value:string,inline:bool}>
 */
function discord_poll_result_fields(array $event, array $responsesData): array
{
    $results = poll_results($event, $responsesData);

    return [[
        'name' => t('discord.voters'),
        'value' => (string)(int)$results['respondents'],
        'inline' => true,
    ]];
}

function discord_event_embed(
    array $event,
    array $responsesData,
    array $usersById,
    string $linkshellName
): array {
    $isPoll = is_poll_event($event);
    $startAt = event_start_datetime($event);
    $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
    $status = $startAt !== null && $startAt <= $now ? t('common.current') : t('common.upcoming');
    $prefix = $isPoll ? '📊' : '📅';

    $fields = [
        [
            'name' => t('discord.start'),
            'value' => discord_event_datetime_value((string)$event['start_at']),
            'inline' => true,
        ],
        [
            'name' => t('discord.end'),
            'value' => discord_event_datetime_value((string)$event['end_at']),
            'inline' => true,
        ]
    ];

    $organizer = $usersById[(int)($event['organizer_user_id'] ?? 0)] ?? null;
    if ($organizer !== null) {
        $fields[] = [
            'name' => t('discord.organizer'),
            'value' => discord_text_limit(discord_character_identity($organizer), 1024),
            'inline' => true,
        ];
    }

    $responseFields = $isPoll
        ? discord_poll_result_fields($event, $responsesData)
        : discord_job_response_fields($event, $responsesData, $usersById);

    $fields = array_slice(array_merge($fields, $responseFields), 0, 25);

    $embed = [
        'author' => [
            'name' => discord_text_limit($linkshellName, 256),
        ],
        'title' => discord_text_limit($prefix . ' ' . (string)$event['title'], 256),
        'url' => discord_absolute_app_url('events.php?event_id=' . (int)$event['id']),
        'color' => discord_event_color($event),
        'fields' => $fields,
        'footer' => [
            'text' => $organizer !== null
                ? discord_text_limit(discord_character_identity($organizer), 2048)
                : ($isPoll ? t('discord.poll.footer') : t('discord.event.footer')),
        ],
    ];

    $imageFilename = basename((string)($event['image'] ?? ''));
    if ($imageFilename !== '' && event_image_exists($imageFilename)) {
        $embed['image'] = [
            'url' => discord_absolute_app_url('assets/event-images/' . rawurlencode($imageFilename)),
        ];
    }

    return $embed;
}

/**
 * Supprime définitivement les événements terminés depuis au moins 10 minutes.
 *
 * Le nettoyage est effectué avant la génération de la liste Discord :
 * - l'événement est retiré du stockage ;
 * - ses réponses sont supprimées ;
 * - les images de la bibliothèque restent intactes et réutilisables.
 */
function purge_events_ended_for_ten_minutes(): void
{
    $timezone = new DateTimeZone(APP_TIMEZONE);
    $now = new DateTimeImmutable('now', $timezone);
    $deletionLimit = $now->sub(new DateInterval('PT10M'));

    $eventsData = load_events_data();
    $storedEvents = is_array($eventsData['events'] ?? null)
        ? $eventsData['events']
        : [];

    $remainingEvents = [];
    $deletedEventIds = [];

    foreach ($storedEvents as $event) {
        if (!is_array($event)) {
            $remainingEvents[] = $event;
            continue;
        }

        $endValue = trim((string)($event['end_at'] ?? ''));
        if ($endValue === '') {
            $remainingEvents[] = $event;
            continue;
        }

        try {
            $endAt = (new DateTimeImmutable($endValue, $timezone))->setTimezone($timezone);
        } catch (Throwable) {
            // Une date invalide ne doit jamais entraîner une suppression automatique.
            $remainingEvents[] = $event;
            continue;
        }

        // L'événement reste stocké jusqu'à ce qu'il soit terminé depuis 10 minutes.
        if ($endAt > $deletionLimit) {
            $remainingEvents[] = $event;
            continue;
        }

        $eventId = (int)($event['id'] ?? 0);
        if ($eventId > 0) {
            $deletedEventIds[$eventId] = true;
        }
    }

    if (count($remainingEvents) === count($storedEvents)) {
        return;
    }

    $eventsData['events'] = array_values($remainingEvents);
    save_events_data($eventsData);

    // Supprime les réponses rattachées aux événements supprimés.
    if ($deletedEventIds !== [] && function_exists('save_event_responses_data')) {
        $responsesData = load_event_responses_data();

        if (is_array($responsesData['responses'] ?? null)) {
            $responsesData['responses'] = array_values(array_filter(
                $responsesData['responses'],
                static function ($response) use ($deletedEventIds): bool {
                    if (!is_array($response)) {
                        return true;
                    }

                    $eventId = (int)($response['event_id'] ?? 0);
                    return !isset($deletedEventIds[$eventId]);
                }
            ));

            save_event_responses_data($responsesData);
        }
    }

    // Les images appartiennent à la bibliothèque et ne sont jamais supprimées ici.
}

function save_discord_bot_payload(array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false) {
        throw new RuntimeException(t('discord.error.encode'));
    }

    $handle = fopen(DISCORD_BOT_DATA_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('discord.error.open_file'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('discord.error.lock_file'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException(t('discord.error.truncate_file'));
        }

        if (fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('discord.error.write_file'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

try {
    // Nettoyage définitif avant tout chargement et toute génération de la liste Discord.
    purge_events_ended_for_ten_minutes();

    $settings = load_app_settings();
    $eventsData = load_events_data();
    $responsesData = load_event_responses_data();
    $usersData = load_users_data();
    $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));

    $events = array_values(array_filter(
        $eventsData['events'],
        static fn(array $event): bool => !is_event_expired($event, $now)
    ));

    usort($events, static function (array $left, array $right): int {
        $endComparison = strcmp((string)$left['end_at'], (string)$right['end_at']);
        return $endComparison !== 0
            ? $endComparison
            : strcmp((string)$left['start_at'], (string)$right['start_at']);
    });

    $usersById = [];
    foreach ($usersData['users'] as $registeredUser) {
        if (!in_array((string)($registeredUser['status'] ?? ''), ['member', 'admin'], true)) {
            continue;
        }

        $usersById[(int)$registeredUser['id']] = $registeredUser;
    }

    $linkshellName = linkshell_name($settings);
    $messages = [];

    foreach ($events as $event) {
        $messages[] = [
            'content' => '',
            'embeds' => [discord_event_embed($event, $responsesData, $usersById, $linkshellName)],
        ];
    }

    $payload = ['messages' => $messages];
    save_discord_bot_payload($payload);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode([
        'messages' => [],
        'error' => t('discord.error.generate'),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
