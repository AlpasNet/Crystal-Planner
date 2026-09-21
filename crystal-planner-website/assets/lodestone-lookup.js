(() => {
    const widgets = document.querySelectorAll('[data-lodestone-lookup]');

    widgets.forEach((widget) => {
        const toggle = widget.querySelector('[data-lodestone-toggle]');
        const panel = widget.querySelector('[data-lodestone-panel]');
        const searchButton = widget.querySelector('[data-lodestone-search]');
        const nameInput = widget.querySelector('[data-lodestone-name]');
        const worldInput = widget.querySelector('[data-lodestone-world]');
        const status = widget.querySelector('[data-lodestone-status]');
        const results = widget.querySelector('[data-lodestone-results]');
        const targetInput = document.getElementById(widget.dataset.targetInput || '');

        if (!toggle || !panel || !searchButton || !nameInput || !worldInput || !status || !results || !targetInput) {
            return;
        }

        const setStatus = (message, type = '') => {
            status.textContent = message;
            status.classList.toggle('is-error', type === 'error');
            status.classList.toggle('is-success', type === 'success');
        };

        const clearResults = () => {
            results.replaceChildren();
        };

        const renderOfficialSearchFallback = (characterName, world) => {
            const fallback = document.createElement('div');
            fallback.className = 'lodestone-result-card';

            const text = createTextElement(
                'p',
                'muted',
                widget.dataset.fallbackHelp || ''
            );
            fallback.appendChild(text);

            const actions = document.createElement('div');
            actions.className = 'lodestone-result-actions';

            const officialUrl = new URL('https://eu.finalfantasyxiv.com/lodestone/character/');
            officialUrl.searchParams.set('q', characterName);
            officialUrl.searchParams.set('worldname', world);
            officialUrl.searchParams.set('classjob', '');
            officialUrl.searchParams.set('race_tribe', '');
            officialUrl.searchParams.set('order', '');

            const officialLink = document.createElement('a');
            officialLink.className = 'button button-secondary button-small';
            officialLink.href = officialUrl.toString();
            officialLink.target = '_blank';
            officialLink.rel = 'noopener noreferrer';
            officialLink.textContent = widget.dataset.officialSearchLabel || 'Open official Lodestone search';
            actions.appendChild(officialLink);
            fallback.appendChild(actions);
            results.appendChild(fallback);
        };

        const setPanelOpen = (open) => {
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');

            if (open) {
                window.setTimeout(() => nameInput.focus(), 0);
            }
        };

        const createTextElement = (tag, className, text) => {
            const element = document.createElement(tag);
            if (className) {
                element.className = className;
            }
            element.textContent = text;
            return element;
        };

        const renderResult = (character) => {
            const card = document.createElement('article');
            card.className = 'lodestone-result-card';

            const identity = document.createElement('div');
            identity.className = 'lodestone-result-identity';

            if (typeof character.avatar_url === 'string' && character.avatar_url !== '') {
                const avatar = document.createElement('img');
                avatar.className = 'lodestone-result-avatar';
                avatar.src = character.avatar_url;
                avatar.alt = '';
                avatar.loading = 'lazy';
                avatar.referrerPolicy = 'no-referrer';
                identity.appendChild(avatar);
            } else {
                const placeholder = createTextElement(
                    'span',
                    'lodestone-result-avatar lodestone-result-avatar-placeholder',
                    (character.character_name || '?').slice(0, 1).toUpperCase()
                );
                placeholder.setAttribute('aria-hidden', 'true');
                identity.appendChild(placeholder);
            }

            const details = document.createElement('div');
            details.className = 'lodestone-result-details';
            details.appendChild(createTextElement('strong', '', character.character_name || ''));

            const locationParts = [character.world || '', character.data_center || ''].filter(Boolean);
            details.appendChild(createTextElement('span', 'muted', locationParts.join(' · ')));
            details.appendChild(createTextElement(
                'span',
                'lodestone-result-id',
                `${widget.dataset.idLabel || 'ID Lodestone'} : ${character.lodestone_id || ''}`
            ));
            identity.appendChild(details);
            card.appendChild(identity);

            const actions = document.createElement('div');
            actions.className = 'lodestone-result-actions';

            const useButton = document.createElement('button');
            useButton.type = 'button';
            useButton.className = 'button button-primary button-small';
            useButton.textContent = widget.dataset.useLabel || 'Use this ID';
            useButton.addEventListener('click', () => {
                targetInput.value = character.lodestone_id || '';
                targetInput.dispatchEvent(new Event('input', { bubbles: true }));
                targetInput.dispatchEvent(new Event('change', { bubbles: true }));
                targetInput.focus();
                targetInput.classList.add('is-lodestone-filled');
                window.setTimeout(() => targetInput.classList.remove('is-lodestone-filled'), 1600);
                setStatus(widget.dataset.selectedText || '', 'success');
            });
            actions.appendChild(useButton);

            if (typeof character.lodestone_url === 'string' && character.lodestone_url !== '') {
                const profileLink = document.createElement('a');
                profileLink.className = 'button button-secondary button-small';
                profileLink.href = character.lodestone_url;
                profileLink.target = '_blank';
                profileLink.rel = 'noopener noreferrer';
                profileLink.textContent = widget.dataset.profileLabel || 'Open profile';
                actions.appendChild(profileLink);
            }

            card.appendChild(actions);
            return card;
        };

        const runSearch = async () => {
            const characterName = nameInput.value.trim();
            const world = worldInput.value.trim();

            clearResults();

            if (characterName === '' || world === '') {
                setStatus(widget.dataset.requiredError || widget.dataset.genericError || '', 'error');
                return;
            }

            searchButton.disabled = true;
            searchButton.setAttribute('aria-busy', 'true');
            setStatus(widget.dataset.loadingText || '');

            try {
                const body = new URLSearchParams({
                    csrf_token: widget.dataset.csrfToken || '',
                    character_name: characterName,
                    world,
                });

                const primaryEndpoint = widget.dataset.endpoint || 'lodestone-search.php';
                const fallbackEndpoint = new URL(
                    widget.dataset.endpointFallback || 'lodestone-search.php',
                    window.location.href
                ).toString();
                const endpoints = [...new Set([primaryEndpoint, fallbackEndpoint])];
                let response = null;
                let payload = null;
                let invalidResponse = false;

                for (let index = 0; index < endpoints.length; index += 1) {
                    response = await fetch(endpoints[index], {
                        method: 'POST',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: body.toString(),
                    });

                    const responseText = await response.text();
                    try {
                        payload = JSON.parse(responseText);
                        invalidResponse = false;
                    } catch (_error) {
                        payload = null;
                        invalidResponse = true;
                    }

                    // Si le chemin absolu calculé par le serveur ne correspond pas
                    // au dossier public, on retente automatiquement avec un chemin
                    // relatif à la page d'inscription ou de récupération.
                    if ((response.status === 404 || invalidResponse) && index < endpoints.length - 1) {
                        continue;
                    }

                    break;
                }

                if (!response || invalidResponse || !payload) {
                    const statusText = response ? ` (HTTP ${response.status})` : '';
                    throw new Error((widget.dataset.genericError || '') + statusText);
                }

                if (!response.ok || payload.success !== true) {
                    const reference = typeof payload.reference === 'string' && payload.reference !== ''
                        ? ` [${payload.reference}]`
                        : '';
                    const technicalCode = typeof payload.technical_code === 'string'
                        && payload.technical_code !== ''
                        ? ` (${payload.technical_code})`
                        : '';
                    throw new Error(
                        (payload.message || widget.dataset.genericError || '')
                        + technicalCode
                        + reference
                    );
                }

                const characters = Array.isArray(payload.results) ? payload.results : [];

                if (characters.length === 0) {
                    setStatus(payload.message || widget.dataset.emptyText || '');
                    return;
                }

                characters.forEach((character) => {
                    results.appendChild(renderResult(character));
                });

                setStatus(payload.message || '', 'success');
            } catch (error) {
                setStatus(
                    error instanceof Error && error.message !== ''
                        ? error.message
                        : (widget.dataset.genericError || ''),
                    'error'
                );
                renderOfficialSearchFallback(characterName, world);
            } finally {
                searchButton.disabled = false;
                searchButton.removeAttribute('aria-busy');
            }
        };

        toggle.addEventListener('click', () => {
            setPanelOpen(panel.hidden);
        });

        searchButton.addEventListener('click', runSearch);

        [nameInput, worldInput].forEach((input) => {
            input.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    runSearch();
                }
            });
        });
    });
})();
