<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const EVENT_JOB_CATEGORIES = [
    'pve' => 'jobs.category.pve',
    'pvp' => 'jobs.category.pvp',
    'craft_gather' => 'jobs.category.craft_gather',
    'other' => 'jobs.category.other',
];

function default_jobs_data(): array
{
    return [
        'next_id' => 1,
        'categories' => [
            'pve' => [],
            'pvp' => [],
            'craft_gather' => [],
            'other' => [],
        ],
    ];
}

function normalize_jobs_data(mixed $decoded): array
{
    $default = default_jobs_data();

    if (!is_array($decoded)) {
        return $default;
    }

    $normalized = $default;
    $highestId = 0;
    $seenIds = [];

    foreach (EVENT_JOB_CATEGORIES as $categoryKey => $_label) {
        $rawJobs = $decoded['categories'][$categoryKey] ?? [];

        if (!is_array($rawJobs)) {
            continue;
        }

        foreach ($rawJobs as $rawJob) {
            if (!is_array($rawJob)) {
                continue;
            }

            $id = (int)($rawJob['id'] ?? 0);
            $name = trim((string)($rawJob['name'] ?? ''));
            $image = basename(trim((string)($rawJob['image'] ?? '')));

            if ($id <= 0 || $name === '' || isset($seenIds[$id])) {
                continue;
            }

            $seenIds[$id] = true;
            $highestId = max($highestId, $id);
            $normalized['categories'][$categoryKey][] = [
                'id' => $id,
                'name' => $name,
                'image' => $image,
            ];
        }
    }

    $normalized['next_id'] = max(
        (int)($decoded['next_id'] ?? 1),
        $highestId + 1,
        1
    );

    return $normalized;
}

function ensure_jobs_file_exists(): void
{
    $directory = dirname(JOBS_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (file_exists(JOBS_FILE)) {
        return;
    }

    $json = json_encode(
        default_jobs_data(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(JOBS_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException(t('storage.error.create_jobs'));
    }
}

function load_jobs_data(): array
{
    ensure_jobs_file_exists();

    $handle = fopen(JOBS_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_jobs'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('storage.error.lock_jobs'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_jobs_data();
        }

        return normalize_jobs_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function update_jobs_data(callable $callback): mixed
{
    ensure_jobs_file_exists();

    $handle = fopen(JOBS_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('storage.error.open_jobs'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('storage.error.lock_jobs'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $decoded = ($contents !== false && trim($contents) !== '')
            ? json_decode($contents, true)
            : null;

        $data = normalize_jobs_data($decoded);
        $result = $callback($data);

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('storage.error.encode_jobs'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            throw new RuntimeException(t('storage.error.truncate_jobs'));
        }

        if (fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('storage.error.save_jobs'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $result;
    } finally {
        fclose($handle);
    }
}

function is_valid_job_category(string $category): bool
{
    return array_key_exists($category, EVENT_JOB_CATEGORIES);
}

function job_category_label(string $category): string
{
    return isset(EVENT_JOB_CATEGORIES[$category]) ? t(EVENT_JOB_CATEGORIES[$category]) : t('jobs.category.unknown');
}

function job_name_exists(array $jobs, string $name, ?int $exceptJobId = null): bool
{
    $needle = app_text_lower(trim($name));

    foreach ($jobs as $job) {
        if ($exceptJobId !== null && (int)($job['id'] ?? 0) === $exceptJobId) {
            continue;
        }

        if (app_text_lower(trim((string)($job['name'] ?? ''))) === $needle) {
            return true;
        }
    }

    return false;
}

/**
 * @return array{category:string,index:int,job:array}|null
 */
function find_job_location(array $data, int $jobId): ?array
{
    foreach (EVENT_JOB_CATEGORIES as $categoryKey => $_label) {
        foreach ($data['categories'][$categoryKey] as $index => $job) {
            if ((int)($job['id'] ?? 0) === $jobId) {
                return [
                    'category' => $categoryKey,
                    'index' => $index,
                    'job' => $job,
                ];
            }
        }
    }

    return null;
}

function find_job_in_category(array $data, string $category, int $jobId): ?array
{
    if (!is_valid_job_category($category)) {
        return null;
    }

    foreach (($data['categories'][$category] ?? []) as $job) {
        if ((int)($job['id'] ?? 0) === $jobId) {
            return $job;
        }
    }

    return null;
}

