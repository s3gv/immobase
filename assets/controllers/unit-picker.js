// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Die Vervollständigung im Einheiten-Feld.
 *
 * Anders als beim Kontakt-Picker wird genau eine gewählt: ein Mietverhältnis
 * gehört zu einer Einheit. Die Wahl ersetzt deshalb, statt zu ergänzen.
 *
 * Vermietete Einheiten stehen mit in den Treffern und sind wählbar — nur
 * sichtbar als vermietet markiert. Genau so entsteht der Nachmieter: er wird
 * erfasst, während der Vormieter noch wohnt, und bleibt bis zum Abschließen
 * ein Entwurf. Ob die Einheit dann frei ist, entscheidet sich beim
 * Aktivieren, nicht beim Tippen.
 */

const DEBOUNCE_MS = 200;

/** Eine Zeile aus der Vorlage — ohne innerHTML, damit kein Name als Markup landet. */
function rowFor(picker, result) {
    const template = picker.querySelector('[data-unit-template]');
    const row = template.content.firstElementChild.cloneNode(true);
    const name = row.querySelector('.ib-picker__name');

    name.textContent = result.name;

    const hint = document.createElement('span');

    hint.className = 'ib-field__hint';
    hint.textContent = result.address === ''
        ? result.property
        : `${result.property} · ${result.address}`;
    name.appendChild(hint);

    return row;
}

function choose(picker, result) {
    picker.querySelector('[data-unit-value]').value = result.id;
    picker.querySelector('[data-unit-chosen]').replaceChildren(rowFor(picker, result));
}

function show(picker, results) {
    const box = picker.querySelector('[data-unit-results]');

    box.replaceChildren();

    if (results.length === 0) {
        const empty = document.createElement('li');

        empty.className = 'ib-picker__result is-empty';
        empty.textContent = picker.dataset.empty;
        box.appendChild(empty);
        box.hidden = false;

        return;
    }

    for (const result of results) {
        box.appendChild(option(picker, result, box));
    }

    box.hidden = false;
}

function option(picker, result, box) {
    const item = document.createElement('li');
    const button = document.createElement('button');

    button.type = 'button';
    button.className = `ib-picker__result${result.let ? ' is-let' : ''}`;
    button.textContent = result.let
        ? `${result.property} · ${result.name} — ${picker.dataset.let}`
        : `${result.property} · ${result.name}`;
    button.addEventListener('click', () => {
        choose(picker, result);
        box.hidden = true;

        const field = picker.querySelector('#unit-search');

        if (field !== null) {
            field.value = '';
        }
    });

    item.appendChild(button);

    return item;
}

async function search(picker, term) {
    if (term.trim() === '') {
        picker.querySelector('[data-unit-results]').hidden = true;

        return;
    }

    const url = new URL(picker.dataset.searchUrl, window.location.origin);

    url.searchParams.set('q', term);

    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            return;
        }

        show(picker, (await response.json()).results ?? []);
    } catch {
        // Die Suche ist eine Bequemlichkeit. Fällt sie aus, bleibt das
        // Formular benutzbar — eine Fehlermeldung wäre hier lauter als nötig.
    }
}

document.addEventListener('input', (event) => {
    const field = event.target;

    if (!(field instanceof HTMLInputElement) || field.id !== 'unit-search') {
        return;
    }

    const picker = field.closest('[data-unit-picker]');

    if (picker === null) {
        return;
    }

    window.clearTimeout(picker.dataset.timer);
    picker.dataset.timer = window.setTimeout(() => search(picker, field.value), DEBOUNCE_MS);
});

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const remove = event.target.closest('[data-unit-remove]');

    if (remove === null) {
        return;
    }

    event.preventDefault();

    const picker = remove.closest('[data-unit-picker]');

    picker.querySelector('[data-unit-value]').value = '';
    picker.querySelector('[data-unit-chosen]').replaceChildren();
});

// Die Ergebnisliste schließt sich, sobald das Feld den Fokus verliert. Der
// kurze Aufschub lässt den Klick auf einen Treffer noch durch.
document.addEventListener('focusout', (event) => {
    const picker = event.target instanceof Element ? event.target.closest('[data-unit-picker]') : null;

    if (picker === null) {
        return;
    }

    window.setTimeout(() => {
        if (picker.contains(document.activeElement)) {
            return;
        }

        picker.querySelector('[data-unit-results]').hidden = true;
    }, 150);
});
