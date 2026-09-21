<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/jobs_storage.php';
require_once __DIR__ . '/../includes/events_storage.php';
require_once __DIR__ . '/../includes/event_images.php';
require_once __DIR__ . '/../includes/event_responses_storage.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
purge_expired_events();

$eventId = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);
$event = find_event_by_id($eventId);

if ($event === null) {
    $_SESSION['flash_error'] = t('admin.events.error.not_found');
    redirect_to('admin/events.php');
}

$error = '';
$title = (string)$event['title'];
$eventType = event_type($event);
$responseList = (string)$event['response_list'];
$pollMode = (string)($event['poll_mode'] ?? 'single');
$pollOptionsText = poll_options_text($event['poll_options'] ?? []);
$descriptionLanguage = (string)($event['description_language'] ?? DEFAULT_LANGUAGE);
$description = (string)$event['description'];
$descriptionOther = (string)($event['description_other'] ?? $description);
$startAtInput = event_datetime_input_value((string)$event['start_at']);
$endAtInput = event_datetime_input_value((string)$event['end_at']);
$imageFilename = basename((string)$event['image']);
$jobsData = load_jobs_data();
$availableImages = list_event_images();
$usersData = load_users_data();
$organizerMembers = array_values(array_filter(
    $usersData['users'],
    static fn(array $registeredUser): bool => in_array((string)($registeredUser['status'] ?? ''), ['member', 'admin'], true)
));
usort($organizerMembers, static function (array $left, array $right): int {
    $nameCompare = strcasecmp(user_character_name($left), user_character_name($right));
    return $nameCompare !== 0 ? $nameCompare : ((int)$left['id'] <=> (int)$right['id']);
});
$organizerMembersById = [];
foreach ($organizerMembers as $organizerMember) {
    $organizerMembersById[(int)$organizerMember['id']] = $organizerMember;
}
$organizerUserId = (int)($event['organizer_user_id'] ?? 0);
if ($organizerUserId <= 0 && isset($organizerMembersById[(int)($event['created_by'] ?? 0)])) {
    $organizerUserId = (int)$event['created_by'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title = trim((string)($_POST['title'] ?? ''));
    $eventType = trim((string)($_POST['event_type'] ?? 'job'));
    $responseList = trim((string)($_POST['response_list'] ?? ''));
    $pollMode = trim((string)($_POST['poll_mode'] ?? 'single'));
    $pollOptionsText = trim((string)($_POST['poll_options'] ?? ''));
    $descriptionLanguage = trim((string)($_POST['description_language'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $descriptionOther = trim((string)($_POST['description_other'] ?? ''));
    $startAtInput = trim((string)($_POST['start_at'] ?? ''));
    $endAtInput = trim((string)($_POST['end_at'] ?? ''));
    $imageFilename = basename(trim((string)($_POST['image'] ?? '')));
    $organizerUserId = (int)($_POST['organizer_user_id'] ?? 0);

    try {
        if (app_text_length($title) < 3 || app_text_length($title) > 100) {
            throw new RuntimeException(t('admin.events.error.title_length'));
        }

        if (!in_array($eventType, EVENT_TYPES, true)) {
            throw new RuntimeException(t('admin.events.error.invalid_type'));
        }

        $pollOptions = [];

        if ($eventType === 'job') {
            if (!is_valid_job_category($responseList)) {
                throw new RuntimeException(t('admin.events.error.invalid_job_list'));
            }
        } else {
            if (!in_array($pollMode, POLL_MODES, true)) {
                throw new RuntimeException(t('admin.events.error.invalid_poll_mode'));
            }
            $pollOptions = parse_poll_options_text($pollOptionsText);
        }

        if (!in_array($descriptionLanguage, SUPPORTED_LANGUAGES, true)) {
            throw new RuntimeException(t('admin.events.error.description_language'));
        }

        if ($description === '' || app_text_length($description) > 5000
            || $descriptionOther === '' || app_text_length($descriptionOther) > 5000) {
            throw new RuntimeException(t('admin.events.error.description'));
        }

        $startAt = parse_event_datetime($startAtInput);
        $endAt = parse_event_datetime($endAtInput);

        if ($startAt === null || $endAt === null) {
            throw new RuntimeException(t('admin.events.error.invalid_dates'));
        }

        if ($endAt <= $startAt) {
            throw new RuntimeException(t('admin.events.error.end_after_start'));
        }

        $now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
        if ($endAt <= $now) {
            throw new RuntimeException(t('admin.events.error.end_future'));
        }

        if (!event_image_is_selectable($imageFilename)) {
            throw new RuntimeException(t('admin.events.error.invalid_image'));
        }

        if ($organizerUserId <= 0 || !isset($organizerMembersById[$organizerUserId])) {
            throw new RuntimeException(t('admin.events.error.invalid_organizer'));
        }
        $oldConfigurationSignature = event_response_configuration_signature($event);
        $newConfiguration = [
            'event_type' => $eventType,
            'response_list' => $eventType === 'job' ? $responseList : '',
            'poll_mode' => $eventType === 'poll' ? $pollMode : '',
            'poll_options' => $eventType === 'poll' ? $pollOptions : [],
        ];
        $newConfigurationSignature = event_response_configuration_signature($newConfiguration);

        try {
            $updated = update_events_data(function (array &$data) use (
                $eventId,
                $title,
                $eventType,
                $responseList,
                $pollMode,
                $pollOptions,
                $descriptionLanguage,
                $description,
                $descriptionOther,
                $startAt,
                $endAt,
                $imageFilename,
                $organizerUserId
            ): bool {
                foreach ($data['events'] as &$storedEvent) {
                    if ((int)$storedEvent['id'] !== $eventId) {
                        continue;
                    }

                    $storedEvent['title'] = $title;
                    $storedEvent['event_type'] = $eventType;
                    $storedEvent['response_list'] = $eventType === 'job' ? $responseList : '';
                    $storedEvent['poll_mode'] = $eventType === 'poll' ? $pollMode : '';
                    $storedEvent['poll_options'] = $eventType === 'poll' ? $pollOptions : [];
                    $storedEvent['description_language'] = $descriptionLanguage;
                    $storedEvent['description'] = $description;
                    $storedEvent['description_other'] = $descriptionOther;
                    $storedEvent['start_at'] = $startAt->format(DateTimeInterface::ATOM);
                    $storedEvent['end_at'] = $endAt->format(DateTimeInterface::ATOM);

                    $storedEvent['image'] = $imageFilename;
                    $storedEvent['organizer_user_id'] = $organizerUserId;

                    return true;
                }

                return false;
            });

            if (!$updated) {
                throw new RuntimeException(t('admin.events.error.not_found'));
            }
        } catch (Throwable $exception) {
            throw $exception;
        }

        if ($oldConfigurationSignature !== $newConfigurationSignature) {
            delete_responses_for_events([$eventId]);
            $_SESSION['flash_warning'] = t('admin.event_edit.responses_deleted');
        }

        $_SESSION['flash_success'] = t('admin.event_edit.success', ['title' => $title]);
        redirect_to('admin/events.php');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

render_header(t('admin.event_edit.page_title'), $admin);
?>

<section class="page-heading event-page-heading">
    <div>
        <div class="eyebrow"><?= e(t('common.administration')) ?></div>
        <h1><?= e(t('admin.event_edit.heading')) ?></h1>
        <p class="muted"><?= e(t('admin.event_edit.intro')) ?></p>
    </div>
    <div class="page-heading-actions">
        <a class="button button-secondary" href="<?= e(app_url('admin/events.php')) ?>"><?= e(t('common.back_events')) ?></a>
        <a class="button button-secondary" href="<?= e(app_url('admin/index.php')) ?>"><?= e(t('admin.event_edit.admin_home')) ?></a>
    </div>
</section>

<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="event-create-card">
    <form method="post" class="event-create-form" data-event-form>
        <?= csrf_field() ?>
        <input type="hidden" name="event_id" value="<?= $eventId ?>">

        <div class="event-form-main">
            <label class="event-title-field">
                <span><?= e(t('admin.events.title')) ?></span>
                <input type="text" name="title" value="<?= e($title) ?>" minlength="3" maxlength="100" required autofocus>
            </label>


            <label class="event-type-field event-organizer-field">
                <span><?= e(t('admin.events.organizer')) ?></span>
                <select name="organizer_user_id" required>
                    <option value=""><?= e(t('admin.events.organizer_placeholder')) ?></option>
                    <?php foreach ($organizerMembers as $organizerMember): ?>
                        <?php $organizerWorld = user_character_world($organizerMember); ?>
                        <option value="<?= (int)$organizerMember['id'] ?>" <?= $organizerUserId === (int)$organizerMember['id'] ? 'selected' : '' ?>>
                            <?= e(user_character_name($organizerMember)) ?><?= $organizerWorld !== '' ? ' — ' . e($organizerWorld) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(t('admin.events.organizer_help')) ?></small>
            </label>

            <label class="event-type-field">
                <span><?= e(t('admin.events.description_language')) ?></span>
                <select name="description_language" required>
                    <?php foreach (SUPPORTED_LANGUAGES as $language): ?>
                        <option value="<?= e($language) ?>" <?= $descriptionLanguage === $language ? 'selected' : '' ?>><?= e(language_label($language)) ?></option>
                    <?php endforeach; ?>
                </select>
                <small><?= e(t('admin.events.description_language_help')) ?></small>
            </label>

            <label class="event-description-field">
                <span><?= e(t('admin.events.description_base')) ?></span>
                <textarea name="description" rows="8" maxlength="5000" placeholder="<?= e(t('admin.events.description_base_placeholder')) ?>" required><?= e($description) ?></textarea>
            </label>

            <label class="event-description-field">
                <span><?= e(t('admin.events.description_other')) ?></span>
                <textarea name="description_other" rows="8" maxlength="5000" placeholder="<?= e(t('admin.events.description_other_placeholder')) ?>" required><?= e($descriptionOther) ?></textarea>
            </label>

            <label class="event-type-field">
                <span><?= e(t('admin.events.type')) ?></span>
                <select name="event_type" data-event-type required>
                    <option value="job" <?= $eventType === 'job' ? 'selected' : '' ?>><?= e(t('events.type.job')) ?></option>
                    <option value="poll" <?= $eventType === 'poll' ? 'selected' : '' ?>><?= e(t('events.type.poll')) ?></option>
                </select>
                <small><?= e(t('admin.event_edit.type_warning')) ?></small>
            </label>

            <div class="event-response-config" data-config="job">
                <label class="event-list-field">
                    <span><?= e(t('admin.events.response_list')) ?></span>
                    <select name="response_list" data-job-required>
                        <?php foreach (EVENT_JOB_CATEGORIES as $categoryKey => $categoryLabel): ?>
                            <?php $jobCount = count($jobsData['categories'][$categoryKey] ?? []); ?>
                            <option value="<?= e($categoryKey) ?>" <?= $responseList === $categoryKey ? 'selected' : '' ?>>
                                <?= e(job_category_label($categoryKey)) ?> — <?= e(tp('admin.jobs.category_count.one', 'admin.jobs.category_count.other', $jobCount)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="event-response-config poll-admin-config" data-config="poll">
                <label>
                    <span><?= e(t('admin.events.poll_mode')) ?></span>
                    <select name="poll_mode" data-poll-required>
                        <option value="single" <?= $pollMode === 'single' ? 'selected' : '' ?>><?= e(t('events.poll.single')) ?></option>
                        <option value="multiple" <?= $pollMode === 'multiple' ? 'selected' : '' ?>><?= e(t('events.poll.multiple')) ?></option>
                    </select>
                </label>

                <label>
                    <span><?= e(t('admin.events.poll_options')) ?></span>
                    <textarea name="poll_options" rows="7" maxlength="2500" data-poll-required><?= e($pollOptionsText) ?></textarea>
                    <small><?= e(t('admin.event_edit.options_warning')) ?></small>
                </label>
            </div>
        </div>

        <aside class="event-form-side">
            <div class="event-image-picker">
                <label class="event-image-field">
                    <span><?= e(t('admin.events.image')) ?></span>
                    <select name="image" data-event-image-select required>
                        <option value=""><?= e(t('admin.events.image_select_placeholder')) ?></option>
                        <?php foreach ($availableImages as $availableImage): ?>
                            <option
                                value="<?= e($availableImage) ?>"
                                data-image-url="<?= e(event_image_url($availableImage)) ?>"
                                <?= $imageFilename === $availableImage ? 'selected' : '' ?>
                            ><?= e(event_image_label($availableImage)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small><?= e(t('admin.events.image_help')) ?></small>
                </label>

                <div class="event-image-picker-preview">
                    <img
                        src="<?= $imageFilename !== '' && event_image_is_selectable($imageFilename) ? e(event_image_url($imageFilename)) : '' ?>"
                        alt="<?= e(t('admin.events.image_preview_alt')) ?>"
                        data-event-image-preview
                        <?= $imageFilename !== '' && event_image_is_selectable($imageFilename) ? '' : 'hidden' ?>
                    >
                    <div class="event-image-picker-empty" data-event-image-empty <?= $imageFilename !== '' && event_image_is_selectable($imageFilename) ? 'hidden' : '' ?>>
                        <?= e($availableImages === [] ? t('admin.events.image_library_empty') : t('admin.events.image_preview_empty')) ?>
                    </div>
                </div>
            </div>

            <div class="event-date-grid">
                <label>
                    <span><?= e(t('admin.events.start')) ?></span>
                    <input type="datetime-local" name="start_at" value="<?= e($startAtInput) ?>" required>
                </label>

                <label>
                    <span><?= e(t('admin.events.end')) ?></span>
                    <input type="datetime-local" name="end_at" value="<?= e($endAtInput) ?>" required>
                </label>
            </div>

            <div class="event-timezone-note"><?= e(t('admin.events.timezone')) ?></div>

            <button type="submit" class="button button-primary event-submit-button"><?= e(t('admin.event_edit.save')) ?></button>
        </aside>
    </form>
</section>

<script>
(() => {
    const form = document.querySelector('[data-event-form]');
    if (!form) return;

    const typeSelect = form.querySelector('[data-event-type]');
    const jobConfig = form.querySelector('[data-config="job"]');
    const pollConfig = form.querySelector('[data-config="poll"]');

    const update = () => {
        const isPoll = typeSelect.value === 'poll';
        jobConfig.hidden = isPoll;
        pollConfig.hidden = !isPoll;
        form.querySelectorAll('[data-job-required]').forEach((field) => field.required = !isPoll);
        form.querySelectorAll('[data-poll-required]').forEach((field) => field.required = isPoll);
    };

    const imageSelect = form.querySelector('[data-event-image-select]');
    const imagePreview = form.querySelector('[data-event-image-preview]');
    const imageEmpty = form.querySelector('[data-event-image-empty]');

    const updateImagePreview = () => {
        if (!imageSelect || !imagePreview || !imageEmpty) return;
        const selected = imageSelect.selectedOptions[0];
        const url = selected?.dataset.imageUrl || '';
        if (url) {
            imagePreview.src = url;
            imagePreview.hidden = false;
            imageEmpty.hidden = true;
        } else {
            imagePreview.removeAttribute('src');
            imagePreview.hidden = true;
            imageEmpty.hidden = false;
        }
    };

    typeSelect.addEventListener('change', update);
    imageSelect?.addEventListener('change', updateImagePreview);
    update();
    updateImagePreview();
})();
</script>

<?php render_footer(); ?>
