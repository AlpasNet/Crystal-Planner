<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/jobs_storage.php';
require_once __DIR__ . '/../includes/job_images.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {
            case 'add':
                $category = (string)($_POST['category'] ?? '');
                $name = trim((string)($_POST['name'] ?? ''));

                if (!is_valid_job_category($category)) {
                    throw new RuntimeException(t('admin.jobs.error.invalid_category'));
                }

                if ($name === '' || app_text_length($name) > 50) {
                    throw new RuntimeException(t('admin.jobs.error.name_range'));
                }

                $imageFilename = save_job_image_upload($_FILES['image'] ?? null);

                try {
                    update_jobs_data(function (array &$data) use ($category, $name, $imageFilename): void {
                        if (job_name_exists($data['categories'][$category], $name)) {
                            throw new RuntimeException(t('admin.jobs.error.duplicate'));
                        }

                        $jobId = (int)$data['next_id'];
                        $data['categories'][$category][] = [
                            'id' => $jobId,
                            'name' => $name,
                            'image' => $imageFilename,
                        ];
                        $data['next_id'] = $jobId + 1;
                    });
                } catch (Throwable $exception) {
                    delete_job_image_file($imageFilename);
                    throw $exception;
                }

                $success = t('admin.jobs.success.added_category', ['name' => $name, 'category' => job_category_label($category)]);
                break;

            case 'update':
                $jobId = (int)($_POST['job_id'] ?? 0);
                $category = (string)($_POST['category'] ?? '');
                $name = trim((string)($_POST['name'] ?? ''));

                if ($jobId <= 0 || !is_valid_job_category($category)) {
                    throw new RuntimeException(t('admin.jobs.error.invalid_update'));
                }

                if ($name === '' || app_text_length($name) > 50) {
                    throw new RuntimeException(t('admin.jobs.error.name_range'));
                }

                $newImageFilename = null;
                $upload = $_FILES['image'] ?? null;

                if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $newImageFilename = save_job_image_upload($upload);
                }

                try {
                    $oldImageFilename = update_jobs_data(function (array &$data) use ($jobId, $category, $name, $newImageFilename): string {
                        $location = find_job_location($data, $jobId);

                        if ($location === null || $location['category'] !== $category) {
                            throw new RuntimeException(t('admin.jobs.error.not_found_category'));
                        }

                        if (job_name_exists($data['categories'][$category], $name, $jobId)) {
                            throw new RuntimeException(t('admin.jobs.error.duplicate_name'));
                        }

                        $index = $location['index'];
                        $oldImage = (string)($location['job']['image'] ?? '');

                        if ($newImageFilename === null && !job_image_exists($oldImage)) {
                            throw new RuntimeException(t('admin.jobs.error.image_required_update'));
                        }

                        $data['categories'][$category][$index]['name'] = $name;

                        if ($newImageFilename !== null) {
                            $data['categories'][$category][$index]['image'] = $newImageFilename;
                        }

                        return $oldImage;
                    });
                } catch (Throwable $exception) {
                    if ($newImageFilename !== null) {
                        delete_job_image_file($newImageFilename);
                    }
                    throw $exception;
                }

                if ($newImageFilename !== null && $oldImageFilename !== $newImageFilename) {
                    delete_job_image_file($oldImageFilename);
                }

                $success = t('admin.jobs.success.updated_generic');
                break;

            case 'move_up':
            case 'move_down':
                $jobId = (int)($_POST['job_id'] ?? 0);
                $category = (string)($_POST['category'] ?? '');

                if ($jobId <= 0 || !is_valid_job_category($category)) {
                    throw new RuntimeException(t('admin.jobs.error.invalid_job'));
                }

                update_jobs_data(function (array &$data) use ($jobId, $category, $action): void {
                    $location = find_job_location($data, $jobId);

                    if ($location === null || $location['category'] !== $category) {
                        throw new RuntimeException(t('admin.jobs.error.not_found_category'));
                    }

                    $index = $location['index'];
                    $targetIndex = $action === 'move_up' ? $index - 1 : $index + 1;
                    $lastIndex = count($data['categories'][$category]) - 1;

                    if ($targetIndex < 0 || $targetIndex > $lastIndex) {
                        return;
                    }

                    $temporary = $data['categories'][$category][$targetIndex];
                    $data['categories'][$category][$targetIndex] = $data['categories'][$category][$index];
                    $data['categories'][$category][$index] = $temporary;
                });

                $success = t('admin.jobs.success.moved');
                break;

            case 'reorder':
                $category = (string)($_POST['category'] ?? '');
                $rawOrder = trim((string)($_POST['order'] ?? ''));

                if (!is_valid_job_category($category) || $rawOrder === '') {
                    throw new RuntimeException(t('admin.jobs.error.invalid_reorder'));
                }

                $orderedIds = array_values(array_filter(
                    array_map('intval', explode(',', $rawOrder)),
                    static fn(int $id): bool => $id > 0
                ));

                update_jobs_data(function (array &$data) use ($category, $orderedIds): void {
                    $jobs = $data['categories'][$category] ?? [];
                    $jobsById = [];

                    foreach ($jobs as $job) {
                        $jobsById[(int)$job['id']] = $job;
                    }

                    $existingIds = array_keys($jobsById);
                    $submittedIds = array_values(array_unique($orderedIds));
                    $sortedExistingIds = $existingIds;
                    $sortedSubmittedIds = $submittedIds;
                    sort($sortedExistingIds);
                    sort($sortedSubmittedIds);

                    if (
                        count($submittedIds) !== count($jobs)
                        || $sortedSubmittedIds !== $sortedExistingIds
                    ) {
                        throw new RuntimeException(t('admin.jobs.error.invalid_reorder'));
                    }

                    $reorderedJobs = [];
                    foreach ($submittedIds as $jobId) {
                        $reorderedJobs[] = $jobsById[$jobId];
                    }

                    $data['categories'][$category] = $reorderedJobs;
                });

                $success = t('admin.jobs.success.moved');
                break;

            case 'delete':
                $jobId = (int)($_POST['job_id'] ?? 0);
                $category = (string)($_POST['category'] ?? '');

                if ($jobId <= 0 || !is_valid_job_category($category)) {
                    throw new RuntimeException(t('admin.jobs.error.invalid_job'));
                }

                $deleted = update_jobs_data(function (array &$data) use ($jobId, $category): array {
                    $location = find_job_location($data, $jobId);

                    if ($location === null || $location['category'] !== $category) {
                        throw new RuntimeException(t('admin.jobs.error.not_found_category'));
                    }

                    $job = $location['job'];
                    array_splice($data['categories'][$category], $location['index'], 1);

                    return $job;
                });

                delete_job_image_file((string)($deleted['image'] ?? ''));
                $success = t('admin.jobs.success.deleted', ['name' => (string)$deleted['name']]);
                break;

            default:
                throw new RuntimeException(t('admin.jobs.error.unknown_action'));
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$data = load_jobs_data();
$totalJobs = array_sum(array_map('count', $data['categories']));

render_header(t('admin.jobs.page_title'), $admin);
?>

<section class="page-heading jobs-heading">
    <div class="jobs-heading-copy">
        <div class="eyebrow"><?= e(t('common.administration')) ?></div>
        <h1><?= e(t('admin.jobs.heading')) ?></h1>
        <p class="muted">
            <?= e(t('admin.jobs.intro')) ?>
        </p>
    </div>

    <div class="page-heading-actions page-heading-actions-stacked">
        <a class="button button-secondary" href="<?= e(app_url('admin/index.php')) ?>"><?= e(t('common.back_admin_home')) ?></a>
        <span class="jobs-total">
            <?= e(tp('admin.jobs.total.one', 'admin.jobs.total.other', $totalJobs)) ?>
        </span>
    </div>
</section>

<?php if ($success !== ''): ?>
    <div class="alert alert-success" role="status"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="jobs-grid">
    <?php foreach (EVENT_JOB_CATEGORIES as $categoryKey => $categoryLabel): ?>
        <?php $jobs = $data['categories'][$categoryKey]; ?>
        <article class="job-category-card">
            <header class="job-category-header">
                <div>
                    <div class="job-category-title-line">
                        <h2><?= e(job_category_label($categoryKey)) ?></h2>
                        <span class="job-count-badge">
                            <?= e(tp('admin.jobs.category_count.one', 'admin.jobs.category_count.other', count($jobs))) ?>
                        </span>
                    </div>
                    <p><?= e(t('admin.jobs.category_help')) ?></p>
                </div>
            </header>

            <section class="job-add-section" aria-labelledby="add-<?= e($categoryKey) ?>">
                <div class="job-section-heading">
                    <h3 id="add-<?= e($categoryKey) ?>"><?= e(t('admin.jobs.add_heading')) ?></h3>
                    <p><?= e(t('admin.jobs.add_help')) ?></p>
                </div>

                <form method="post" enctype="multipart/form-data" class="job-add-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="category" value="<?= e($categoryKey) ?>">

                    <div class="job-add-fields">
                        <label>
                            <span><?= e(t('admin.jobs.name')) ?></span>
                            <input
                                type="text"
                                name="name"
                                maxlength="50"
                                placeholder="<?= e(t('admin.jobs.placeholder')) ?>"
                                required
                            >
                        </label>

                        <label>
                            <span><?= e(t('admin.jobs.image')) ?></span>
                            <?php render_file_input('image', 'image/png,image/jpeg,image/webp', true, 'file-input'); ?>
                            <small><?= e(t('admin.jobs.image_help')) ?></small>
                        </label>
                    </div>

                    <div class="job-add-action">
                        <button class="button button-primary" type="submit"><?= e(t('admin.jobs.add')) ?></button>
                    </div>
                </form>
            </section>

            <section class="job-list-section" aria-label="<?= e(t('admin.jobs.list_aria', ['category' => job_category_label($categoryKey)])) ?>">
                <div class="job-section-heading job-list-heading">
                    <h3><?= e(t('admin.jobs.configured')) ?></h3>
                    <p><?= e(t('admin.jobs.list_help')) ?></p>
                </div>

                <form
                    method="post"
                    id="job-reorder-<?= e($categoryKey) ?>"
                    class="sr-only"
                    aria-hidden="true"
                >
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reorder">
                    <input type="hidden" name="category" value="<?= e($categoryKey) ?>">
                    <input type="hidden" name="order" value="">
                </form>

                <div
                    id="job-list-<?= e($categoryKey) ?>"
                    class="job-list"
                    data-sortable-job-list
                    data-reorder-form="job-reorder-<?= e($categoryKey) ?>"
                    data-position-template="<?= e(t('admin.jobs.position_aria', ['position' => '__POSITION__'])) ?>"
                >
                    <?php foreach ($jobs as $index => $job): ?>
                        <form
                            id="job-<?= (int)$job['id'] ?>"
                            method="post"
                            enctype="multipart/form-data"
                            class="job-row"
                            action="<?= e(app_url('admin/jobs.php') . '#job-' . (int)$job['id']) ?>"
                            data-job-row
                            data-job-id="<?= (int)$job['id'] ?>"
                        >
                            <?= csrf_field() ?>
                            <input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
                            <input type="hidden" name="category" value="<?= e($categoryKey) ?>">

                            <div class="job-row-identity">
                                <button
                                    class="job-drag-handle"
                                    type="button"
                                    draggable="true"
                                    title="<?= e(t('admin.jobs.drag_title')) ?>"
                                    aria-label="<?= e(t('admin.jobs.drag_aria', ['name' => (string)$job['name']])) ?>"
                                    data-job-drag-handle
                                >☰</button>

                                <div
                                    class="job-position"
                                    aria-label="<?= e(t('admin.jobs.position_aria', ['position' => $index + 1])) ?>"
                                    data-job-position
                                >
                                    <span data-job-position-number><?= $index + 1 ?></span>
                                </div>

                                <div class="job-visual">
                                    <?php if (job_image_exists((string)($job['image'] ?? ''))): ?>
                                        <img
                                            class="job-image-preview"
                                            src="<?= e(job_image_url((string)$job['image'])) ?>"
                                            alt="<?= e(t('admin.jobs.image_alt', ['name' => (string)$job['name']])) ?>"
                                            loading="lazy"
                                        >
                                    <?php else: ?>
                                        <div class="job-image-preview job-image-missing"><?= e(t('admin.jobs.image_missing')) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="job-row-content">
                                <div class="job-main-fields">
                                    <label>
                                        <span><?= e(t('admin.jobs.name')) ?></span>
                                        <input
                                            class="job-name-input"
                                            type="text"
                                            name="name"
                                            value="<?= e((string)$job['name']) ?>"
                                            maxlength="50"
                                            required
                                        >
                                    </label>

                                    <label>
                                        <span><?= e(t('admin.jobs.replace_image')) ?></span>
                                        <?php render_file_input('image', 'image/png,image/jpeg,image/webp', false, 'file-input'); ?>
                                    </label>
                                </div>

                                <div class="job-row-actions">
                                    <div class="job-order-actions" aria-label="<?= e(t('admin.jobs.order_aria')) ?>">
                                        <button
                                            class="icon-button"
                                            type="submit"
                                            name="action"
                                            value="move_up"
                                            title="<?= e(t('admin.jobs.up_title')) ?>"
                                            aria-label="<?= e(t('admin.jobs.up_aria', ['name' => (string)$job['name']])) ?>"
                                            <?= $index === 0 ? 'disabled' : '' ?>
                                        >↑</button>

                                        <button
                                            class="icon-button"
                                            type="submit"
                                            name="action"
                                            value="move_down"
                                            title="<?= e(t('admin.jobs.down_title')) ?>"
                                            aria-label="<?= e(t('admin.jobs.down_aria', ['name' => (string)$job['name']])) ?>"
                                            <?= $index === count($jobs) - 1 ? 'disabled' : '' ?>
                                        >↓</button>
                                    </div>

                                    <button class="button button-small job-save-button" type="submit" name="action" value="update">
                                        <?= e(t('common.save')) ?>
                                    </button>

                                    <button
                                        class="button button-danger button-small"
                                        type="submit"
                                        name="action"
                                        value="delete"
                                        onclick="return confirm('<?= e(t('admin.jobs.delete_confirm')) ?>');"
                                    ><?= e(t('common.delete')) ?></button>
                                </div>
                            </div>
                        </form>
                    <?php endforeach; ?>

                    <?php if ($jobs === []): ?>
                        <div class="job-empty-state">
                            <strong><?= e(t('admin.jobs.empty_title')) ?></strong>
                            <span><?= e(t('admin.jobs.empty_help')) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </article>
    <?php endforeach; ?>
</section>

<?php render_footer(); ?>
