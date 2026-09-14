// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Eine kleine Auswahl gebräuchlicher Zeichen, die ins Textfeld schreibt.
 *
 * Kein Bilddienst, keine eigene Zeichensammlung, kein Auswahlfenster mit
 * Suchfeld und Hauttönen: der Text ist Unicode, die Datenbank kann es, und
 * wer mehr braucht, hat die Tastatur seines Geräts.
 *
 * Eingefügt wird an der Schreibmarke und nicht am Ende — wer mitten im Satz
 * klickt, meint diese Stelle.
 */

function insert(field, sign) {
    const start = field.selectionStart ?? field.value.length;
    const end = field.selectionEnd ?? field.value.length;

    field.value = field.value.slice(0, start) + sign + field.value.slice(end);
    field.selectionStart = start + sign.length;
    field.selectionEnd = field.selectionStart;
    field.focus();
}

document.querySelectorAll('[data-emoji-for]').forEach((row) => {
    const field = document.getElementById(row.dataset.emojiFor);

    if (!(field instanceof HTMLTextAreaElement)) {
        return;
    }

    row.addEventListener('click', (event) => {
        const key = event.target.closest('[data-emoji]');

        if (key instanceof HTMLElement) {
            insert(field, key.dataset.emoji);
        }
    });
});
