<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const DISCORD_RULES_EMBED_COLOR = 0x8D5CFF;

function default_rules_data(): array
{
    return [
        'version' => 1,
        'updated_at' => null,
        'next_id' => 1,
        'rules' => [],
        'messages' => [],
    ];
}

function normalize_rule_text(string $value): string
{
    return trim(preg_replace("/\r\n?|\r/u", "\n", $value) ?? $value);
}

function discord_rules_messages(array $rules): array
{
    $messages = [];

    foreach ($rules as $rule) {
        $title = normalize_rule_text((string)($rule['title'] ?? ''));
        $content = normalize_rule_text((string)($rule['content'] ?? ''));

        if ($title === '' || $content === '') {
            continue;
        }

        $messages[] = [
            'content' => '',
            'embeds' => [[
                'title' => $title,
                'description' => $content,
                'color' => DISCORD_RULES_EMBED_COLOR,
            ]],
        ];
    }

    return $messages;
}

function normalize_rules_data(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return default_rules_data();
    }

    $normalizedRules = [];
    $highestId = 0;
    $sourceRules = isset($decoded['rules']) && is_array($decoded['rules'])
        ? $decoded['rules']
        : [];

    foreach ($sourceRules as $sourceRule) {
        if (!is_array($sourceRule)) {
            continue;
        }

        $id = max(0, (int)($sourceRule['id'] ?? 0));
        $title = normalize_rule_text((string)($sourceRule['title'] ?? ''));
        $content = normalize_rule_text((string)($sourceRule['content'] ?? ($sourceRule['description'] ?? '')));

        if ($id <= 0 || $title === '' || $content === '') {
            continue;
        }

        $highestId = max($highestId, $id);
        $normalizedRules[] = [
            'id' => $id,
            'position' => count($normalizedRules) + 1,
            'title' => $title,
            'content' => $content,
            'discord' => [
                'title' => $title,
                'description' => $content,
            ],
        ];
    }

    $nextId = max((int)($decoded['next_id'] ?? 1), $highestId + 1, 1);
    $updatedAt = isset($decoded['updated_at']) && is_string($decoded['updated_at'])
        ? $decoded['updated_at']
        : null;

    return [
        'version' => 1,
        'updated_at' => $updatedAt,
        'next_id' => $nextId,
        'rules' => $normalizedRules,
        'messages' => discord_rules_messages($normalizedRules),
    ];
}

function ensure_rules_file_exists(): void
{
    $directory = dirname(RULES_FILE);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(t('storage.error.create_data_dir'));
    }

    if (file_exists(RULES_FILE)) {
        return;
    }

    $json = json_encode(
        default_rules_data(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($json === false || file_put_contents(RULES_FILE, $json . PHP_EOL) === false) {
        throw new RuntimeException(t('admin.rules.error.create_file'));
    }
}

function load_rules_data(): array
{
    ensure_rules_file_exists();

    $handle = fopen(RULES_FILE, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('admin.rules.error.open_file'));
    }

    try {
        if (!flock($handle, LOCK_SH)) {
            throw new RuntimeException(t('admin.rules.error.lock_file'));
        }

        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);

        if ($contents === false || trim($contents) === '') {
            return default_rules_data();
        }

        return normalize_rules_data(json_decode($contents, true));
    } finally {
        fclose($handle);
    }
}

function save_rules_data(array $rules): array
{
    ensure_rules_file_exists();

    $handle = fopen(RULES_FILE, 'c+');
    if ($handle === false) {
        throw new RuntimeException(t('admin.rules.error.open_file'));
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException(t('admin.rules.error.lock_file'));
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        $current = ($contents !== false && trim($contents) !== '')
            ? normalize_rules_data(json_decode($contents, true))
            : default_rules_data();

        $nextId = (int)$current['next_id'];
        $usedIds = [];
        $normalizedRules = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $id = max(0, (int)($rule['id'] ?? 0));
            if ($id <= 0 || isset($usedIds[$id])) {
                $id = $nextId++;
            } else {
                $nextId = max($nextId, $id + 1);
            }

            $usedIds[$id] = true;
            $title = normalize_rule_text((string)($rule['title'] ?? ''));
            $content = normalize_rule_text((string)($rule['content'] ?? ''));

            $normalizedRules[] = [
                'id' => $id,
                'position' => count($normalizedRules) + 1,
                'title' => $title,
                'content' => $content,
                'discord' => [
                    'title' => $title,
                    'description' => $content,
                ],
            ];
        }

        $data = [
            'version' => 1,
            'updated_at' => (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format(DateTimeInterface::ATOM),
            'next_id' => max($nextId, 1),
            'rules' => $normalizedRules,
            'messages' => discord_rules_messages($normalizedRules),
        ];

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(t('admin.rules.error.encode_file'));
        }

        rewind($handle);
        if (!ftruncate($handle, 0) || fwrite($handle, $json . PHP_EOL) === false) {
            throw new RuntimeException(t('admin.rules.error.save_file'));
        }

        fflush($handle);
        flock($handle, LOCK_UN);

        return $data;
    } finally {
        fclose($handle);
    }
}
