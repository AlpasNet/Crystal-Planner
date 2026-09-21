<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/jobs_storage.php';
require_once __DIR__ . '/includes/job_images.php';
require_once __DIR__ . '/includes/events_storage.php';
require_once __DIR__ . '/includes/event_images.php';
require_once __DIR__ . '/includes/event_responses_storage.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_member();
purge_expired_events();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');
    $eventId = (int)($_POST['event_id'] ?? 0);
    $event = find_event_by_id($eventId);
    $eventReturnPath = $eventId > 0 ? 'events.php?event_id=' . $eventId : 'events.php';

    try {
        if ($event === null || is_event_expired($event)) {
            throw new RuntimeException(t('events_answer.error.unavailable'));
        }

        if ($action === 'delete') {
            delete_event_response($eventId, (int)$user['id']);
            $_SESSION['flash_success'] = t('events_answer.success.removed');
            redirect_to($eventReturnPath);
        }

        if ($action !== 'save') {
            throw new RuntimeException(t('events_answer.error.invalid_action'));
        }

        if (is_poll_event($event)) {
            $submittedOptionIds = $_POST['option_ids'] ?? [];
            if (!is_array($submittedOptionIds)) {
                $submittedOptionIds = [$submittedOptionIds];
            }
            $submittedOptionIds = array_values(array_unique(array_filter(
                array_map('intval', $submittedOptionIds),
                static fn(int $optionId): bool => $optionId > 0
            )));

            $validOptionIds = [];
            foreach (($event['poll_options'] ?? []) as $option) {
                $validOptionIds[(int)$option['id']] = true;
            }
            foreach ($submittedOptionIds as $optionId) {
                if (!isset($validOptionIds[$optionId])) {
                    throw new RuntimeException(t('events_answer.error.poll_missing'));
                }
            }

            $pollMode = (string)($event['poll_mode'] ?? 'single');
            if ($pollMode === 'single' && count($submittedOptionIds) !== 1) {
                throw new RuntimeException(t('events_answer.error.poll_single_select'));
            }
            if ($pollMode === 'multiple' && $submittedOptionIds === []) {
                throw new RuntimeException(t('events_answer.error.poll_multiple_select'));
            }
            save_poll_event_response($eventId, (int)$user['id'], $submittedOptionIds);
        } else {
            $jobId = (int)($_POST['job_id'] ?? 0);
            $jobsData = load_jobs_data();
            $job = find_job_in_category($jobsData, (string)$event['response_list'], $jobId);
            if ($job === null) {
                throw new RuntimeException(t('events_answer.error.job_select'));
            }

            $submittedPositions = $_POST['positions'] ?? [];
            if (!is_array($submittedPositions)) {
                $submittedPositions = [$submittedPositions];
            }
            $positions = normalize_event_positions($submittedPositions);
            save_job_event_response($eventId, (int)$user['id'], (int)$job['id'], (string)$job['name'], $positions);
        }

        $_SESSION['flash_success'] = t('events_answer.success.saved_title', ['title' => (string)$event['title']]);
        redirect_to($eventReturnPath);
    } catch (RuntimeException $exception) {
        $_SESSION['flash_error'] = $exception->getMessage();
        redirect_to($eventReturnPath);
    }
}

$eventsData = load_events_data();
$jobsData = load_jobs_data();
$responsesData = load_event_responses_data();
$usersData = load_users_data();
$pollHues = [262, 205, 150, 38, 330, 178, 12, 290];
$events = array_values(array_filter($eventsData['events'], static fn(array $event): bool => !is_event_expired($event)));
usort($events, static fn(array $left, array $right): int => strcmp((string)$left['start_at'], (string)$right['start_at']));
$now = new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));

$usersById = [];
foreach ($usersData['users'] as $registeredUser) {
    if (!in_array((string)($registeredUser['status'] ?? ''), ['member', 'admin'], true)) {
        continue;
    }
    $usersById[(int)$registeredUser['id']] = $registeredUser;
}

$requestedEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$selectedEvent = null;
if ($requestedEventId > 0) {
    foreach ($events as $availableEvent) {
        if ((int)$availableEvent['id'] === $requestedEventId) {
            $selectedEvent = $availableEvent;
            break;
        }
    }
}
$invalidRequestedEvent = isset($_GET['event_id']) && $selectedEvent === null;

render_header(t('events_answer.page_title'), $user);
?>
<section class="page-heading member-events-heading">
    <div>
        <div class="eyebrow"><?= e(t('events_answer.eyebrow')) ?></div>
        <h1><?= e(t('events_answer.heading')) ?></h1>
        <p class="muted"><?= e($selectedEvent !== null ? t('events_answer.intro') : t('events_answer.gallery_intro')) ?></p>
    </div>
    <div class="page-heading-actions page-heading-actions-stacked">
        <?php if ($selectedEvent !== null): ?>
            <a class="button button-secondary" href="<?= e(app_url('events.php')) ?>"><?= e(t('common.back_events')) ?></a>
        <?php endif; ?>
        <a class="button button-secondary" href="<?= e(app_url('home.php')) ?>"><?= e(t('common.back_home')) ?></a>
        <?php if ($selectedEvent === null): ?>
            <span class="members-count"><?= e(tp('events_answer.count.one', 'events_answer.count.other', count($events))) ?></span>
        <?php endif; ?>
    </div>
</section>

<?php if ($invalidRequestedEvent): ?>
<div class="alert alert-error" role="alert"><?= e(t('events_answer.error.event_not_found')) ?></div>
<?php endif; ?>

<?php if ($events === []): ?>
<section class="content-card member-events-empty">
    <div class="status-icon" aria-hidden="true">📅</div>
    <h2><?= e(t('events_answer.empty.title')) ?></h2>
    <p class="muted"><?= e(t('events_answer.empty.text')) ?></p>
</section>
<?php elseif ($selectedEvent === null): ?>
<section class="member-event-gallery" aria-label="<?= e(t('events_answer.heading')) ?>">
<?php foreach ($events as $event):
    $eventId = (int)$event['id'];
    $response = find_event_response_for_user($eventId, (int)$user['id'], $responsesData);
    $eventOrganizer = $usersById[(int)($event['organizer_user_id'] ?? 0)] ?? null;
    $startAt = event_start_datetime($event);
    $isStarted = $startAt !== null && $startAt <= $now;
?>
    <a class="member-event-gallery-card" href="<?= e(app_url('events.php?event_id=' . $eventId)) ?>">
        <div class="member-event-gallery-image-wrap">
            <?php if (event_image_exists((string)$event['image'])): ?>
                <img class="member-event-gallery-image" src="<?= e(event_image_url((string)$event['image'])) ?>" alt="<?= e(t('aria.event_image', ['title' => (string)$event['title']])) ?>" loading="lazy">
            <?php else: ?>
                <div class="member-event-gallery-image event-image-missing"><?= e(t('common.image_unavailable')) ?></div>
            <?php endif; ?>
            <div class="member-event-status-stack">
                <span class="member-event-state member-event-state-inline <?= $isStarted ? 'is-live' : 'is-upcoming' ?>"><?= e($isStarted ? t('common.current') : t('common.upcoming')) ?></span>
                <?php if ($response !== null): ?><span class="response-saved-badge response-saved-badge-overlay"><?= e(t('events_answer.response_saved')) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="member-event-gallery-title-row">
            <h2><?= e((string)$event['title']) ?></h2>
            <div class="member-event-gallery-dates">
                <span><strong><?= e(t('common.start')) ?>:</strong> <?= e(format_event_datetime((string)$event['start_at'])) ?></span>
                <span><strong><?= e(t('events_answer.end_deadline')) ?>:</strong> <?= e(format_event_datetime((string)$event['end_at'])) ?></span>
                <?php if ($eventOrganizer !== null): ?><span><strong><?= e(t('events_answer.organizer')) ?>:</strong> <?= e(user_character_name($eventOrganizer)) ?></span><?php endif; ?>
            </div>
        </div>
    </a>
<?php endforeach; ?>
</section>
<?php else:
    $event = $selectedEvent;
    $eventId = (int)$event['id'];
    $response = find_event_response_for_user($eventId, (int)$user['id'], $responsesData);
    $eventOrganizer = $usersById[(int)($event['organizer_user_id'] ?? 0)] ?? null;
    $startAt = event_start_datetime($event);
    $isStarted = $startAt !== null && $startAt <= $now;
    $isPoll = is_poll_event($event);
    $selectedOptionIds = array_map('intval', $response['option_ids'] ?? []);
    $selectedJobId = (int)($response['job_id'] ?? 0);
    $selectedPositions = normalize_event_positions($response['positions'] ?? []);
    $category = (string)($event['response_list'] ?? '');
    $jobs = $isPoll ? [] : ($jobsData['categories'][$category] ?? []);
?>
<section class="member-event-list member-event-detail-list">
<article class="member-event-card">
    <div class="member-event-cover-wrap">
        <?php if (event_image_exists((string)$event['image'])): ?>
            <img class="member-event-cover" src="<?= e(event_image_url((string)$event['image'])) ?>" alt="<?= e(t('aria.event_image', ['title' => (string)$event['title']])) ?>" loading="lazy">
        <?php else: ?><div class="member-event-cover event-image-missing"><?= e(t('common.image_unavailable')) ?></div><?php endif; ?>
        <div class="member-event-status-stack">
            <span class="member-event-state member-event-state-inline <?= $isStarted ? 'is-live' : 'is-upcoming' ?>"><?= e($isStarted ? t('common.current') : t('common.upcoming')) ?></span>
            <?php if ($response !== null): ?><span class="response-saved-badge response-saved-badge-overlay"><?= e(t('events_answer.response_saved')) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="member-event-body">
        <div class="member-event-title-row">
            <div><h2><?= e((string)$event['title']) ?></h2><span class="event-list-badge <?= $isPoll ? 'is-poll' : '' ?>"><?= e(event_response_configuration_label($event)) ?></span></div>
        </div>
        <div class="member-event-dates">
            <span><strong><?= e(t('common.start')) ?>:</strong> <?= e(format_event_datetime((string)$event['start_at'])) ?></span>
            <span><strong><?= e(t('events_answer.end_deadline')) ?>:</strong> <?= e(format_event_datetime((string)$event['end_at'])) ?></span>
            <?php if ($eventOrganizer !== null): ?><span><strong><?= e(t('events_answer.organizer')) ?>:</strong> <?= e(user_character_name($eventOrganizer)) ?></span><?php endif; ?>
        </div>
        <div class="member-event-description"><?= nl2br(e(event_description_for_language($event))) ?></div>

        <div class="live-responses-section member-event-responses-section">
        <?php if ($isPoll):
            $pollResult = poll_results($event, $responsesData); ?>
            <div class="members-section-heading compact-heading">
                <div>
                    <h3><?= e(t('events_answer.results.heading')) ?></h3>
                    <p class="muted"><?= e(t('available.poll.no_names')) ?> <?php if ($event['poll_mode'] === 'multiple'): ?><?= e(t('available.poll.multiple_help')) ?><?php endif; ?></p>
                </div>
                <span class="members-count"><?= e(tp('common.voter', 'common.voters', (int)$pollResult['respondents'], ['count' => (int)$pollResult['respondents']])) ?></span>
            </div>
            <div class="poll-result-list poll-result-list-large">
            <?php foreach ($pollResult['options'] as $index => $resultOption): ?>
                <?php if ($event['poll_mode'] === 'multiple'): ?>
                    <div class="poll-result-row">
                        <div class="poll-result-label">
                            <span><?= e((string)$resultOption['label']) ?></span>
                            <strong><?= e(tp('events_answer.results.people.one', 'events_answer.results.people.other', (int)$resultOption['count'], ['count' => (int)$resultOption['count']])) ?></strong>
                        </div>
                    </div>
                <?php else:
                    $hue = $pollHues[$index % count($pollHues)];
                    $percentage = localized_number((float)$resultOption['percentage']); ?>
                    <div class="poll-result-row" style="--poll-color: hsl(<?= $hue ?> 76% 58%);">
                        <div class="poll-result-label"><span><?= e((string)$resultOption['label']) ?></span><strong><?= e($percentage) ?> %</strong></div>
                        <div class="poll-result-track" role="img" aria-label="<?= e((string)$resultOption['label'] . ' : ' . $percentage . ' %') ?>"><span style="width: <?= e((string)min(100, (float)$resultOption['percentage'])) ?>%"></span></div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
        <?php else:
            $eventResponses = find_event_responses($eventId, $responsesData);
            $visibleResponses = [];
            foreach ($eventResponses as $participantResponse) {
                if ((string)($participantResponse['response_type'] ?? 'job') !== 'job') {
                    continue;
                }
                $respondent = $usersById[(int)$participantResponse['user_id']] ?? null;
                if ($respondent === null) {
                    continue;
                }
                $visibleResponses[] = ['response' => $participantResponse, 'user' => $respondent];
            }
            usort($visibleResponses, static fn(array $left, array $right): int => strcasecmp(user_character_name($left['user']), user_character_name($right['user'])));
        ?>
            <div class="members-section-heading compact-heading">
                <div><h3><?= e(t('available.participants.heading')) ?></h3><p class="muted"><?= e(t('available.participants.help')) ?></p></div>
                <span class="members-count"><?= e(tp('common.response', 'common.responses', count($visibleResponses), ['count' => count($visibleResponses)])) ?></span>
            </div>
            <?php if ($visibleResponses === []): ?>
                <div class="live-responses-empty"><?= e(t('available.participants.empty')) ?></div>
            <?php else: ?>
            <div class="participant-grid">
            <?php foreach ($visibleResponses as $item):
                $participantResponse = $item['response'];
                $respondent = $item['user'];
                $participantJob = find_job_in_category($jobsData, (string)$event['response_list'], (int)$participantResponse['job_id']);
                $participantPositions = normalize_event_positions($participantResponse['positions'] ?? []);
            ?>
                <article class="participant-card">
                    <?php render_user_avatar($respondent, 'participant-avatar'); ?>
                    <div class="participant-identity">
                        <strong><?= e(user_character_name($respondent)) ?></strong>
                        <?php if (user_character_world($respondent) !== ''): ?><small><?= e(user_character_world($respondent)) ?></small><?php endif; ?>
                        <?php if ($participantPositions !== []): ?>
                            <div class="participant-positions" aria-label="<?= e(t('available.positions_selected', ['positions' => format_event_positions($participantPositions)])) ?>">
                                <?php foreach ($participantPositions as $position): ?><span><?= e($position) ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="participant-job" aria-label="<?= e(t('available.job_selected', ['job' => (string)$participantResponse['job_name']])) ?>" title="<?= e((string)$participantResponse['job_name']) ?>">
                        <?php if ($participantJob !== null && job_image_exists((string)$participantJob['image'])): ?>
                            <img src="<?= e(job_image_url((string)$participantJob['image'])) ?>" alt="" loading="lazy">
                        <?php else: ?>
                            <span class="participant-job-placeholder" aria-hidden="true"><?= e(app_text_initial((string)$participantResponse['job_name'])) ?></span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        </div>

        <div class="member-response-section">
        <?php if ($isPoll): ?>
            <div class="member-response-heading">
                <div><h3><?= e(t('events_answer.poll.heading')) ?></h3><p class="muted"><?= e($event['poll_mode'] === 'multiple' ? t('events_answer.poll.multiple_help') : t('events_answer.poll.single_help')) ?></p></div>
                <?php if ($response !== null): ?><span class="current-response-text"><?= e(tp('events_answer.poll.selected.one', 'events_answer.poll.selected.other', count($selectedOptionIds))) ?></span><?php endif; ?>
            </div>
            <form method="post" class="member-response-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="poll-choice-list">
                <?php foreach ($event['poll_options'] as $option):
                    $optionId = (int)$option['id'];
                    $inputId = 'event-' . $eventId . '-poll-' . $optionId;
                    $inputType = $event['poll_mode'] === 'multiple' ? 'checkbox' : 'radio';
                ?>
                    <label class="poll-choice" for="<?= e($inputId) ?>"><input id="<?= e($inputId) ?>" type="<?= e($inputType) ?>" name="option_ids[]" value="<?= $optionId ?>" <?= in_array($optionId, $selectedOptionIds, true) ? 'checked' : '' ?> <?= $inputType === 'radio' ? 'required' : '' ?>><span><?= e((string)$option['label']) ?></span></label>
                <?php endforeach; ?>
                </div>
                <div class="member-response-actions"><button type="submit" class="button button-primary"><?= e($response === null ? t('events_answer.save') : t('events_answer.update')) ?></button></div>
            </form>
            <?php if ($response !== null): ?><form method="post" class="remove-response-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="event_id" value="<?= $eventId ?>"><button type="submit" class="button button-secondary"><?= e(t('events_answer.remove')) ?></button></form><?php endif; ?>
        <?php else: ?>
            <div class="member-response-heading">
                <div><h3><?= e(t('events_answer.job.heading')) ?></h3><p class="muted"><?= e(t('events_answer.job.help', ['category' => job_category_label($category)])) ?></p></div>
                <?php if ($response !== null): ?>
                    <div class="current-response-summary">
                        <span class="current-response-text"><?= e(t('events_answer.job.current', ['job' => (string)$response['job_name']])) ?></span>
                        <?php if ($selectedPositions !== []): ?><span class="current-response-positions"><?= e(t('events_answer.positions.current', ['positions' => format_event_positions($selectedPositions)])) ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($jobs === []): ?><div class="alert alert-warning"><?= e(t('events_answer.job.none')) ?></div>
            <?php else: ?>
            <form method="post" class="member-response-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="event_id" value="<?= $eventId ?>">
                <div class="job-choice-grid">
                <?php foreach ($jobs as $job):
                    $jobId = (int)$job['id'];
                    $inputId = 'event-' . $eventId . '-job-' . $jobId;
                ?>
                    <label class="job-choice" for="<?= e($inputId) ?>" title="<?= e((string)$job['name']) ?>" aria-label="<?= e((string)$job['name']) ?>"><input id="<?= e($inputId) ?>" type="radio" name="job_id" value="<?= $jobId ?>" aria-label="<?= e((string)$job['name']) ?>" <?= $selectedJobId === $jobId ? 'checked' : '' ?> required>
                    <?php if (job_image_exists((string)$job['image'])): ?><img src="<?= e(job_image_url((string)$job['image'])) ?>" alt="<?= e((string)$job['name']) ?>" loading="lazy"><?php else: ?><span class="job-choice-placeholder" aria-hidden="true"><?= e(app_text_initial((string)$job['name'])) ?></span><?php endif; ?></label>
                <?php endforeach; ?>
                </div>
                <fieldset class="position-choice-section">
                    <legend><?= e(t('events_answer.positions.heading')) ?></legend>
                    <p class="muted"><?= e(t('events_answer.positions.help')) ?></p>
                    <div class="position-choice-grid">
                    <?php foreach (EVENT_POSITION_CHOICES as $position):
                        $positionId = 'event-' . $eventId . '-position-' . $position;
                    ?>
                        <label class="position-choice" for="<?= e($positionId) ?>">
                            <input id="<?= e($positionId) ?>" type="checkbox" name="positions[]" value="<?= e($position) ?>" <?= in_array($position, $selectedPositions, true) ? 'checked' : '' ?>>
                            <span><?= e($position) ?></span>
                        </label>
                    <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="member-response-actions"><button type="submit" class="button button-primary"><?= e($response === null ? t('events_answer.save') : t('events_answer.update')) ?></button></div>
            </form>
            <?php if ($response !== null): ?><form method="post" class="remove-response-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="event_id" value="<?= $eventId ?>"><button type="submit" class="button button-secondary"><?= e(t('events_answer.remove')) ?></button></form><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </div>
</article>
</section>
<?php endif; ?>
<?php render_footer(); ?>
