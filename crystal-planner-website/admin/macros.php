<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/macros_storage.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$success = '';
$error = '';
$currentData = load_macros_data();
$currentMacrosById = [];

foreach ($currentData['macros'] as $currentMacro) {
    $currentMacrosById[(int)$currentMacro['id']] = $currentMacro;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $newUploadedPaths = [];

    try {
        $submittedMacros = $_POST['macros'] ?? [];
        if (!is_array($submittedMacros)) {
            throw new RuntimeException(t('admin.macros.error.invalid_data'));
        }

        if (count($submittedMacros) > 50) {
            throw new RuntimeException(t('admin.macros.error.too_many'));
        }

        $macrosToSave = [];

        foreach (array_values($submittedMacros) as $index => $submittedMacro) {
            if (!is_array($submittedMacro)) {
                throw new RuntimeException(t('admin.macros.error.invalid_data'));
            }

            $position = $index + 1;
            $id = max(0, (int)($submittedMacro['id'] ?? 0));
            $title = normalize_macro_text((string)($submittedMacro['title'] ?? ''));
            $description = normalize_macro_text((string)($submittedMacro['description'] ?? ''));
            $removeImage = (string)($submittedMacro['remove_image'] ?? '0') === '1';
            $currentMacro = $id > 0 ? ($currentMacrosById[$id] ?? null) : null;

            if ($title === '' || $description === '') {
                throw new RuntimeException(t('admin.macros.error.required', ['position' => $position]));
            }

            if (app_text_length($title) > 256) {
                throw new RuntimeException(t('admin.macros.error.title_too_long', ['position' => $position]));
            }

            if (app_text_length($description) > 4096) {
                throw new RuntimeException(t('admin.macros.error.description_too_long', ['position' => $position]));
            }

            $imagePath = '';
            $uploadedFile = macro_upload_for_index($index);

            if ($uploadedFile !== null) {
                $imagePath = save_macro_uploaded_image($uploadedFile);
                $newUploadedPaths[] = $imagePath;
            } elseif (!$removeImage && is_array($currentMacro)) {
                $imagePath = normalize_macro_image_path((string)($currentMacro['image_path'] ?? ''));
            }

            if ($imagePath === '') {
                throw new RuntimeException(t('admin.macros.error.image_required', ['position' => $position]));
            }

            $macrosToSave[] = [
                'id' => $id,
                'title' => $title,
                'description' => $description,
                'image_path' => $imagePath,
            ];
        }

        $savedData = save_macros_data($macrosToSave);

        $oldImagePaths = [];
        foreach ($currentData['macros'] as $macro) {
            $path = normalize_macro_image_path((string)($macro['image_path'] ?? ''));
            if ($path !== '') $oldImagePaths[$path] = true;
        }

        $savedImagePaths = [];
        foreach ($savedData['macros'] as $macro) {
            $path = normalize_macro_image_path((string)($macro['image_path'] ?? ''));
            if ($path !== '') $savedImagePaths[$path] = true;
        }

        foreach (array_keys($oldImagePaths) as $oldImagePath) {
            if (!isset($savedImagePaths[$oldImagePath])) {
                delete_macro_uploaded_image($oldImagePath);
            }
        }

        delete_unused_macro_images($savedData['macros']);

        $currentData = $savedData;
        $currentMacrosById = [];
        foreach ($currentData['macros'] as $currentMacro) {
            $currentMacrosById[(int)$currentMacro['id']] = $currentMacro;
        }

        $success = t('admin.macros.success.saved', ['count' => count($macrosToSave)]);
    } catch (RuntimeException $exception) {
        foreach ($newUploadedPaths as $newUploadedPath) {
            delete_macro_uploaded_image($newUploadedPath);
        }
        $error = $exception->getMessage();
    }
}

$macros = $currentData['macros'];
$updatedAt = trim((string)($currentData['updated_at'] ?? ''));

render_header(t('admin.macros.page_title'), $admin);
?>
<section class="page-heading rules-page-heading">
    <div>
        <div class="eyebrow"><?= e(t('common.administration')) ?></div>
        <h1><?= e(t('admin.macros.heading')) ?></h1>
        <p class="muted"><?= e(t('admin.macros.intro')) ?></p>
    </div>
    <div class="page-heading-actions page-heading-actions-stacked">
        <a class="button button-secondary" href="<?= e(app_url('admin/index.php')) ?>"><?= e(t('common.back_admin_home')) ?></a>
        <a class="button button-secondary" href="<?= e(app_url('discord-macros.json')) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('admin.macros.open_json')) ?></a>
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
        <h2><?= e(t('admin.macros.discord_title')) ?></h2>
        <p class="muted"><?= e(t('admin.macros.discord_text')) ?></p>
    </div>
    <div class="rules-json-summary">
        <span><?= e(t('admin.macros.file_label')) ?></span>
        <code>discord-macros.json</code>
        <?php if ($updatedAt !== ''): ?>
            <small><?= e(t('admin.macros.updated_at', ['date' => $updatedAt])) ?></small>
        <?php endif; ?>
    </div>
</section>

<form class="rules-editor-form" method="post" enctype="multipart/form-data" data-macros-form>
    <?= csrf_field() ?>

    <div class="rules-toolbar">
        <div>
            <h2><?= e(t('admin.macros.blocks_title')) ?></h2>
            <p class="muted"><?= e(t('admin.macros.blocks_text')) ?></p>
        </div>
        <button class="button button-secondary" type="button" data-add-macro><?= e(t('admin.macros.add_block')) ?></button>
    </div>

    <div class="rules-list" data-macros-list>
        <?php foreach ($macros as $index => $macro): ?>
            <?php
                $imagePath = normalize_macro_image_path((string)($macro['image_path'] ?? ''));
                $imageUrl = trim((string)($macro['image'] ?? ''));
            ?>
            <article class="rule-editor-card macro-editor-card" data-macro-card data-current-image="<?= e($imageUrl) ?>">
                <div class="rule-editor-header">
                    <div class="rule-editor-heading">
                        <button class="rule-drag-handle" type="button" aria-label="<?= e(t('admin.macros.drag_aria')) ?>" title="<?= e(t('admin.macros.drag_aria')) ?>" data-drag-handle draggable="true">⋮⋮</button>
                        <div>
                            <span class="rule-number" data-macro-number><?= e(t('admin.macros.block_number', ['number' => $index + 1])) ?></span>
                            <small><?= e(t('admin.macros.embed_hint')) ?></small>
                        </div>
                    </div>
                    <div class="rule-editor-actions">
                        <button class="button button-small" type="button" data-move-up aria-label="<?= e(t('admin.macros.move_up')) ?>" title="<?= e(t('admin.macros.move_up')) ?>">↑</button>
                        <button class="button button-small" type="button" data-move-down aria-label="<?= e(t('admin.macros.move_down')) ?>" title="<?= e(t('admin.macros.move_down')) ?>">↓</button>
                        <button class="button button-danger" type="button" data-remove-macro><?= e(t('common.delete')) ?></button>
                    </div>
                </div>

                <input type="hidden" name="macros[<?= $index ?>][id]" value="<?= (int)$macro['id'] ?>" data-macro-id>
                <input type="hidden" name="macros[<?= $index ?>][remove_image]" value="0" data-remove-image-value>

                <div class="rule-editor-grid macro-editor-grid">
                    <div class="rule-fields macro-fields">
                        <label>
                            <span><?= e(t('admin.macros.title_label')) ?></span>
                            <input type="text" name="macros[<?= $index ?>][title]" value="<?= e((string)$macro['title']) ?>" maxlength="256" required data-macro-title>
                            <small class="field-counter" data-title-counter></small>
                        </label>

                        <label>
                            <span><?= e(t('admin.macros.description_label')) ?></span>
                            <textarea name="macros[<?= $index ?>][description]" rows="7" maxlength="4096" required data-macro-description><?= e((string)$macro['description']) ?></textarea>
                            <small class="field-counter" data-description-counter></small>
                        </label>


                        <div class="macro-image-fields">
                            <label>
                                <span><?= e(t('admin.macros.image_upload_label')) ?></span>
                                <input type="file" name="macro_images[<?= $index ?>]" accept="image/png,image/jpeg,image/gif,image/webp" data-macro-image-file>
                                <small><?= e(t('admin.macros.image_upload_hint')) ?></small>
                            </label>
                        </div>

                        <button class="button button-secondary macro-remove-image" type="button" data-remove-image><?= e(t('admin.macros.remove_image')) ?></button>
                        <p class="rule-markdown-note"><?= e(t('admin.macros.markdown_hint')) ?></p>
                    </div>

                    <aside class="rule-discord-preview" aria-live="polite">
                        <span class="rule-preview-label"><?= e(t('admin.macros.preview')) ?></span>
                        <div class="rule-preview-embed macro-preview-embed">
                            <strong data-preview-title></strong>
                            <div data-preview-description></div>
                            <img src="<?= e($imageUrl) ?>" alt="" data-preview-image <?= $imageUrl === '' ? 'hidden' : '' ?>>
                        </div>
                    </aside>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="rules-empty-state" data-macros-empty <?= $macros !== [] ? 'hidden' : '' ?>>
        <h2><?= e(t('admin.macros.empty_title')) ?></h2>
        <p class="muted"><?= e(t('admin.macros.empty_text')) ?></p>
        <button class="button button-secondary" type="button" data-add-macro><?= e(t('admin.macros.add_first_block')) ?></button>
    </div>

    <div class="rules-save-bar">
        <span class="muted" data-macros-count></span>
        <button class="button button-primary" type="submit"><?= e(t('admin.macros.save')) ?></button>
    </div>
</form>

<template data-macro-template>
    <article class="rule-editor-card macro-editor-card" data-macro-card data-current-image="">
        <div class="rule-editor-header">
            <div class="rule-editor-heading">
                <button class="rule-drag-handle" type="button" aria-label="<?= e(t('admin.macros.drag_aria')) ?>" title="<?= e(t('admin.macros.drag_aria')) ?>" data-drag-handle draggable="true">⋮⋮</button>
                <div>
                    <span class="rule-number" data-macro-number></span>
                    <small><?= e(t('admin.macros.embed_hint')) ?></small>
                </div>
            </div>
            <div class="rule-editor-actions">
                <button class="button button-small" type="button" data-move-up aria-label="<?= e(t('admin.macros.move_up')) ?>" title="<?= e(t('admin.macros.move_up')) ?>">↑</button>
                <button class="button button-small" type="button" data-move-down aria-label="<?= e(t('admin.macros.move_down')) ?>" title="<?= e(t('admin.macros.move_down')) ?>">↓</button>
                <button class="button button-danger" type="button" data-remove-macro><?= e(t('common.delete')) ?></button>
            </div>
        </div>

        <input type="hidden" value="0" data-macro-id>
        <input type="hidden" value="0" data-remove-image-value>

        <div class="rule-editor-grid macro-editor-grid">
            <div class="rule-fields macro-fields">
                <label>
                    <span><?= e(t('admin.macros.title_label')) ?></span>
                    <input type="text" maxlength="256" required data-macro-title>
                    <small class="field-counter" data-title-counter></small>
                </label>

                <label>
                    <span><?= e(t('admin.macros.description_label')) ?></span>
                    <textarea rows="7" maxlength="4096" required data-macro-description></textarea>
                    <small class="field-counter" data-description-counter></small>
                </label>


                <div class="macro-image-fields">
                    <label>
                        <span><?= e(t('admin.macros.image_upload_label')) ?></span>
                        <input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-macro-image-file>
                        <small><?= e(t('admin.macros.image_upload_hint')) ?></small>
                    </label>
                </div>

                <button class="button button-secondary macro-remove-image" type="button" data-remove-image><?= e(t('admin.macros.remove_image')) ?></button>
                <p class="rule-markdown-note"><?= e(t('admin.macros.markdown_hint')) ?></p>
            </div>

            <aside class="rule-discord-preview" aria-live="polite">
                <span class="rule-preview-label"><?= e(t('admin.macros.preview')) ?></span>
                <div class="rule-preview-embed macro-preview-embed">
                    <strong data-preview-title></strong>
                    <div data-preview-description></div>
                    <img src="" alt="" data-preview-image hidden>
                </div>
            </aside>
        </div>
    </article>
</template>

<script>
(() => {
    const form = document.querySelector('[data-macros-form]');
    const list = form?.querySelector('[data-macros-list]');
    const template = document.querySelector('[data-macro-template]');
    const emptyState = form?.querySelector('[data-macros-empty]');
    const countLabel = form?.querySelector('[data-macros-count]');

    if (!form || !list || !template || !emptyState || !countLabel) return;

    const labels = {
        block: <?= json_encode(t('admin.macros.block_number', ['number' => '__NUMBER__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        count: <?= json_encode(t('admin.macros.block_count', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        titlePlaceholder: <?= json_encode(t('admin.macros.preview_title_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        descriptionPlaceholder: <?= json_encode(t('admin.macros.preview_description_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        titleCounter: <?= json_encode(t('admin.macros.title_counter', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        descriptionCounter: <?= json_encode(t('admin.macros.description_counter', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
    };

    let draggedCard = null;

    const textLength = (value) => Array.from(value || '').length;

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
        const title = card.querySelector('[data-macro-title]');
        const description = card.querySelector('[data-macro-description]');
        const imageFile = card.querySelector('[data-macro-image-file]');
        const removeImage = card.querySelector('[data-remove-image-value]');
        const previewTitle = card.querySelector('[data-preview-title]');
        const previewDescription = card.querySelector('[data-preview-description]');
        const titleCounter = card.querySelector('[data-title-counter]');
        const descriptionCounter = card.querySelector('[data-description-counter]');

        if (!title || !description) return;

        const titleValue = title.value.trim();
        const descriptionValue = description.value.trim();

        if (previewTitle) {
            previewTitle.textContent = titleValue || labels.titlePlaceholder;
        }
        if (previewDescription) {
            previewDescription.innerHTML = renderDiscordMarkdown(descriptionValue || labels.descriptionPlaceholder);
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
        const cards = Array.from(list.querySelectorAll('[data-macro-card]'));

        cards.forEach((card, index) => {
            const id = card.querySelector('[data-macro-id]');
            const title = card.querySelector('[data-macro-title]');
            const description = card.querySelector('[data-macro-description]');
            const imageFile = card.querySelector('[data-macro-image-file]');
            const removeImage = card.querySelector('[data-remove-image-value]');
            const number = card.querySelector('[data-macro-number]');
            const moveUp = card.querySelector('[data-move-up]');
            const moveDown = card.querySelector('[data-move-down]');

            if (id) id.name = `macros[${index}][id]`;
            if (title) title.name = `macros[${index}][title]`;
            if (description) description.name = `macros[${index}][description]`;
            if (imageFile) imageFile.name = `macro_images[${index}]`;
            if (removeImage) removeImage.name = `macros[${index}][remove_image]`;
            if (number) number.textContent = labels.block.replace('__NUMBER__', String(index + 1));
            if (moveUp) moveUp.disabled = index === 0;
            if (moveDown) moveDown.disabled = index === cards.length - 1;

            card.removeAttribute('draggable');
            refreshCard(card);
        });

        emptyState.hidden = cards.length !== 0;
        countLabel.textContent = labels.count.replace('__COUNT__', String(cards.length));
    };

    const addMacro = () => {
        const fragment = template.content.cloneNode(true);
        const card = fragment.querySelector('[data-macro-card]');
        list.appendChild(fragment);
        refreshAll();
        card?.querySelector('[data-macro-title]')?.focus();
    };

    form.addEventListener('click', (event) => {
        if (event.target.closest('[data-add-macro]')) {
            addMacro();
            return;
        }

        const card = event.target.closest('[data-macro-card]');
        if (!card) return;

        if (event.target.closest('[data-remove-macro]')) {
            card.remove();
            refreshAll();
            return;
        }

        if (event.target.closest('[data-remove-image]')) {
            const removeValue = card.querySelector('[data-remove-image-value]');
            const imageFile = card.querySelector('[data-macro-image-file]');
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
        const card = event.target.closest('[data-macro-card]');
        if (!card) return;

        refreshCard(card);
    });

    form.addEventListener('change', (event) => {
        const card = event.target.closest('[data-macro-card]');
        if (!card || !event.target.matches('[data-macro-image-file]')) return;

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
        const card = handle?.closest('[data-macro-card]');
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

        const target = event.target.closest('[data-macro-card]');
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
