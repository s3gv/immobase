// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Der Knopf, der erst nach der Vertrauensmarke aufgeht.
 *
 * Ein Plugin zu aktivieren heißt, fremder Software die eigenen Daten zu
 * öffnen. Die Marke ist der Moment, in dem jemand das bewusst sagt — und ein
 * Knopf, der vorher schon bereitsteht, lädt dazu ein, ihn zu drücken, bevor
 * man gelesen hat.
 *
 * Ohne JavaScript bleibt der Knopf offen und die Ablehnung kommt vom Server.
 * Das ist der richtige Weg herum: die Regel gilt dort, wo sie gelten muss,
 * und hier steht nur die Hilfe dazu.
 */

document.querySelectorAll('form[data-plugin-trust]').forEach((form) => {
    const mark = form.querySelector('[data-plugin-trust-mark]');
    const submit = form.querySelector('[data-plugin-trust-submit]');

    if (!mark || !submit) {
        return;
    }

    const follow = () => {
        submit.disabled = !mark.checked;
    };

    mark.addEventListener('change', follow);
    follow();
});
