<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/guides_storage.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$success = '';
$error = '';
$currentData = load_guides_data();
$currentGuidesById = [];

foreach ($currentData['guides'] as $currentGuide) {
    $currentGuidesById[(int)$currentGuide['id']] = $currentGuide;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $newUploadedPaths = [];

    try {
        $submittedGuides = $_POST['guides'] ?? [];
        if (!is_array($submittedGuides)) {
            throw new RuntimeException(t('admin.guides.error.invalid_data'));
        }

        if (count($submittedGuides) > 50) {
            throw new RuntimeException(t('admin.guides.error.too_many'));
        }

        $guidesToSave = [];

        foreach (array_values($submittedGuides) as $index => $submittedGuide) {
            if (!is_array($submittedGuide)) {
                throw new RuntimeException(t('admin.guides.error.invalid_data'));
            }

            $position = $index + 1;
            $id = max(0, (int)($submittedGuide['id'] ?? 0));
            $title = normalize_guide_text((string)($submittedGuide['title'] ?? ''));
            $description = normalize_guide_text((string)($submittedGuide['description'] ?? ''));
            $link = trim((string)($submittedGuide['link'] ?? ''));
            $removeImage = (string)($submittedGuide['remove_image'] ?? '0') === '1';
            $currentGuide = $id > 0 ? ($currentGuidesById[$id] ?? null) : null;

            if ($title === '' || $description === '' || $link === '') {
                throw new RuntimeException(t('admin.guides.error.required', ['position' => $position]));
            }

            if (app_text_length($title) > 256) {
                throw new RuntimeException(t('admin.guides.error.title_too_long', ['position' => $position]));
            }

            if (app_text_length($description) > 4096) {
                throw new RuntimeException(t('admin.guides.error.description_too_long', ['position' => $position]));
            }

            if (!is_valid_public_http_url($link)) {
                throw new RuntimeException(t('admin.guides.error.invalid_link', ['position' => $position]));
            }

            $imagePath = '';
            $imageUrl = '';
            $uploadedFile = guide_upload_for_index($index);

            if ($uploadedFile !== null) {
                $imagePath = save_guide_uploaded_image($uploadedFile);
                $newUploadedPaths[] = $imagePath;
            } elseif (!$removeImage && is_array($currentGuide)) {
                $imagePath = normalize_guide_image_path((string)($currentGuide['image_path'] ?? ''));
            }

            if ($imagePath === '') {
                throw new RuntimeException(t('admin.guides.error.image_required', ['position' => $position]));
            }

            $guidesToSave[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'link' => $link,
                'image_path' => $imagePath,
            ];
        }

        $savedData = save_guides_data($guidesToSave);

        $oldImagePaths = [];
        foreach ($currentData['guides'] as $guide) {
            $path = normalize_guide_image_path((string)($guide['image_path'] ?? ''));
            if ($path !== '') $oldImagePaths[$path] = true;
        }

        $savedImagePaths = [];
        foreach ($savedData['guides'] as $guide) {
            $path = normalize_guide_image_path((string)($guide['image_path'] ?? ''));
            if ($path !== '') $savedImagePaths[$path] = true;
        }

        foreach (array_keys($oldImagePaths) as $oldImagePath) {
            if (!isset($savedImagePaths[$oldImagePath])) {
                delete_guide_uploaded_image($oldImagePath);
            }
        }

        delete_unused_guide_images($savedData['guides']);

        $currentData = $savedData;
        $currentGuidesById = [];
        foreach ($currentData['guides'] as $currentGuide) {
            $currentGuidesById[(int)$currentGuide['id']] = $currentGuide;
        }

        $success = t('admin.guides.success.saved', ['count' => count($guidesToSave)]);
    } catch (RuntimeException $exception) {
        foreach ($newUploadedPaths as $newUploadedPath) {
            delete_guide_uploaded_image($newUploadedPath);
        }
        $error = $exception->getMessage();
    }
}

$guides = $currentData['guides'];
$updatedAt = trim((string)($currentData['updated_at'] ?? ''));

render_header(t('admin.guides.page_title'), $admin);
?>
<section class="page-heading rules-page-heading">
    <div>
        <div class="eyebrow"><?= e(t('common.administration')) ?></div>
        <h1><?= e(t('admin.guides.heading')) ?></h1>
        <p class="muted"><?= e(t('admin.guides.intro')) ?></p>
    </div>
    <div class="page-heading-actions page-heading-actions-stacked">
        <a class="button button-secondary" href="<?= e(app_url('admin/index.php')) ?>"><?= e(t('common.back_admin_home')) ?></a>
        <a class="button button-secondary" href="<?= e(app_url('discord-guides.json')) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('admin.guides.open_json')) ?></a>
    </div>
</section>

<?php if ($success !== ''): ?>
    <div class="alert alert-success" role="status"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="content-card rules-information-card">
    <div>
        <h2><?= e(t('admin.guides.discord_title')) ?></h2>
        <p class="muted"><?= e(t('admin.guides.discord_text')) ?></p>
    </div>
    <div class="rules-json-summary">
        <span><?= e(t('admin.guides.file_label')) ?></span>
        <code>discord-guides.json</code>
        <?php if ($updatedAt !== ''): ?>
            <small><?= e(t('admin.guides.updated_at', ['date' => $updatedAt])) ?></small>
        <?php endif; ?>
    </div>
</section>

<form class="rules-editor-form" method="post" enctype="multipart/form-data" data-guides-form>
    <?= csrf_field() ?>

    <div class="rules-toolbar">
        <div>
            <h2><?= e(t('admin.guides.blocks_title')) ?></h2>
            <p class="muted"><?= e(t('admin.guides.blocks_text')) ?></p>
        </div>
        <button class="button button-secondary" type="button" data-add-guide><?= e(t('admin.guides.add_block')) ?></button>
    </div>

    <div class="rules-list" data-guides-list>
        <?php foreach ($guides as $index => $guide): ?>
            <?php
                $imagePath = normalize_guide_image_path((string)($guide['image_path'] ?? ''));
                $imageUrl = trim((string)($guide['image'] ?? ''));
            ?>
            <article class="rule-editor-card guide-editor-card" data-guide-card data-current-image="<?= e($imageUrl) ?>">
                <div class="rule-editor-header">
                    <div class="rule-editor-heading">
                        <button class="rule-drag-handle" type="button" aria-label="<?= e(t('admin.guides.drag_aria')) ?>" title="<?= e(t('admin.guides.drag_aria')) ?>" data-drag-handle draggable="true">⋮⋮</button>
                        <div>
                            <span class="rule-number" data-guide-number><?= e(t('admin.guides.block_number', ['number' => $index + 1])) ?></span>
                            <small><?= e(t('admin.guides.embed_hint')) ?></small>
                        </div>
                    </div>
                    <div class="rule-editor-actions">
                        <button class="button button-small" type="button" data-move-up aria-label="<?= e(t('admin.guides.move_up')) ?>" title="<?= e(t('admin.guides.move_up')) ?>">↑</button>
                        <button class="button button-small" type="button" data-move-down aria-label="<?= e(t('admin.guides.move_down')) ?>" title="<?= e(t('admin.guides.move_down')) ?>">↓</button>
                        <button class="button button-danger" type="button" data-remove-guide><?= e(t('common.delete')) ?></button>
                    </div>
                </div>

                <input type="hidden" name="guides[<?= $index ?>][id]" value="<?= (int)$guide['id'] ?>" data-guide-id>
                <input type="hidden" name="guides[<?= $index ?>][remove_image]" value="0" data-remove-image-value>

                <div class="rule-editor-grid guide-editor-grid">
                    <div class="rule-fields guide-fields">
                        <label>
                            <span><?= e(t('admin.guides.title_label')) ?></span>
                            <input type="text" name="guides[<?= $index ?>][title]" value="<?= e((string)$guide['title']) ?>" maxlength="256" required data-guide-title>
                            <small class="field-counter" data-title-counter></small>
                        </label>

                        <label>
                            <span><?= e(t('admin.guides.description_label')) ?></span>
                            <textarea name="guides[<?= $index ?>][description]" rows="7" maxlength="4096" required data-guide-description><?= e((string)$guide['description']) ?></textarea>
                            <small class="field-counter" data-description-counter></small>
                        </label>

                        <label>
                            <span><?= e(t('admin.guides.link_label')) ?></span>
                            <input type="url" name="guides[<?= $index ?>][link]" value="<?= e((string)$guide['link']) ?>" maxlength="2048" placeholder="https://..." required data-guide-link>
                        </label>

                        <div class="guide-image-fields">
                            <label>
                                <span><?= e(t('admin.guides.image_upload_label')) ?></span>
                                <input type="file" name="guide_images[<?= $index ?>]" accept="image/png,image/jpeg,image/gif,image/webp" data-guide-image-file>
                                <small><?= e(t('admin.guides.image_upload_hint')) ?></small>
                            </label>
                        </div>

                        <button class="button button-secondary guide-remove-image" type="button" data-remove-image><?= e(t('admin.guides.remove_image')) ?></button>
                        <p class="rule-markdown-note"><?= e(t('admin.guides.markdown_hint')) ?></p>
                    </div>

                    <aside class="rule-discord-preview" aria-live="polite">
                        <span class="rule-preview-label"><?= e(t('admin.guides.preview')) ?></span>
                        <div class="rule-preview-embed guide-preview-embed">
                            <a href="#" target="_blank" rel="noopener noreferrer" data-preview-title></a>
                            <div data-preview-description></div>
                            <img src="<?= e($imageUrl) ?>" alt="" data-preview-image <?= $imageUrl === '' ? 'hidden' : '' ?>>
                            <span class="guide-preview-link" data-preview-link></span>
                        </div>
                    </aside>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="rules-empty-state" data-guides-empty <?= $guides !== [] ? 'hidden' : '' ?>>
        <h2><?= e(t('admin.guides.empty_title')) ?></h2>
        <p class="muted"><?= e(t('admin.guides.empty_text')) ?></p>
        <button class="button button-secondary" type="button" data-add-guide><?= e(t('admin.guides.add_first_block')) ?></button>
    </div>

    <div class="rules-save-bar">
        <span class="muted" data-guides-count></span>
        <button class="button button-primary" type="submit"><?= e(t('admin.guides.save')) ?></button>
    </div>
</form>

<template data-guide-template>
    <article class="rule-editor-card guide-editor-card" data-guide-card data-current-image="">
        <div class="rule-editor-header">
            <div class="rule-editor-heading">
                <button class="rule-drag-handle" type="button" aria-label="<?= e(t('admin.guides.drag_aria')) ?>" title="<?= e(t('admin.guides.drag_aria')) ?>" data-drag-handle draggable="true">⋮⋮</button>
                <div>
                    <span class="rule-number" data-guide-number></span>
                    <small><?= e(t('admin.guides.embed_hint')) ?></small>
                </div>
            </div>
            <div class="rule-editor-actions">
                <button class="button button-small" type="button" data-move-up aria-label="<?= e(t('admin.guides.move_up')) ?>" title="<?= e(t('admin.guides.move_up')) ?>">↑</button>
                <button class="button button-small" type="button" data-move-down aria-label="<?= e(t('admin.guides.move_down')) ?>" title="<?= e(t('admin.guides.move_down')) ?>">↓</button>
                <button class="button button-danger" type="button" data-remove-guide><?= e(t('common.delete')) ?></button>
            </div>
        </div>

        <input type="hidden" value="0" data-guide-id>
        <input type="hidden" value="0" data-remove-image-value>

        <div class="rule-editor-grid guide-editor-grid">
            <div class="rule-fields guide-fields">
                <label>
                    <span><?= e(t('admin.guides.title_label')) ?></span>
                    <input type="text" maxlength="256" required data-guide-title>
                    <small class="field-counter" data-title-counter></small>
                </label>

                <label>
                    <span><?= e(t('admin.guides.description_label')) ?></span>
                    <textarea rows="7" maxlength="4096" required data-guide-description></textarea>
                    <small class="field-counter" data-description-counter></small>
                </label>

                <label>
                    <span><?= e(t('admin.guides.link_label')) ?></span>
                    <input type="url" maxlength="2048" placeholder="https://..." required data-guide-link>
                </label>

                <div class="guide-image-fields">
                    <label>
                        <span><?= e(t('admin.guides.image_upload_label')) ?></span>
                        <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-guide-image-file>
                        <small><?= e(t('admin.guides.image_upload_hint')) ?></small>
                    </label>
                </div>

                <button class="button button-secondary guide-remove-image" type="button" data-remove-image><?= e(t('admin.guides.remove_image')) ?></button>
                <p class="rule-markdown-note"><?= e(t('admin.guides.markdown_hint')) ?></p>
            </div>

            <aside class="rule-discord-preview" aria-live="polite">
                <span class="rule-preview-label"><?= e(t('admin.guides.preview')) ?></span>
                <div class="rule-preview-embed guide-preview-embed">
                    <a href="#" target="_blank" rel="noopener noreferrer" data-preview-title></a>
                    <div data-preview-description></div>
                    <img src="" alt="" data-preview-image hidden>
                    <span class="guide-preview-link" data-preview-link></span>
                </div>
            </aside>
        </div>
    </article>
</template>

<script>
(() => {
    const form = document.querySelector('[data-guides-form]');
    const list = form?.querySelector('[data-guides-list]');
    const template = document.querySelector('[data-guide-template]');
    const emptyState = form?.querySelector('[data-guides-empty]');
    const countLabel = form?.querySelector('[data-guides-count]');

    if (!form || !list || !template || !emptyState || !countLabel) return;

    const labels = {
        block: <?= json_encode(t('admin.guides.block_number', ['number' => '__NUMBER__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        count: <?= json_encode(t('admin.guides.block_count', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        titlePlaceholder: <?= json_encode(t('admin.guides.preview_title_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        descriptionPlaceholder: <?= json_encode(t('admin.guides.preview_description_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        linkPlaceholder: <?= json_encode(t('admin.guides.preview_link_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        titleCounter: <?= json_encode(t('admin.guides.title_counter', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        descriptionCounter: <?= json_encode(t('admin.guides.description_counter', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };

    let draggedCard = null;

    const textLength = (value) => Array.from(value || '').length;
    const validWebUrl = (value) => /^https?:\/\//i.test(String(value || '').trim());

    const renderDiscordMarkdown = (value) => {
        const escaped = String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        return escaped
            .replace(/`([^`\n]+)`/g, '<code>$1</code>')
            .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
            .replace(/__([^_\n]+)__/g, '<u>$1</u>')
            .replace(/~~([^~\n]+)~~/g, '<s>$1</s>')
            .replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    };

    const setPreviewImage = (card, source) => {
        const previewImage = card.querySelector('[data-preview-image]');
        if (!previewImage) return;

        const value = String(source || '').trim();
        if (value === '') {
            previewImage.hidden = true;
            previewImage.removeAttribute('src');
            return;
        }

        previewImage.src = value;
        previewImage.hidden = false;
    };

    const refreshCard = (card) => {
        const title = card.querySelector('[data-guide-title]');
        const description = card.querySelector('[data-guide-description]');
        const link = card.querySelector('[data-guide-link]');
        const imageFile = card.querySelector('[data-guide-image-file]');
        const removeImage = card.querySelector('[data-remove-image-value]');
        const previewTitle = card.querySelector('[data-preview-title]');
        const previewDescription = card.querySelector('[data-preview-description]');
        const previewLink = card.querySelector('[data-preview-link]');
        const titleCounter = card.querySelector('[data-title-counter]');
        const descriptionCounter = card.querySelector('[data-description-counter]');

        if (!title || !description || !link) return;

        const titleValue = title.value.trim();
        const descriptionValue = description.value.trim();
        const linkValue = link.value.trim();

        if (previewTitle) {
            previewTitle.textContent = titleValue || labels.titlePlaceholder;
            previewTitle.href = validWebUrl(linkValue) ? linkValue : '#';
        }
        if (previewDescription) {
            previewDescription.innerHTML = renderDiscordMarkdown(descriptionValue || labels.descriptionPlaceholder);
        }
        if (previewLink) {
            previewLink.textContent = validWebUrl(linkValue) ? linkValue : labels.linkPlaceholder;
        }
        if (titleCounter) {
            titleCounter.textContent = labels.titleCounter.replace('__COUNT__', String(textLength(title.value)));
        }
        if (descriptionCounter) {
            descriptionCounter.textContent = labels.descriptionCounter.replace('__COUNT__', String(textLength(description.value)));
        }

        if (imageFile?.files?.[0]) {
            return;
        }

        if (removeImage?.value === '1') {
            setPreviewImage(card, '');
        } else {
            setPreviewImage(card, card.dataset.currentImage || '');
        }
    };

    const refreshAll = () => {
        const cards = Array.from(list.querySelectorAll('[data-guide-card]'));

        cards.forEach((card, index) => {
            const id = card.querySelector('[data-guide-id]');
            const title = card.querySelector('[data-guide-title]');
            const description = card.querySelector('[data-guide-description]');
            const link = card.querySelector('[data-guide-link]');
                const imageFile = card.querySelector('[data-guide-image-file]');
            const removeImage = card.querySelector('[data-remove-image-value]');
            const number = card.querySelector('[data-guide-number]');
            const moveUp = card.querySelector('[data-move-up]');
            const moveDown = card.querySelector('[data-move-down]');

            if (id) id.name = `guides[${index}][id]`;
            if (title) title.name = `guides[${index}][title]`;
            if (description) description.name = `guides[${index}][description]`;
            if (link) link.name = `guides[${index}][link]`;
            if (imageFile) imageFile.name = `guide_images[${index}]`;
            if (removeImage) removeImage.name = `guides[${index}][remove_image]`;
            if (number) number.textContent = labels.block.replace('__NUMBER__', String(index + 1));
            if (moveUp) moveUp.disabled = index === 0;
            if (moveDown) moveDown.disabled = index === cards.length - 1;

            card.removeAttribute('draggable');
            refreshCard(card);
        });

        emptyState.hidden = cards.length !== 0;
        countLabel.textContent = labels.count.replace('__COUNT__', String(cards.length));
    };

    const addGuide = () => {
        const fragment = template.content.cloneNode(true);
        const card = fragment.querySelector('[data-guide-card]');
        list.appendChild(fragment);
        refreshAll();
        card?.querySelector('[data-guide-title]')?.focus();
    };

    form.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-guide]')) {
            addGuide();
            return;
        }

        const card = event.target.closest('[data-guide-card]');
        if (!card) return;

        if (event.target.closest('[data-remove-guide]')) {
            card.remove();
            refreshAll();
            return;
        }

        if (event.target.closest('[data-remove-image]')) {
            const removeValue = card.querySelector('[data-remove-image-value]');
                const imageFile = card.querySelector('[data-guide-image-file]');
            if (removeValue) removeValue.value = '1';
            if (imageFile) imageFile.value = '';
            card.dataset.currentImage = '';
            setPreviewImage(card, '');
            return;
        }

        if (event.target.closest('[data-move-up]')) {
            const previous = card.previousElementSibling;
            if (previous) list.insertBefore(card, previous);
            refreshAll();
            return;
        }

        if (event.target.closest('[data-move-down]')) {
            const next = card.nextElementSibling;
            if (next) list.insertBefore(next, card);
            refreshAll();
        }
    });

    form.addEventListener('input', (event) => {
        const card = event.target.closest('[data-guide-card]');
        if (!card) return;

        refreshCard(card);
    });

    form.addEventListener('change', (event) => {
        const card = event.target.closest('[data-guide-card]');
        if (!card || !event.target.matches('[data-guide-image-file]')) return;

        const file = event.target.files?.[0];
        if (!file) {
            refreshCard(card);
            return;
        }

        const removeValue = card.querySelector('[data-remove-image-value]');
        if (removeValue) removeValue.value = '0';

        const reader = new FileReader();
        reader.addEventListener('load', () => setPreviewImage(card, reader.result));
        reader.readAsDataURL(file);
    });

    list.addEventListener('dragstart', (event) => {
        const handle = event.target.closest('[data-drag-handle]');
        const card = handle?.closest('[data-guide-card]');
        if (!handle || !card) {
            event.preventDefault();
            return;
        }

        draggedCard = card;
        card.classList.add('is-dragging');
        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', 'crystal-planner-card');
        }
    });

    list.addEventListener('dragover', (event) => {
        if (!draggedCard) return;
        event.preventDefault();

        const target = event.target.closest('[data-guide-card]');
        if (!target || target === draggedCard) return;

        const rectangle = target.getBoundingClientRect();
        const insertAfter = event.clientY > rectangle.top + rectangle.height / 2;
        list.insertBefore(draggedCard, insertAfter ? target.nextSibling : target);
    });

    list.addEventListener('dragend', () => {
        draggedCard?.classList.remove('is-dragging');
        draggedCard = null;
        refreshAll();
    });

    refreshAll();
})();
</script>
<?php render_footer(); ?>
