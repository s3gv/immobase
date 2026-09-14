// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Die Vervollständigung in einem Feld, das Kontakte sammelt.
 *
 * Zwei Stellen benutzen sie: die Eigentümer einer Einheit, die je einen
 * Anteil tragen, und die Mieter eines Mietverhältnisses, die keinen tragen.
 * Was sich unterscheidet, steht in zwei Attributen am Picker — Name und Wert
 * des Feldes je Zeile. Alles andere ist dasselbe, und zweimal dieselbe Suche
 * zu schreiben hieße, sie ab dem nächsten Fehler zweimal zu reparieren.
 *
 * Tippen sucht, ein Klick fügt eine Zeile hinzu. Die Zeilen sind gewöhnliche
 * Formularfelder — gespeichert wird beim Absenden des Schritts, nicht bei
 * jedem Klick. So bleibt eine halb zusammengestellte Liste widerrufbar.
 *
 * Ohne JavaScript bleibt das Feld ein Feld: die bereits zugeordneten Kontakte
 * stehen serverseitig da und lassen sich entfernen. Nur das Hinzufügen
 * braucht diese Datei.
 */

import { notice } from './notice.js';

const DEBOUNCE_MS = 200;

/** Das Suchfeld heißt je Verwendung anders und endet immer auf „-search". */
const SEARCH = '[id$="-search"]';

function textOf(element, selector) {
    return element.querySelector(selector);
}

/**
 * Der Schlüssel einer neuen Zeile.
 *
 * Nicht die Kennung des Kontakts: derselbe Kontakt darf zweimal an derselben
 * Einheit stehen, wenn er verkauft und später zurückkauft — nur nicht
 * gleichzeitig. Die Zeile ist also der Eigentumszeitraum und nicht die
 * Partei, und sie braucht einen eigenen Schlüssel. Der Server erkennt an ihm,
 * dass die Zeile neu ist.
 *
 * **Gezählt wird gegen die Seite und nicht gegen einen Zähler im Skript.**
 * Nach einem Fehler zeichnet der Server die noch nicht gespeicherten Zeilen
 * erneut — mitsamt ihrem `neu-1`. Ein Zähler finge beim Neuladen wieder bei
 * eins an, die nächste hinzugefügte Zeile hieße genauso, und beim Absenden
 * überschriebe eine die andere. Die Seite weiß, welche Schlüssel es schon
 * gibt; das Skript muss es sich nicht merken.
 */
function freshKey(list) {
    let at = 1;

    while (list.querySelector(`[data-party-row="neu-${at}"]`) !== null) {
        at += 1;
    }

    return `neu-${at}`;
}

/** Eine Zeile aus der Vorlage — ohne innerHTML, damit kein Name als Markup landet. */
function rowFor(picker, result) {
    const template = picker.querySelector('[data-party-template]');
    const row = template.content.firstElementChild.cloneNode(true);
    const key = freshKey(picker.querySelector('[data-party-list]'));

    row.setAttribute('data-party-row', key);

    const name = textOf(row, '.ib-picker__name');
    name.textContent = result.name;

    // Dieselbe Zeile wie die serverseitig gezeichneten: „Nummer 10004 · …".
    const hint = document.createElement('span');
    hint.className = 'ib-field__hint';
    hint.textContent = result.address === ''
        ? `${picker.dataset.reference} ${result.reference}`
        : `${picker.dataset.reference} ${result.reference} · ${result.address}`;
    name.appendChild(hint);

    // Was die Zeile abschickt, unterscheidet die beiden Verwendungen: beim
    // Mieter die blanke Kennung, beim Eigentümer mehrere Felder — Anteil,
    // Von und Bis. Deshalb wird über alle Felder der Zeile gelaufen und
    // nicht über das erste: `{field}` im Namensmuster sagt, welches gemeint
    // ist, und ohne Muster bleibt alles, wie es war.
    const pattern = picker.dataset.rowName ?? 'value[{id}]';

    row.querySelectorAll('input').forEach((input) => {
        const field = input.dataset.rowField ?? '';

        input.name = pattern.replace('{id}', key).replace('{field}', field);

        // Wessen Zeile das ist, steht in einem eigenen Feld — der Schlüssel
        // sagt es nicht mehr. Ohne `{field}` im Muster bleibt alles wie
        // gehabt: die Mieterauswahl schickt die blanke Kennung.
        if (field === 'party' || field === '') {
            input.value = field === 'party'
                ? result.id
                : (picker.dataset.rowValue ?? '').replace('{id}', result.id);
        }
    });

    return row;
}

/** @return {boolean} ob die Zeile dazugekommen ist */
function add(picker, result) {
    const list = picker.querySelector('[data-party-list]');

    // Zweimal derselbe Kontakt ist beim Mieter ein Fehler und beim
    // Eigentümer der Rückkauf: verkauft und Jahre später wieder erworben,
    // zwei Zeiträume, eine Partei. Wo Zeiträume erfasst werden, entscheidet
    // die Datenbank über die Überschneidung — nicht dieses Skript.
    if (picker.dataset.allowRepeat === undefined
        && list.querySelector(`[data-party-row="${CSS.escape(result.id)}"]`) !== null) {
        notice(picker.dataset.duplicate);

        return false;
    }

    list.appendChild(rowFor(picker, result));
    picker.querySelector('[data-party-empty]')?.remove();

    return true;
}

function show(picker, results) {
    const box = picker.querySelector('[data-party-results]');
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
        const option = document.createElement('li');
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'ib-picker__result';
        button.textContent = result.address === ''
            ? `${result.name} · ${result.reference}`
            : `${result.name} · ${result.reference} · ${result.address}`;
        button.addEventListener('click', () => {
            if (!add(picker, result)) {
                return;
            }

            box.hidden = true;

            const field = picker.querySelector(SEARCH);

            if (field !== null) {
                // Das Feld leeren: der nächste Eigentümer ist eine neue Suche
                // und nicht eine Verfeinerung der letzten.
                field.value = '';
                field.focus();
            }
        });

        option.appendChild(button);
        box.appendChild(option);
    }

    box.hidden = false;
}

async function search(picker, term) {
    if (term.trim() === '') {
        picker.querySelector('[data-party-results]').hidden = true;

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

    if (!(field instanceof HTMLInputElement) || !field.id.endsWith('-search')) {
        return;
    }

    const picker = field.closest('[data-party-picker]');

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

    const remove = event.target.closest('[data-party-remove]');

    if (remove !== null) {
        event.preventDefault();
        remove.closest('[data-party-row]')?.remove();
    }
});

// Die Ergebnisliste schließt sich, sobald das Feld den Fokus verliert. Der
// kurze Aufschub lässt den Klick auf einen Treffer noch durch.
document.addEventListener('focusout', (event) => {
    const picker = event.target instanceof Element ? event.target.closest('[data-party-picker]') : null;

    if (picker === null) {
        return;
    }

    window.setTimeout(() => {
        if (picker.contains(document.activeElement)) {
            return;
        }

        picker.querySelector('[data-party-results]').hidden = true;
    }, 150);
});
