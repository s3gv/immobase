// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Kleine Handgriffe, die frueher als onclick= und onfocus= im HTML standen.
 *
 * Die Content-Security-Policy verbietet Inline-Handler, damit eingeschleustes
 * HTML kein Skript ausfuehrt. Dieselben Handgriffe haengen deshalb hier an
 * Datenattributen:
 *
 * - `data-select-on-focus`: markiert den Inhalt eines Feldes beim Betreten,
 *   etwa einen Einladungslink zum Kopieren
 * - `data-print`: oeffnet den Druckdialog
 */

document.addEventListener('focusin', (event) => {
    const target = event.target;

    if (target instanceof HTMLInputElement && target.hasAttribute('data-select-on-focus')) {
        target.select();
    }
});

document.addEventListener('click', (event) => {
    const trigger = event.target instanceof Element ? event.target.closest('[data-print]') : null;

    if (trigger) {
        window.print();
    }
});
