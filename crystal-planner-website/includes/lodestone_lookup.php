<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/layout.php';


/**
 * Liste officielle des Mondes FFXIV, regroupés par région physique et centre de données logique.
 *
 * @return array<string, list<string>>
 */
function lodestone_world_groups(): array
{
    return [
        'Europe — Chaos' => [
            'Cerberus', 'Louisoix', 'Moogle', 'Omega',
            'Phantom', 'Ragnarok', 'Sagittarius', 'Spriggan',
        ],
        'Europe — Light' => [
            'Alpha', 'Lich', 'Odin', 'Phoenix',
            'Raiden', 'Shiva', 'Twintania', 'Zodiark',
        ],
        'North America — Aether' => [
            'Adamantoise', 'Cactuar', 'Faerie', 'Gilgamesh',
            'Jenova', 'Midgardsormr', 'Sargatanas', 'Siren',
        ],
        'North America — Crystal' => [
            'Balmung', 'Brynhildr', 'Coeurl', 'Diabolos',
            'Goblin', 'Malboro', 'Mateus', 'Zalera',
        ],
        'North America — Dynamis' => [
            'Cuchulainn', 'Golem', 'Halicarnassus', 'Kraken',
            'Maduin', 'Marilith', 'Rafflesia', 'Seraph',
        ],
        'North America — Primal' => [
            'Behemoth', 'Excalibur', 'Exodus', 'Famfrit',
            'Hyperion', 'Lamia', 'Leviathan', 'Ultros',
        ],
        'Japan — Elemental' => [
            'Aegis', 'Atomos', 'Carbuncle', 'Garuda',
            'Gungnir', 'Kujata', 'Tonberry', 'Typhon',
        ],
        'Japan — Gaia' => [
            'Alexander', 'Bahamut', 'Durandal', 'Fenrir',
            'Ifrit', 'Ridill', 'Tiamat', 'Ultima',
        ],
        'Japan — Mana' => [
            'Anima', 'Asura', 'Chocobo', 'Hades',
            'Ixion', 'Masamune', 'Pandaemonium', 'Titan',
        ],
        'Japan — Meteor' => [
            'Belias', 'Mandragora', 'Ramuh', 'Shinryu',
            'Unicorn', 'Valefor', 'Yojimbo', 'Zeromus',
        ],
        'Oceania — Materia' => [
            'Bismarck', 'Ravana', 'Sephirot', 'Sophia', 'Zurvan',
        ],
    ];
}

/**
 * Affiche l'outil réutilisable de recherche d'un personnage Lodestone.
 *
 * Le champ cible doit être présent sur la même page et posséder l'identifiant
 * HTML transmis dans $targetInputId. La saisie manuelle de l'ID reste toujours
 * disponible en cas d'indisponibilité temporaire du Lodestone.
 */
function render_lodestone_lookup(string $targetInputId): void
{
    $widgetId = 'lodestone-lookup-' . bin2hex(random_bytes(4));
    ?>
    <section
        id="<?= e($widgetId) ?>"
        class="lodestone-lookup"
        data-lodestone-lookup
        data-endpoint="<?= e(app_url('lodestone-search.php')) ?>"
        data-endpoint-fallback="lodestone-search.php"
        data-csrf-token="<?= e(csrf_token()) ?>"
        data-target-input="<?= e($targetInputId) ?>"
        data-loading-text="<?= e(t('lodestone_lookup.loading')) ?>"
        data-empty-text="<?= e(t('lodestone_lookup.empty')) ?>"
        data-generic-error="<?= e(t('lodestone_lookup.error.generic')) ?>"
        data-required-error="<?= e(t('lodestone_lookup.error.required')) ?>"
        data-use-label="<?= e(t('lodestone_lookup.use_id')) ?>"
        data-profile-label="<?= e(t('lodestone_lookup.open_profile')) ?>"
        data-id-label="<?= e(t('lodestone_lookup.id_label')) ?>"
        data-selected-text="<?= e(t('lodestone_lookup.selected')) ?>"
        data-official-search-label="<?= e(t('lodestone_lookup.open_official_search')) ?>"
        data-fallback-help="<?= e(t('lodestone_lookup.error.fallback_help')) ?>"
    >
        <div class="lodestone-lookup-heading">
            <div>
                <strong><?= e(t('lodestone_lookup.heading')) ?></strong>
                <p class="muted"><?= e(t('lodestone_lookup.intro')) ?></p>
            </div>
            <button
                type="button"
                class="button button-secondary button-small"
                data-lodestone-toggle
                aria-expanded="false"
                aria-controls="<?= e($widgetId) ?>-panel"
            >
                <?= e(t('lodestone_lookup.toggle')) ?>
            </button>
        </div>

        <div id="<?= e($widgetId) ?>-panel" class="lodestone-lookup-panel" data-lodestone-panel hidden>
            <div class="lodestone-lookup-fields">
                <label>
                    <span><?= e(t('lodestone_lookup.character_name')) ?></span>
                    <input
                        type="text"
                        maxlength="40"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="<?= e(t('lodestone_lookup.character_placeholder')) ?>"
                        data-lodestone-name
                    >
                </label>

                <label>
                    <span><?= e(t('lodestone_lookup.world')) ?></span>
                    <select data-lodestone-world required>
                        <option value="" selected disabled><?= e(t('lodestone_lookup.world_select')) ?></option>
                        <?php foreach (lodestone_world_groups() as $groupLabel => $worlds): ?>
                            <optgroup label="<?= e($groupLabel) ?>">
                                <?php foreach ($worlds as $world): ?>
                                    <option value="<?= e($world) ?>"><?= e($world) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <div class="lodestone-lookup-actions">
                <button type="button" class="button button-primary" data-lodestone-search>
                    <?= e(t('lodestone_lookup.search')) ?>
                </button>
                <span class="muted lodestone-lookup-help"><?= e(t('lodestone_lookup.manual_help')) ?></span>
            </div>

            <div class="lodestone-lookup-status" data-lodestone-status role="status" aria-live="polite"></div>
            <div class="lodestone-lookup-results" data-lodestone-results></div>
        </div>
    </section>
    <?php
}
