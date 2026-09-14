// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Ein Hinweis aus einem Skript heraus — im Haus-Dialog, nie als alert().
 *
 * window.alert() sieht in jedem Browser anders aus, nennt die Adresse der
 * Seite und hält den Reiter an, bis jemand klickt. Nichts davon gehört in
 * diese Anwendung. Der Dialog steht in shell.html.twig und wartet leer.
 */

const DEFAULT_TITLE = document.getElementById('notice')
    ?.querySelector('[data-notice-title]')?.textContent ?? '';

/**
 * @param {string} message
 * @param {string|null} title Überschrift; ohne Angabe die voreingestellte
 */
export function notice(message, title = null) {
    const dialog = document.getElementById('notice');

    if (!(dialog instanceof HTMLDialogElement)) {
        // Ohne Dialog bliebe der Hinweis unsichtbar — das soll auffallen.
        console.error('Der Hinweis-Dialog fehlt auf dieser Seite.');

        return;
    }

    const heading = dialog.querySelector('[data-notice-title]');

    if (heading !== null) {
        heading.textContent = title ?? DEFAULT_TITLE;
    }

    const body = dialog.querySelector('[data-notice-body]');

    if (body !== null) {
        body.textContent = message;
    }

    dialog.showModal();
}
