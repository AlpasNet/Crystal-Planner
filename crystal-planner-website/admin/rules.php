<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rules_storage.php';
require_once __DIR__ . '/../includes/layout.php';

$admin = require_admin();
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $submittedRules = $_POST['rules'] ?? [];
        if (!is_array($submittedRules)) {
            throw new RuntimeException(t('admin.rules.error.invalid_data'));
        }

        if (count($submittedRules) > 50) {
            throw new RuntimeException(t('admin.rules.error.too_many'));
        }

        $rulesToSave = [];

        foreach (array_values($submittedRules) as $index => $submittedRule) {
            if (!is_array($submittedRule)) {
                throw new RuntimeException(t('admin.rules.error.invalid_data'));
            }

            $id = max(0, (int)($submittedRule['id'] ?? 0));
            $title = normalize_rule_text((string)($submittedRule['title'] ?? ''));
            $content = normalize_rule_text((string)($submittedRule['content'] ?? ''));
            $position = $index + 1;

            if ($title === '' || $content === '') {
                throw new RuntimeException(t('admin.rules.error.required', ['position' => $position]));
            }

            if (app_text_length($title) > 256) {
                throw new RuntimeException(t('admin.rules.error.title_too_long', ['position' => $position]));
            }

            if (app_text_length($content) > 4096) {
                throw new RuntimeException(t('admin.rules.error.content_too_long', ['position' => $position]));
            }

            $rulesToSave[] = [
                'id' => $id,
                'title' => $title,
                'content' => $content,
            ];
        }

        save_rules_data($rulesToSave);
        $success = t('admin.rules.success.saved', ['count' => count($rulesToSave)]);
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

$data = load_rules_data();
$rules = $data['rules'];
$updatedAt = trim((string)($data['updated_at'] ?? ''));

render_header(t('admin.rules.page_title'), $admin);
?>
<section class="page-heading rules-page-heading">
    <div>
        <div class="eyebrow"><?= e(t('common.administration')) ?></div>
        <h1><?= e(t('admin.rules.heading')) ?></h1>
        <p class="muted"><?= e(t('admin.rules.intro')) ?></p>
    </div>
    <div class="page-heading-actions page-heading-actions-stacked">
        <a class="button button-secondary" href="<?= e(app_url('admin/index.php')) ?>"><?= e(t('common.back_admin_home')) ?></a>
        <a class="button button-secondary" href="<?= e(app_url('discord-rules.json')) ?>" target="_blank" rel="noopener noreferrer"><?= e(t('admin.rules.open_json')) ?></a>
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
        <h2><?= e(t('admin.rules.discord_title')) ?></h2>
        <p class="muted"><?= e(t('admin.rules.discord_text')) ?></p>
    </div>
    <div class="rules-json-summary">
        <span><?= e(t('admin.rules.file_label')) ?></span>
        <code>discord-rules.json</code>
        <?php if ($updatedAt !== ''): ?>
            <small><?= e(t('admin.rules.updated_at', ['date' => $updatedAt])) ?></small>
        <?php endif; ?>
    </div>
</section>

<form class="rules-editor-form" method="post" data-rules-form>
    <?= csrf_field() ?>

    <div class="rules-toolbar">
        <div>
            <h2><?= e(t('admin.rules.blocks_title')) ?></h2>
            <p class="muted"><?= e(t('admin.rules.blocks_text')) ?></p>
        </div>
        <button class="button button-secondary" type="button" data-add-rule><?= e(t('admin.rules.add_block')) ?></button>
    </div>

    <div class="rules-list" data-rules-list>
        <?php foreach ($rules as $index => $rule): ?>
            <article class="rule-editor-card" data-rule-card>
                <div class="rule-editor-header">
                    <div class="rule-editor-heading">
                        <button class="rule-drag-handle" type="button" aria-label="<?= e(t('admin.rules.drag_aria')) ?>" title="<?= e(t('admin.rules.drag_aria')) ?>" data-drag-handle draggable="true">⋮⋮</button>
                        <div>
                            <span class="rule-number" data-rule-number><?= e(t('admin.rules.block_number', ['number' => $index + 1])) ?></span>
                            <small><?= e(t('admin.rules.embed_hint')) ?></small>
                        </div>
                    </div>
                    <div class="rule-editor-actions">
                        <button class="button button-small" type="button" data-move-up aria-label="<?= e(t('admin.rules.move_up')) ?>" title="<?= e(t('admin.rules.move_up')) ?>">↑</button>
                        <button class="button button-small" type="button" data-move-down aria-label="<?= e(t('admin.rules.move_down')) ?>" title="<?= e(t('admin.rules.move_down')) ?>">↓</button>
                        <button class="button button-danger" type="button" data-remove-rule><?= e(t('common.delete')) ?></button>
                    </div>
                </div>

                <input type="hidden" name="rules[<?= $index ?>][id]" value="<?= (int)$rule['id'] ?>" data-rule-id>

                <div class="rule-editor-grid">
                    <div class="rule-fields">
                        <label>
                            <span><?= e(t('admin.rules.title_label')) ?></span>
                            <input
                                type="text"
                                name="rules[<?= $index ?>][title]"
                                value="<?= e((string)$rule['title']) ?>"
                                maxlength="256"
                                required
                                data-rule-title
                            >
                            <small class="field-counter" data-title-counter></small>
                        </label>

                        <label>
                            <span><?= e(t('admin.rules.content_label')) ?></span>
                            <textarea
                                name="rules[<?= $index ?>][content]"
                                rows="9"
                                maxlength="4096"
                                required
                                data-rule-content
                            ><?= e((string)$rule['content']) ?></textarea>
                            <small class="field-counter" data-content-counter></small>
                        </label>
                        <p class="rule-markdown-note"><?= e(t('admin.rules.markdown_hint')) ?></p>
                    </div>

                    <aside class="rule-discord-preview" aria-live="polite">
                        <span class="rule-preview-label"><?= e(t('admin.rules.preview')) ?></span>
                        <div class="rule-preview-embed">
                            <strong data-preview-title></strong>
                            <div data-preview-content></div>
                        </div>
                    </aside>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="rules-empty-state" data-rules-empty <?= $rules !== [] ? 'hidden' : '' ?>>
        <h2><?= e(t('admin.rules.empty_title')) ?></h2>
        <p class="muted"><?= e(t('admin.rules.empty_text')) ?></p>
        <button class="button button-secondary" type="button" data-add-rule><?= e(t('admin.rules.add_first_block')) ?></button>
    </div>

    <div class="rules-save-bar">
        <span class="muted" data-rules-count></span>
        <button class="button button-primary" type="submit"><?= e(t('admin.rules.save')) ?></button>
    </div>
</form>

<template data-rule-template>
    <article class="rule-editor-card" data-rule-card>
        <div class="rule-editor-header">
            <div class="rule-editor-heading">
                <button class="rule-drag-handle" type="button" aria-label="<?= e(t('admin.rules.drag_aria')) ?>" title="<?= e(t('admin.rules.drag_aria')) ?>" data-drag-handle draggable="true">⋮⋮</button>
                <div>
                    <span class="rule-number" data-rule-number></span>
                    <small><?= e(t('admin.rules.embed_hint')) ?></small>
                </div>
            </div>
            <div class="rule-editor-actions">
                <button class="button button-small" type="button" data-move-up aria-label="<?= e(t('admin.rules.move_up')) ?>" title="<?= e(t('admin.rules.move_up')) ?>">↑</button>
                <button class="button button-small" type="button" data-move-down aria-label="<?= e(t('admin.rules.move_down')) ?>" title="<?= e(t('admin.rules.move_down')) ?>">↓</button>
                <button class="button button-danger" type="button" data-remove-rule><?= e(t('common.delete')) ?></button>
            </div>
        </div>

        <input type="hidden" value="0" data-rule-id>

        <div class="rule-editor-grid">
            <div class="rule-fields">
                <label>
                    <span><?= e(t('admin.rules.title_label')) ?></span>
                    <input type="text" maxlength="256" required data-rule-title>
                    <small class="field-counter" data-title-counter></small>
                </label>

                <label>
                    <span><?= e(t('admin.rules.content_label')) ?></span>
                    <textarea rows="9" maxlength="4096" required data-rule-content></textarea>
                    <small class="field-counter" data-content-counter></small>
                </label>
                <p class="rule-markdown-note"><?= e(t('admin.rules.markdown_hint')) ?></p>
            </div>

            <aside class="rule-discord-preview" aria-live="polite">
                <span class="rule-preview-label"><?= e(t('admin.rules.preview')) ?></span>
                <div class="rule-preview-embed">
                    <strong data-preview-title></strong>
                    <div data-preview-content></div>
                </div>
            </aside>
        </div>
    </article>
</template>

<script>
(() => {
    const form = document.querySelector('[data-rules-form]');
    const list = form?.querySelector('[data-rules-list]');
    const template = document.querySelector('[data-rule-template]');
    const emptyState = form?.querySelector('[data-rules-empty]');
    const countLabel = form?.querySelector('[data-rules-count]');

    if (!form || !list || !template || !emptyState || !countLabel) {
        return;
    }

    const labels = {
        block: <?= json_encode(t('admin.rules.block_number', ['number' => '__NUMBER__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        count: <?= json_encode(t('admin.rules.block_count', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        titlePlaceholder: <?= json_encode(t('admin.rules.preview_title_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        contentPlaceholder: <?= json_encode(t('admin.rules.preview_content_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        titleCounter: <?= json_encode(t('admin.rules.title_counter', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        contentCounter: <?= json_encode(t('admin.rules.content_counter', ['count' => '__COUNT__']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
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

    const refreshCard = (card) => {
        const title = card.querySelector('[data-rule-title]');
        const content = card.querySelector('[data-rule-content]');
        const previewTitle = card.querySelector('[data-preview-title]');
        const previewContent = card.querySelector('[data-preview-content]');
        const titleCounter = card.querySelector('[data-title-counter]');
        const contentCounter = card.querySelector('[data-content-counter]');

        if (!title || !content) return;

        const titleValue = title.value.trim();
        const contentValue = content.value.trim();

        if (previewTitle) {
            previewTitle.textContent = titleValue || labels.titlePlaceholder;
        }
        if (previewContent) {
            previewContent.innerHTML = renderDiscordMarkdown(contentValue || labels.contentPlaceholder);
        }
        if (titleCounter) {
            titleCounter.textContent = labels.titleCounter.replace('__COUNT__', String(textLength(title.value)));
        }
        if (contentCounter) {
            contentCounter.textContent = labels.contentCounter.replace('__COUNT__', String(textLength(content.value)));
        }
    };

    const refreshAll = () => {
        const cards = Array.from(list.querySelectorAll('[data-rule-card]'));

        cards.forEach((card, index) => {
            const id = card.querySelector('[data-rule-id]');
            const title = card.querySelector('[data-rule-title]');
            const content = card.querySelector('[data-rule-content]');
            const number = card.querySelector('[data-rule-number]');
            const moveUp = card.querySelector('[data-move-up]');
            const moveDown = card.querySelector('[data-move-down]');

            if (id) id.name = `rules[${index}][id]`;
            if (title) title.name = `rules[${index}][title]`;
            if (content) content.name = `rules[${index}][content]`;
            if (number) number.textContent = labels.block.replace('__NUMBER__', String(index + 1));
            if (moveUp) moveUp.disabled = index === 0;
            if (moveDown) moveDown.disabled = index === cards.length - 1;

            card.removeAttribute('draggable');
            refreshCard(card);
        });

        emptyState.hidden = cards.length !== 0;
        countLabel.textContent = labels.count.replace('__COUNT__', String(cards.length));
    };

    const addRule = () => {
        const fragment = template.content.cloneNode(true);
        const card = fragment.querySelector('[data-rule-card]');
        list.appendChild(fragment);
        refreshAll();
        card?.querySelector('[data-rule-title]')?.focus();
    };

    form.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-rule]');
        if (addButton) {
            addRule();
            return;
        }

        const card = event.target.closest('[data-rule-card]');
        if (!card) return;

        if (event.target.closest('[data-remove-rule]')) {
            card.remove();
            refreshAll();
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
        const card = event.target.closest('[data-rule-card]');
        if (card) refreshCard(card);
    });

    list.addEventListener('dragstart', (event) => {
        const handle = event.target.closest('[data-drag-handle]');
        const card = handle?.closest('[data-rule-card]');
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

        const target = event.target.closest('[data-rule-card]');
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
