// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Öffnet und schließt Dialoge.
 *
 * Getragen vom eingebauten dialog-Element: Escape, Fokusfalle und das
 * Abdunkeln des Hintergrunds kommen damit vom Browser. Nachgebaute Dialoge
 * verlieren erfahrungsgemäß genau diese Dinge.
 *
 * Ein Klick neben den Kasten schließt. Das ist bei einer Rückfrage die sichere
 * Richtung: geschlossen heißt abgebrochen, nie ausgeführt.
 */

function dialogNamed(id) {
    const found = document.getElementById(id);

    if (found instanceof HTMLDialogElement) {
        return found;
    }

    // Ein Auslöser, der ins Leere zeigt, bleibt sonst still wirkungslos.
    console.error(`Kein Dialog mit der Kennung "${id}".`);

    return null;
}

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) {
        return;
    }

    const opener = event.target.closest('[data-modal-open]');

    if (null !== opener) {
        event.preventDefault();
        dialogNamed(opener.getAttribute('data-modal-open'))?.showModal();

        return;
    }

    const closer = event.target.closest('[data-modal-close]');

    if (null !== closer) {
        event.preventDefault();
        closer.closest('dialog')?.close();

        return;
    }

    // Der Klick traf den abgedunkelten Bereich und nicht den Kasten darin.
    if (event.target instanceof HTMLDialogElement) {
        event.target.close();
    }
});
