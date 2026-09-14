// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Die Vorschläge unter dem Suchfeld der Kopfzeile.
 *
 * Das Feld ist ein gewöhnliches Formular: ohne dieses Skript führt Enter auf
 * die Trefferseite, und die kann alles, was hier steht — sie braucht nur
 * einen Seitenaufbau dafür. Was hier dazukommt, ist das Tempo.
 *
 * Entprellt wie in `party-picker.js`, dieselbe Bauart und nicht eine zweite.
 * Gezeichnet wird ohne `innerHTML`: ein Objektname ist Eingabe, und Eingabe
 * wird nicht zu Markup.
 */

const DEBOUNCE_MS = 200;

/** Kürzer wird nicht gesucht — dieselbe Grenze wie in `SearchTerm::LEAST`. */
const LEAST = 2;

function row(hit) {
    const option = document.createElement('a');

    option.className = 'ib-suggest__hit';
    option.href = hit.url;
    option.setAttribute('role', 'option');

    const body = document.createElement('span');
    body.className = 'ib-suggest__body';

    const title = document.createElement('span');
    title.className = 'ib-suggest__title';
    title.textContent = hit.title;
    body.appendChild(title);

    if (hit.subtitle !== '') {
        const meta = document.createElement('span');
        meta.className = 'ib-suggest__meta';
        meta.textContent = hit.subtitle;
        body.appendChild(meta);
    }

    option.appendChild(body);

    const reference = document.createElement('span');
    reference.className = 'ib-suggest__reference';
    reference.textContent = hit.reference;
    option.appendChild(reference);

    return option;
}

function group(found) {
    const section = document.createElement('div');
    const heading = document.createElement('p');

    section.className = 'ib-suggest__group';
    heading.className = 'ib-suggest__kind';
    heading.textContent = found.kind;
    section.appendChild(heading);

    for (const hit of found.hits) {
        section.appendChild(row(hit));
    }

    return section;
}

/** Der letzte Eintrag führt auf die Trefferseite — dorthin, wo alles steht. */
function allResults(form, url) {
    const link = document.createElement('a');

    link.className = 'ib-suggest__all';
    link.href = url;
    link.setAttribute('role', 'option');
    link.textContent = form.querySelector('input[type="search"]').dataset.all;

    return link;
}

function draw(form, data) {
    const box = form.querySelector('[data-search-suggestions]');
    const field = form.querySelector('input[type="search"]');

    box.replaceChildren();

    if (data.groups.length === 0) {
        const empty = document.createElement('p');

        empty.className = 'ib-suggest__empty';
        empty.textContent = field.dataset.nothing;
        box.appendChild(empty);
    } else {
        for (const found of data.groups) {
            box.appendChild(group(found));
        }

        box.appendChild(allResults(form, data.allUrl));
    }

    box.hidden = false;
    field.setAttribute('aria-expanded', 'true');
}

function close(form) {
    const box = form.querySelector('[data-search-suggestions]');

    box.hidden = true;
    form.querySelector('input[type="search"]').setAttribute('aria-expanded', 'false');
}

async function suggest(form, term) {
    if (term.trim().length < LEAST) {
        close(form);

        return;
    }

    const url = new URL(form.dataset.suggest, window.location.origin);
    url.searchParams.set('q', term);

    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });

        if (!response.ok) {
            return;
        }

        draw(form, await response.json());
    } catch {
        // Die Vorschläge sind eine Bequemlichkeit. Fallen sie aus, führt Enter
        // immer noch auf die Trefferseite; eine Fehlermeldung wäre hier lauter
        // als der Schaden.
    }
}

/**
 * Mit den Pfeiltasten durch die Vorschläge.
 *
 * Bewegt wird die Eingabemarke und nicht eine gemerkte Zeilennummer: dann ist
 * Enter der gewöhnliche Klick auf einen Link, und die Tastatur braucht keine
 * zweite Vorstellung davon, was gerade ausgewählt ist.
 */
function step(form, by) {
    const options = [...form.querySelectorAll('[role="option"]')];

    if (options.length === 0) {
        return;
    }

    const at = options.indexOf(document.activeElement);
    const next = at < 0 ? (by > 0 ? 0 : options.length - 1) : at + by;

    if (next < 0) {
        form.querySelector('input[type="search"]').focus();

        return;
    }

    options[Math.min(next, options.length - 1)].focus();
}

function start(form) {
    const field = form.querySelector('input[type="search"]');

    field.addEventListener('input', () => {
        window.clearTimeout(form.dataset.timer);
        form.dataset.timer = window.setTimeout(() => suggest(form, field.value), DEBOUNCE_MS);
    });

    form.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            close(form);
            field.focus();

            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            step(form, event.key === 'ArrowDown' ? 1 : -1);
        }
    });

    // Erst schließen, wenn die Eingabemarke das Formular wirklich verlassen
    // hat: ein Klick auf einen Vorschlag nimmt sie kurz mit.
    form.addEventListener('focusout', () => {
        window.setTimeout(() => {
            if (!form.contains(document.activeElement)) {
                close(form);
            }
        }, 0);
    });
}

/**
 * Ein Tastendruck führt ins Suchfeld.
 *
 * `/` ist der gewohnte Griff und kostet nichts — außer wenn jemand gerade
 * schreibt. Dann ist es ein Schrägstrich und sonst nichts.
 */
document.addEventListener('keydown', (event) => {
    const form = document.querySelector('[data-search]');

    if (form === null) {
        return;
    }

    const writing = document.activeElement instanceof HTMLInputElement
        || document.activeElement instanceof HTMLTextAreaElement
        || document.activeElement?.isContentEditable === true;

    if ((event.key === '/' && !writing) || (event.key.toLowerCase() === 'k' && (event.metaKey || event.ctrlKey))) {
        event.preventDefault();
        form.querySelector('input[type="search"]').focus();
    }
});

document.querySelectorAll('[data-search]').forEach(start);
