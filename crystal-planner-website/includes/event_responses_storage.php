<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const EVENT_POSITION_CHOICES = ['MT', 'OT', 'H1', 'H2', 'M1', 'M2', 'R1', 'R2'];

/**
 * @param mixed $positions
 * @return string[]
 */
function normalize_event_positions(mixed $positions): array
{
    if (!is_array($positions)) {
        return [];
    }

    $allowed = array_fill_keys(EVENT_POSITION_CHOICES, true);
    $normalized = [];

    foreach ($positions as $position) {
        $position = strtoupper(trim((string)$position));
        if ($position === '' || !isset($allowed[$position]) || in_array($position, $normalized, true)) {
            continue;
        }
        $normalized[] = $position;
    }

    usort($normalized, static function (string $left, string $right): int {
        return array_search($left, EVENT_POSITION_CHOICES, true) <=> array_search($right, EVENT_POSITION_CHOICES, true);
    });

    return $normalized;
}

function format_event_positions(array $positions): string
{
    return implode(', ', normalize_event_positions($positions));
}

function default_event_responses_data(): array
{
    return [
        'next_id' => 1,
        'responses' => [],
    ];
}

function normalize_event_responses_data(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return default_event_responses_data();
    }

    $responses = [];
    $highestId = 0;
    $seenPairs = [];

    foreach (($decoded['responses'] ?? []) as $rawResponse) {
        if (!is_array($rawResponse)) {
            continue;
        }

        $id = (int)($rawResponse['id'] ?? 0);
        $eventId = (int)($rawResponse['event_id'] ?? 0);
        $userId = (int)($rawResponse['user_id'] ?? 0);
        $responseType = trim((string)($rawResponse['response_type'] ?? ''));
        $jobId = (int)($rawResponse['job_id'] ?? 0);
        $jobName = trim((string)($rawResponse['job_name'] ?? ''));
        $positions = normalize_event_positions($rawResponse['positions'] ?? []);
        $optionIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($rawResponse['option_ids'] ?? null) ? $rawResponse['option_ids'] : []),
            static fn(int $optionId): bool => $optionId > 0
        )));

        if ($responseType === '') {
            $responseType = $optionIds !== [] ? 'poll' : 'job';
        }

        $validPayload = $responseType === 'poll'
            ? $optionIds !== []
            : $jobId > 0 && $jobName !== '';

        if ($id <= 0 || $eventId <= 0 || $userId <= 0 || !$validPayload) {
            continue;
        }

        $pairKey = $eventId . ':' . $userId;
        if (isset($seenPairs[$pairKey])) {
            continue;
        }

        $seenPairs[$pairKey] = true;
        $highestId = max($highestId, $id);
        $responses[] = [
            'id' => $id,
            'event_id' => $eventId,
            'user_id' => $userId,
            'response_type' => $responseType === 'poll' ? 'poll' : 'job',
            'job_id' => $responseType === 'job' ? $jobId : 0,
            'job_name' => $responseType === 'job' ? $jobName : '',
            'positions' => $responseType === 'job' ? $positions : [],
            'option_ids' => $responseType === 'poll' ? $optionIds : [],
        ];
    }

    return [
        'next_id' => max((int)($decoded['next_id'] ?? 1), $highestId + 1, 1),
        'responses' => $responses,
    ];
}

function ensure_event_responses_file_exists(): void
{
    $directory = dirname(EVENT_RESPONSES_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (file_exists(EVENT_RESPONSES_FILE)) {
        return;
    }

    $json = json_encode(
        default_event_responses_data(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(EVENT_RESPONSES_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException(t('storage.error.create_responses'));
    }
}

function load_event_responses_data(): array
{
    ensure_event_responses_file_exists();

    $handle = fopen(EVENT_RESPONSES_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_responses'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('storage.error.lock_responses'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_event_responses_data();
        }

        return normalize_event_responses_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function update_event_responses_data(callable $callback): mixed
{
    ensure_event_responses_file_exists();

    $handle = fopen(EVENT_RESPONSES_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_responses'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('storage.error.lock_responses'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $decoded = ($contents !== false && trim($contents) !== '')
            ? json_decode($contents, true)
            : null;

        $data = normalize_event_responses_data($decoded);
        $result = $callback($data);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('storage.error.encode_responses'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException(t('storage.error.truncate_responses'));
        }

        if (fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('storage.error.save_responses'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function find_event_response_for_user(int $eventId, int $userId, ?array $data = null): ?array
{
    $data ??= load_event_responses_data();

    foreach ($data['responses'] as $response) {
        if ((int)$response['event_id'] === $eventId && (int)$response['user_id'] === $userId) {
            return $response;
        }
    }

    return null;
}

function save_event_response(int $eventId, int $userId, int $jobId, string $jobName, array $positions = []): void
{
    save_job_event_response($eventId, $userId, $jobId, $jobName, $positions);
}

/**
 * @param string[] $positions
 */
function save_job_event_response(int $eventId, int $userId, int $jobId, string $jobName, array $positions = []): void
{
    $jobName = trim($jobName);
    $positions = normalize_event_positions($positions);

    if ($eventId <= 0 || $userId <= 0 || $jobId <= 0 || $jobName === '') {
        throw new RuntimeException(t('response.error.invalid'));
    }

    update_event_responses_data(function (array &$data) use ($eventId, $userId, $jobId, $jobName, $positions): void {
        foreach ($data['responses'] as &$response) {
            if ((int)$response['event_id'] === $eventId && (int)$response['user_id'] === $userId) {
                $response['response_type'] = 'job';
                $response['job_id'] = $jobId;
                $response['job_name'] = $jobName;
                $response['positions'] = $positions;
                $response['option_ids'] = [];
                return;
            }
        }

        $responseId = (int)$data['next_id'];
        $data['responses'][] = [
            'id' => $responseId,
            'event_id' => $eventId,
            'user_id' => $userId,
            'response_type' => 'job',
            'job_id' => $jobId,
            'job_name' => $jobName,
            'positions' => $positions,
            'option_ids' => [],
        ];
        $data['next_id'] = $responseId + 1;
    });
}

/**
 * @param int[] $optionIds
 */
function save_poll_event_response(int $eventId, int $userId, array $optionIds): void
{
    $optionIds = array_values(array_unique(array_filter(
        array_map('intval', $optionIds),
        static fn(int $optionId): bool => $optionId > 0
    )));

    if ($eventId <= 0 || $userId <= 0 || $optionIds === []) {
        throw new RuntimeException(t('response.error.invalid_poll'));
    }

    update_event_responses_data(function (array &$data) use ($eventId, $userId, $optionIds): void {
        foreach ($data['responses'] as &$response) {
            if ((int)$response['event_id'] === $eventId && (int)$response['user_id'] === $userId) {
                $response['response_type'] = 'poll';
                $response['job_id'] = 0;
                $response['job_name'] = '';
                $response['positions'] = [];
                $response['option_ids'] = $optionIds;
                return;
            }
        }

        $responseId = (int)$data['next_id'];
        $data['responses'][] = [
            'id' => $responseId,
            'event_id' => $eventId,
            'user_id' => $userId,
            'response_type' => 'poll',
            'job_id' => 0,
            'job_name' => '',
            'positions' => [],
            'option_ids' => $optionIds,
        ];
        $data['next_id'] = $responseId + 1;
    });
}

function delete_event_response(int $eventId, int $userId): void
{
    update_event_responses_data(function (array &$data) use ($eventId, $userId): void {
        $data['responses'] = array_values(array_filter(
            $data['responses'],
            static fn(array $response): bool => !(
                (int)$response['event_id'] === $eventId
                && (int)$response['user_id'] === $userId
            )
        ));
    });
}

function delete_responses_for_user(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    update_event_responses_data(function (array &$data) use ($userId): void {
        $data['responses'] = array_values(array_filter(
            $data['responses'],
            static fn(array $response): bool => (int)$response['user_id'] !== $userId
        ));
    });
}

/**
 * @param int[] $eventIds
 */
function delete_responses_for_events(array $eventIds): void
{
    $eventIds = array_values(array_unique(array_filter(array_map('intval', $eventIds), static fn(int $id): bool => $id > 0)));

    if ($eventIds === []) {
        return;
    }

    $lookup = array_fill_keys($eventIds, true);

    update_event_responses_data(function (array &$data) use ($lookup): void {
        $data['responses'] = array_values(array_filter(
            $data['responses'],
            static fn(array $response): bool => !isset($lookup[(int)$response['event_id']])
        ));
    });
}

/**
 * @return array<int,array<string,mixed>>
 */
function find_event_responses(int $eventId, ?array $data = null): array
{
    $data ??= load_event_responses_data();

    return array_values(array_filter(
        $data['responses'],
        static fn(array $response): bool => (int)$response['event_id'] === $eventId
    ));
}

/**
 * Résultats anonymes d'un Poll.
 * - Choix unique : pourcentage calculé sur le nombre de votants.
 * - Choix multiple : pourcentage calculé sur le nombre total de sélections,
 *   afin que la somme des pourcentages soit égale à 100 %.
 *
 * @return array{respondents:int,total_selections:int,options:array<int,array{id:int,label:string,count:int,percentage:float}>}
 */
function poll_results(array $event, ?array $data = null): array
{
    $options = [];
    $optionLookup = [];

    foreach (($event['poll_options'] ?? []) as $rawOption) {
        if (!is_array($rawOption)) {
            continue;
        }

        $optionId = (int)($rawOption['id'] ?? 0);
        $label = trim((string)($rawOption['label'] ?? ''));
        if ($optionId <= 0 || $label === '') {
            continue;
        }

        $optionLookup[$optionId] = count($options);
        $options[] = [
            'id' => $optionId,
            'label' => $label,
            'count' => 0,
            'percentage' => 0.0,
        ];
    }

    $responses = find_event_responses((int)($event['id'] ?? 0), $data);
    $respondents = 0;
    $totalSelections = 0;

    foreach ($responses as $response) {
        if ((string)($response['response_type'] ?? '') !== 'poll') {
            continue;
        }

        $selectedIds = array_values(array_unique(array_map('intval', $response['option_ids'] ?? [])));
        $hasValidSelection = false;

        foreach ($selectedIds as $selectedId) {
            if (!isset($optionLookup[$selectedId])) {
                continue;
            }

            $options[$optionLookup[$selectedId]]['count']++;
            $totalSelections++;
            $hasValidSelection = true;
        }

        if ($hasValidSelection) {
            $respondents++;
        }
    }

    $isMultiple = (string)($event['poll_mode'] ?? 'single') === 'multiple';
    $percentageBase = $isMultiple ? $totalSelections : $respondents;

    if ($percentageBase > 0) {
        foreach ($options as &$option) {
            $option['percentage'] = round(((int)$option['count'] / $percentageBase) * 100, 1);
        }
        unset($option);

        // En choix multiple, compense l'écart d'arrondi éventuel afin que
        // les pourcentages affichés totalisent exactement 100 %.
        if ($isMultiple && $options !== []) {
            $roundedTotal = array_sum(array_column($options, 'percentage'));
            $roundingDifference = round(100.0 - $roundedTotal, 1);

            if (abs($roundingDifference) >= 0.1) {
                $largestIndex = 0;
                foreach ($options as $index => $option) {
                    if ((int)$option['count'] > (int)$options[$largestIndex]['count']) {
                        $largestIndex = $index;
                    }
                }

                $options[$largestIndex]['percentage'] = round(
                    (float)$options[$largestIndex]['percentage'] + $roundingDifference,
                    1
                );
            }
        }
    }

    return [
        'respondents' => $respondents,
        'total_selections' => $totalSelections,
        'options' => $options,
    ];
}
