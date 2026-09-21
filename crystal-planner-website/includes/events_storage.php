<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/jobs_storage.php';
require_once __DIR__ . '/event_images.php';
require_once __DIR__ . '/event_responses_storage.php';

const EVENT_TYPES = ['job', 'poll'];
const POLL_MODES = ['single', 'multiple'];

function default_events_data(): array
{
    return [
        'next_id' => 1,
        'events' => [],
    ];
}

/**
 * @return array<int,array{id:int,label:string}>
 */
function normalize_poll_options(mixed $rawOptions): array
{
    if (!is_array($rawOptions)) {
        return [];
    }

    $options = [];
    $seenIds = [];
    $seenLabels = [];
    $nextFallbackId = 1;

    foreach ($rawOptions as $rawOption) {
        if (is_string($rawOption)) {
            $id = $nextFallbackId;
            $label = trim($rawOption);
        } elseif (is_array($rawOption)) {
            $id = (int)($rawOption['id'] ?? 0);
            $label = trim((string)($rawOption['label'] ?? ''));
        } else {
            continue;
        }

        if ($label === '' || app_text_length($label) > 120) {
            continue;
        }

        $labelKey = app_text_lower($label);
        if (isset($seenLabels[$labelKey])) {
            continue;
        }

        if ($id <= 0 || isset($seenIds[$id])) {
            while (isset($seenIds[$nextFallbackId])) {
                $nextFallbackId++;
            }
            $id = $nextFallbackId;
        }

        $seenIds[$id] = true;
        $seenLabels[$labelKey] = true;
        $nextFallbackId = max($nextFallbackId, $id + 1);
        $options[] = [
            'id' => $id,
            'label' => $label,
        ];
    }

    return $options;
}

/**
 * Convertit un champ texte « une option par ligne » en options structurées.
 *
 * @return array<int,array{id:int,label:string}>
 */
function parse_poll_options_text(string $value): array
{
    $lines = preg_split('/\R/u', $value) ?: [];
    $options = [];
    $seen = [];

    foreach ($lines as $line) {
        $label = trim((string)$line);
        if ($label === '') {
            continue;
        }

        if (app_text_length($label) > 120) {
            throw new RuntimeException(t('poll.error.option_length'));
        }

        $key = app_text_lower($label);
        if (isset($seen[$key])) {
            throw new RuntimeException(t('poll.error.unique_options'));
        }

        $seen[$key] = true;
        $options[] = [
            'id' => count($options) + 1,
            'label' => $label,
        ];
    }

    if (count($options) < 2) {
        throw new RuntimeException(t('poll.error.minimum'));
    }

    if (count($options) > 20) {
        throw new RuntimeException(t('poll.error.maximum'));
    }

    return $options;
}

function poll_options_text(array $options): string
{
    return implode("\n", array_map(
        static fn(array $option): string => (string)($option['label'] ?? ''),
        normalize_poll_options($options)
    ));
}

function normalize_events_data(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return default_events_data();
    }

    $events = [];
    $highestId = 0;

    foreach (($decoded['events'] ?? []) as $rawEvent) {
        if (!is_array($rawEvent)) {
            continue;
        }

        $id = (int)($rawEvent['id'] ?? 0);
        $title = trim((string)($rawEvent['title'] ?? ''));
        $image = basename(trim((string)($rawEvent['image'] ?? '')));
        $eventType = trim((string)($rawEvent['event_type'] ?? 'job'));
        $responseList = trim((string)($rawEvent['response_list'] ?? ''));
        $pollMode = trim((string)($rawEvent['poll_mode'] ?? 'single'));
        $pollOptions = normalize_poll_options($rawEvent['poll_options'] ?? []);
        $descriptionLanguage = normalize_language((string)($rawEvent['description_language'] ?? DEFAULT_LANGUAGE));
        $description = trim((string)($rawEvent['description'] ?? ''));
        $descriptionOther = trim((string)($rawEvent['description_other'] ?? $description));
        if ($descriptionOther === '') {
            $descriptionOther = $description;
        }
        $startAt = trim((string)($rawEvent['start_at'] ?? ''));
        $endAt = trim((string)($rawEvent['end_at'] ?? ''));
        $createdBy = (int)($rawEvent['created_by'] ?? 0);
        $organizerUserId = max(0, (int)($rawEvent['organizer_user_id'] ?? 0));

        if (!in_array($eventType, EVENT_TYPES, true)) {
            $eventType = 'job';
        }

        $responseConfigurationValid = $eventType === 'job'
            ? is_valid_job_category($responseList)
            : in_array($pollMode, POLL_MODES, true) && count($pollOptions) >= 2;

        if (
            $id <= 0
            || $title === ''
            || $image === ''
            || !$responseConfigurationValid
            || $description === ''
            || $startAt === ''
            || $endAt === ''
        ) {
            continue;
        }

        $events[] = [
            'id' => $id,
            'title' => $title,
            'image' => $image,
            'event_type' => $eventType,
            'response_list' => $eventType === 'job' ? $responseList : '',
            'poll_mode' => $eventType === 'poll' ? $pollMode : '',
            'poll_options' => $eventType === 'poll' ? $pollOptions : [],
            'description_language' => $descriptionLanguage,
            'description' => $description,
            'description_other' => $descriptionOther,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'created_by' => $createdBy,
            'organizer_user_id' => $organizerUserId,
        ];
        $highestId = max($highestId, $id);
    }

    return [
        'next_id' => max((int)($decoded['next_id'] ?? 1), $highestId + 1, 1),
        'events' => $events,
    ];
}

function ensure_events_file_exists(): void
{
    $directory = dirname(EVENTS_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (file_exists(EVENTS_FILE)) {
        return;
    }

    $json = json_encode(
        default_events_data(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(EVENTS_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException(t('storage.error.create_events'));
    }
}

function load_events_data(): array
{
    ensure_events_file_exists();

    $handle = fopen(EVENTS_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_events'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('storage.error.lock_events'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_events_data();
        }

        return normalize_events_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function update_events_data(callable $callback): mixed
{
    ensure_events_file_exists();

    $handle = fopen(EVENTS_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_events'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('storage.error.lock_events'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $decoded = ($contents !== false && trim($contents) !== '')
            ? json_decode($contents, true)
            : null;

        $data = normalize_events_data($decoded);
        $result = $callback($data);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('storage.error.encode_events'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException(t('storage.error.truncate_events'));
        }

        if (fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('storage.error.save_events'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function parse_event_datetime(string $value): ?DateTimeImmutable
{
    $timezone = new DateTimeZone(APP_TIMEZONE);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);
    $errors = DateTimeImmutable::getLastErrors();

    if ($date === false) {
        return null;
    }

    if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
        return null;
    }

    return $date;
}

function format_event_datetime(string $value): string
{
    try {
        $date = (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(APP_TIMEZONE));
        return $date->format(current_language() === 'fr' ? 'd/m/Y' : 'm/d/Y') . ' ' . t('date.at') . ' ' . $date->format('H:i');
    } catch (Throwable) {
        return $value;
    }
}

function event_end_datetime(array $event): ?DateTimeImmutable
{
    try {
        return (new DateTimeImmutable((string)($event['end_at'] ?? '')))
            ->setTimezone(new DateTimeZone(APP_TIMEZONE));
    } catch (Throwable) {
        return null;
    }
}

function event_start_datetime(array $event): ?DateTimeImmutable
{
    try {
        return (new DateTimeImmutable((string)($event['start_at'] ?? '')))
            ->setTimezone(new DateTimeZone(APP_TIMEZONE));
    } catch (Throwable) {
        return null;
    }
}

function is_event_expired(array $event, ?DateTimeImmutable $now = null): bool
{
    $endAt = event_end_datetime($event);

    if ($endAt === null) {
        return true;
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
    return $endAt <= $now;
}

function find_event_by_id(int $eventId, ?array $data = null): ?array
{
    $data ??= load_events_data();

    foreach ($data['events'] as $event) {
        if ((int)$event['id'] === $eventId) {
            return $event;
        }
    }

    return null;
}


function event_description_for_language(array $event, ?string $language = null): string
{
    $language = normalize_language($language ?? current_language());
    $baseLanguage = normalize_language((string)($event['description_language'] ?? DEFAULT_LANGUAGE));
    $baseDescription = trim((string)($event['description'] ?? ''));
    $otherDescription = trim((string)($event['description_other'] ?? $baseDescription));

    if ($language === $baseLanguage || $otherDescription === '') {
        return $baseDescription;
    }

    return $otherDescription;
}

function event_type(array $event): string
{
    return (string)($event['event_type'] ?? 'job') === 'poll' ? 'poll' : 'job';
}

function is_poll_event(array $event): bool
{
    return event_type($event) === 'poll';
}

function is_job_event(array $event): bool
{
    return event_type($event) === 'job';
}

function event_type_label(array $event): string
{
    return is_poll_event($event) ? t('common.poll') : t('common.event');
}

function poll_mode_label(string $mode): string
{
    return $mode === 'multiple' ? t('events.poll.multiple') : t('events.poll.single');
}

function event_response_configuration_label(array $event): string
{
    if (is_poll_event($event)) {
        return t('events.poll.config', ['mode' => poll_mode_label((string)($event['poll_mode'] ?? 'single'))]);
    }

    return job_category_label((string)($event['response_list'] ?? ''));
}

function event_response_configuration_signature(array $event): string
{
    if (is_poll_event($event)) {
        return json_encode([
            'poll',
            (string)($event['poll_mode'] ?? 'single'),
            normalize_poll_options($event['poll_options'] ?? []),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    return 'job:' . (string)($event['response_list'] ?? '');
}

/**
 * Supprime les événements dont l'heure de fin est atteinte et leurs réponses.
 * Les images de la bibliothèque assets/event-images/ sont conservées et réutilisables.
 * Le nettoyage est déclenché pendant les requêtes web.
 *
 * @return int Nombre d'événements supprimés.
 */
function purge_expired_events(?DateTimeImmutable $now = null): int
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
    $currentData = load_events_data();
    $hasExpiredEvent = false;

    foreach ($currentData['events'] as $event) {
        if (is_event_expired($event, $now)) {
            $hasExpiredEvent = true;
            break;
        }
    }

    if (!$hasExpiredEvent) {
        return 0;
    }

    $expiredEvents = update_events_data(function (array &$data) use ($now): array {
        $expired = [];
        $active = [];

        foreach ($data['events'] as $event) {
            if (is_event_expired($event, $now)) {
                $expired[] = $event;
                continue;
            }

            $active[] = $event;
        }

        $data['events'] = array_values($active);
        return $expired;
    });

    if ($expiredEvents === []) {
        return 0;
    }

    $expiredIds = [];

    foreach ($expiredEvents as $event) {
        $expiredIds[] = (int)$event['id'];
    }

    delete_responses_for_events($expiredIds);

    return count($expiredEvents);
}

function event_datetime_input_value(string $value): string
{
    try {
        return (new DateTimeImmutable($value))
            ->setTimezone(new DateTimeZone(APP_TIMEZONE))
            ->format('Y-m-d\\TH:i');
    } catch (Throwable) {
        return '';
    }
}

function is_event_current(array $event, ?DateTimeImmutable $now = null): bool
{
    $startAt = event_start_datetime($event);
    $endAt = event_end_datetime($event);

    if ($startAt === null || $endAt === null) {
        return false;
    }

    $now ??= new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
    return $startAt <= $now && $endAt > $now;
}

/**
 * Supprime un événement et toutes ses réponses.
 * Son image reste dans la bibliothèque afin de pouvoir être réutilisée.
 */
function delete_event_and_assets(int $eventId): ?array
{
    if ($eventId <= 0) {
        return null;
    }

    $deletedEvent = update_events_data(function (array &$data) use ($eventId): ?array {
        foreach ($data['events'] as $index => $event) {
            if ((int)$event['id'] !== $eventId) {
                continue;
            }

            array_splice($data['events'], $index, 1);
            return $event;
        }

        return null;
    });

    if ($deletedEvent !== null) {
        delete_responses_for_events([$eventId]);
    }

    return $deletedEvent;
}
