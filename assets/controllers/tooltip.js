// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Zeigt zu jedem Element mit data-tooltip einen Hinweis beim Überfahren.
 *
 * Der Hinweis hängt am Ende des Dokuments und wird über Bildschirmkoordinaten
 * platziert, nicht als Kindelement des Knopfes. Der Grund: Symbolknöpfe stehen
 * meist in Tabellenzeilen, und die Tabelle scrollt in einem eigenen Kasten.
 * Ein Hinweis darin würde abgeschnitten oder erzeugte einen Rollbalken.
 *
 * Vorgelesen wird er nicht — die Beschriftung steht bereits als aria-label am
 * Knopf, und doppelt vorgelesen hilft niemandem.
 */

const GAP = 8;

let tooltip = null;
let shownFor = null;

function element() {
    if (null === tooltip) {
        tooltip = document.createElement('div');
        tooltip.className = 'ib-tooltip';
        tooltip.setAttribute('aria-hidden', 'true');
        document.body.append(tooltip);
    }

    return tooltip;
}

function place(anchor) {
    const target = anchor.getBoundingClientRect();
    const own = element().getBoundingClientRect();
    const above = target.top - own.height - GAP;
    const centred = target.left + target.width / 2 - own.width / 2;
    const rightmost = window.innerWidth - own.width - GAP;

    // Oben kein Platz — dann darunter. Seitlich am Rand bleiben.
    element().style.top = `${above < 0 ? target.bottom + GAP : above}px`;
    element().style.left = `${Math.min(Math.max(GAP, centred), Math.max(GAP, rightmost))}px`;
}

function show(anchor) {
    const text = anchor.getAttribute('data-tooltip');

    if (null === text || '' === text || shownFor === anchor) {
        return;
    }

    shownFor = anchor;
    element().textContent = text;
    element().classList.add('is-visible');
    place(anchor);
}

function hide() {
    shownFor = null;

    if (null !== tooltip) {
        tooltip.classList.remove('is-visible');
    }
}

function anchorOf(event) {
    return event.target instanceof Element ? event.target.closest('[data-tooltip]') : null;
}

['mouseover', 'focusin'].forEach((name) => {
    document.addEventListener(name, (event) => {
        const anchor = anchorOf(event);

        if (null === anchor) {
            hide();

            return;
        }

        show(anchor);
    });
});

['mouseout', 'focusout', 'click'].forEach((name) => {
    document.addEventListener(name, hide);
});

document.addEventListener('keydown', (event) => {
    if ('Escape' === event.key) {
        hide();
    }
});

// Beim Rollen wandert der Knopf, der Hinweis nicht — also weg damit.
window.addEventListener('scroll', hide, true);
