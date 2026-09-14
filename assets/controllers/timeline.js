// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Die Uhr der Übersicht und die Terminlisten am Zeitstrahl.
 *
 * Beides ist Zutat: der Server rendert die Uhrzeit mit, und die Listen stehen
 * als gewöhnliche Blöcke in der Seite. Ohne dieses Skript steht die Uhr
 * still und die Listen sind über die Tastatur erreichbar — nur eben nicht
 * beim Überfahren.
 */

/** Sekunden zeigt die Uhr nicht, also genügt der Blick einmal pro Sekunde. */
const TICK_MS = 1000;

/** So lange bleibt eine Liste nach dem Verlassen noch offen. */
const GRACE_MS = 200;

function tick() {
    const clock = document.querySelector('[data-clock] time');

    if (clock === null) {
        return;
    }

    const now = new Date();
    const shown = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

    // Nur schreiben, wenn sich etwas geändert hat: eine Sekündlich neu
    // gesetzte Zeichenkette lässt Screenreader jede Sekunde vorlesen.
    if (clock.textContent !== shown) {
        clock.textContent = shown;
        clock.setAttribute('datetime', shown);
    }
}

function openList(mark) {
    for (const other of document.querySelectorAll('.ib-year__mark.is-open')) {
        if (other !== mark) {
            closeList(other);
        }
    }

    mark.classList.add('is-open');
    mark.querySelector('.ib-year__list').hidden = false;
    mark.querySelector('.ib-year__pin').setAttribute('aria-expanded', 'true');
    place(mark);
}

function closeList(mark) {
    mark.classList.remove('is-open');
    mark.querySelector('.ib-year__list').hidden = true;
    mark.querySelector('.ib-year__pin').setAttribute('aria-expanded', 'false');
}

/**
 * Die Liste bleibt im Bild.
 *
 * Ein Termin im Januar steht am linken Rand, einer im Dezember am rechten —
 * mittig unter dem Punkt liefe die Liste dort aus der Seite hinaus. Gemessen
 * wird gegen den Balken und nicht gegen das Fenster: der Balken ist der
 * Bereich, in dem sie stehen darf.
 */
function place(mark) {
    const list = mark.querySelector('.ib-year__list');
    const bar = mark.closest('.ib-year__bar');

    list.style.transform = '';

    const room = bar.getBoundingClientRect();
    const box = list.getBoundingClientRect();
    const overRight = box.right - room.right;
    const overLeft = room.left - box.left;

    if (overRight > 0) {
        list.style.transform = `translateX(calc(-50% - ${overRight}px))`;
    } else if (overLeft > 0) {
        list.style.transform = `translateX(calc(-50% + ${overLeft}px))`;
    }
}

function start(mark) {
    let leaving = null;

    const show = () => {
        window.clearTimeout(leaving);
        openList(mark);
    };

    // Der Aufschub lässt den Weg vom Punkt in die Liste zu. Ohne ihn klappt
    // sie zu, sobald der Zeiger den Punkt verlässt — und damit ist eine
    // scrollbare Liste nicht zu benutzen.
    const hide = () => {
        leaving = window.setTimeout(() => {
            if (!mark.matches(':hover') && !mark.contains(document.activeElement)) {
                closeList(mark);
            }
        }, GRACE_MS);
    };

    mark.addEventListener('mouseenter', show);
    mark.addEventListener('mouseleave', hide);
    mark.addEventListener('focusin', show);
    mark.addEventListener('focusout', hide);

    // Ein Klick hält sie fest: auf einem Zeigegerät ohne Überfahren — einem
    // Finger — gibt es nichts anderes.
    mark.querySelector('.ib-year__pin').addEventListener('click', () => {
        if (mark.classList.contains('is-open')) {
            closeList(mark);
        } else {
            openList(mark);
        }
    });
}

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        document.querySelectorAll('.ib-year__mark.is-open').forEach(closeList);
    }
});

if (document.querySelector('[data-clock]') !== null) {
    tick();
    window.setInterval(tick, TICK_MS);
}

document.querySelectorAll('.ib-year__mark').forEach(start);
