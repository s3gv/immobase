// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Ein Formular, das sich beim Umstellen selbst abschickt.
 *
 * Für Formulare, die aus nichts als einer Auswahl bestehen: „Verteilerschlüssel
 * und Maßeinheit" ist keine Eingabe, die man abschließt, sondern eine
 * Einstellung — ein Knopf daneben ist ein zweiter Klick für nichts.
 *
 * Dasselbe gilt für einen einzelnen Schalter: „bezahlt" ist umgelegt oder
 * nicht, und ein Knopf, der das Umlegen bestätigt, fragt nach etwas, das schon
 * gesagt wurde.
 *
 * Der Knopf steht trotzdem im HTML und wird hier nur ausgeblendet. Ohne
 * JavaScript ist er der einzige Weg, und ein Formular, das sich dann nicht
 * mehr abschicken lässt, wäre schlechter als ein Knopf zu viel.
 */

function enhance(form) {
    form.querySelectorAll('[data-auto-submit-fallback]').forEach((button) => {
        button.hidden = true;
    });

    form.addEventListener('change', (event) => {
        const target = event.target;
        const isSwitch = target instanceof HTMLInputElement && target.type === 'checkbox';

        if (target instanceof HTMLSelectElement || isSwitch) {
            form.requestSubmit();
        }
    });
}

document.querySelectorAll('form[data-auto-submit]').forEach(enhance);
