// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Der Taschenrechner der Kopfzeile.
 *
 * Er rechnet in Fließkomma und zeigt zwei Nachkommastellen. Das ist für ein
 * Werkzeug am Rand vertretbar — in einer Abrechnung wäre es das nicht, und
 * genau deshalb schreibt er sein Ergebnis nirgendwohin. Es gibt einen Knopf,
 * der es in die Zwischenablage legt; den Rest entscheidet, wer ihn drückt.
 *
 * Das Dezimaltrennzeichen kommt aus der Anfrage und steht am Element — Komma
 * im Deutschen, Punkt im Englischen. Ein Rechner, der andere Regeln hätte als
 * das Betragsfeld daneben, wäre eine Fehlerquelle; getippt wird beides
 * angenommen, angezeigt wird die Schreibweise der Sprache.
 */

import { notice } from './notice.js';

/** So viele Nachkommastellen zeigt die Anzeige. */
const PLACES = 2;

/** Mehr Ziffern nimmt keine Eingabe an — danach wird ohnehin gerundet. */
const MOST_DIGITS = 15;

/** Wie lange „Kopiert" auf dem Knopf stehen bleibt. */
const CONFIRM_MS = 1500;

/** Was eine Taste auf der Tastatur bedeutet. */
const FROM_KEYBOARD = {
    '+': 'plus',
    '-': 'minus',
    '*': 'multiply',
    x: 'multiply',
    '/': 'divide',
    ':': 'divide',
    '%': 'percent',
    '=': 'equals',
    Enter: 'equals',
    Backspace: 'back',
    Delete: 'clear',
    c: 'clear',
};

/** Die Rechenzeichen, wie sie in der angefangenen Rechnung dastehen. */
const SIGNS = { plus: '+', minus: '−', multiply: '×', divide: '÷' };

function isDigit(value) {
    return value.length === 1 && value >= '0' && value <= '9';
}

/**
 * Eine Zahl, geschrieben in der Sprache der Anfrage.
 *
 * `Number(value.toFixed(2))` schneidet die Nullen am Ende weg: „4" und nicht
 * „4,00". Beim Kopieren ist beides gleich gut zu lesen, im Rechner ist das
 * kürzere das ruhigere.
 */
function written(value, decimal) {
    return String(Number(value.toFixed(PLACES))).replace('.', decimal);
}

/** Was in der Anzeige steht, als Zahl — die Anzeige ist der Speicher. */
function value(state) {
    return Number(state.typed.replace(state.decimal, '.'));
}

function compute(left, operation, right) {
    switch (operation) {
        case 'plus':
            return left + right;
        case 'minus':
            return left - right;
        case 'multiply':
            return left * right;
        default:
            return left / right;
    }
}

/**
 * Eine Ziffer kommt dazu.
 *
 * Nach einem Rechenzeichen fängt die Eingabe neu an — sonst hinge die nächste
 * Ziffer an der Zahl, die gerade als Ergebnis dasteht.
 */
function typeDigit(state, digit) {
    if (state.fresh || state.broken) {
        state.typed = digit;
        state.fresh = false;
        state.broken = false;

        return;
    }

    if (state.typed.replace(/[-,.]/g, '').length >= MOST_DIGITS) {
        return;
    }

    state.typed = state.typed === '0' ? digit : state.typed + digit;
}

function typeDecimal(state) {
    if (state.fresh) {
        state.typed = `0${state.decimal}`;
        state.fresh = false;

        return;
    }

    if (!state.typed.includes(state.decimal)) {
        state.typed += state.decimal;
    }
}

/**
 * Die angefangene Rechnung ausführen.
 *
 * Ohne offenes Rechenzeichen wird die getippte Zahl nur gemerkt: „12 +" hat
 * noch nichts zu rechnen, aber es hat etwas zu behalten.
 */
function settle(state) {
    const entry = value(state);

    state.stored = state.operation === null || state.stored === null
        ? entry
        : compute(state.stored, state.operation, entry);

    if (!Number.isFinite(state.stored)) {
        state.broken = true;
        state.stored = null;
        state.operation = null;

        return;
    }

    state.typed = written(state.stored, state.decimal);
}

/**
 * Prozent — was daneben steht, entscheidet mit.
 *
 * „200 + 10 %" sind 220 und nicht 200,1: bei Plus und Minus ist das Prozent
 * ein Anteil der Zahl davor. Bei Mal und Geteilt und ohne offene Rechnung ist
 * es der hundertste Teil seiner selbst. So rechnet jeder Taschenrechner, und
 * eine eigene Regel wäre hier die schlechtere.
 */
function percent(state) {
    const entry = value(state);
    const share = state.operation === 'plus' || state.operation === 'minus'
        ? (state.stored ?? 0) * entry / 100
        : entry / 100;

    state.typed = written(share, state.decimal);
    state.fresh = false;
}

function back(state) {
    const rest = state.typed.slice(0, -1);

    state.typed = rest === '' || rest === '-' ? '0' : rest;
    state.fresh = false;
}

function clear(state) {
    state.typed = '0';
    state.stored = null;
    state.operation = null;
    state.fresh = true;
    state.broken = false;
}

function operate(state, operation) {
    // Zwei Rechenzeichen hintereinander heißen: das zweite gilt. Sonst
    // rechnete „12 + ×" die Zwölf mit sich selbst.
    if (!state.fresh) {
        settle(state);
    }

    state.operation = operation;
    state.fresh = true;
}

function equals(state) {
    settle(state);
    state.operation = null;
    state.fresh = true;
}

function apply(state, key) {
    if (isDigit(key)) {
        typeDigit(state, key);

        return;
    }

    // Nach einer Division durch null steht „Fehler" da, und dahinter steht
    // keine Zahl mehr. Weiterrechnen ließe sich damit nur scheinbar; es
    // geht weiter, wer löscht oder eine neue Zahl tippt.
    if (state.broken && key !== 'clear') {
        return;
    }

    const steps = {
        decimal: () => typeDecimal(state),
        clear: () => clear(state),
        back: () => back(state),
        percent: () => percent(state),
        sign: () => {
            state.typed = state.typed.startsWith('-') ? state.typed.slice(1) : `-${state.typed}`;
        },
        equals: () => equals(state),
    };

    (steps[key] ?? (() => operate(state, key)))();
}

function draw(root, state) {
    const display = root.querySelector('[data-calc-display]');
    const pending = root.querySelector('[data-calc-pending]');

    display.textContent = state.broken ? root.dataset.error : state.typed;
    pending.textContent = state.operation === null || state.stored === null
        ? ''
        : `${written(state.stored, state.decimal)} ${SIGNS[state.operation]}`;
}

/**
 * Das Ergebnis in die Zwischenablage.
 *
 * Kopiert wird, was dasteht — mit dem Trennzeichen der Anfrage. Das
 * Betragsfeld nimmt beide Schreibweisen an; die Anzeige zu übernehmen ist
 * trotzdem das ehrlichere, weil dann im Feld steht, was man gesehen hat.
 */
async function copy(root, state) {
    const button = root.querySelector('[data-calc="copy"]');

    if (state.broken) {
        return;
    }

    try {
        await navigator.clipboard.writeText(state.typed);
    } catch {
        notice(root.dataset.failed);

        return;
    }

    const label = button.textContent;
    button.textContent = button.dataset.copied;
    window.setTimeout(() => {
        button.textContent = label;
    }, CONFIRM_MS);
}

/**
 * Welche Taste der Tastatur der Rechner versteht — und welche nicht.
 *
 * Beide Trennzeichen gelten: wer auf dem Ziffernblock den Punkt drückt, meint
 * im Deutschen das Komma. Eingeworfen wird trotzdem das Zeichen der Sprache.
 */
function keyFrom(event) {
    if (isDigit(event.key)) {
        return event.key;
    }

    if (event.key === ',' || event.key === '.') {
        return 'decimal';
    }

    // „C" und „X" zuerst so, wie sie kommen, dann klein: die Umschalttaste
    // soll nicht darüber entscheiden, ob gelöscht wird.
    return FROM_KEYBOARD[event.key] ?? FROM_KEYBOARD[event.key.toLowerCase()] ?? null;
}

function start(root) {
    const state = {
        typed: '0',
        stored: null,
        operation: null,
        fresh: true,
        broken: false,
        decimal: root.dataset.decimal ?? ',',
    };

    root.addEventListener('click', (event) => {
        const key = event.target.closest('[data-calc]');

        if (!(key instanceof HTMLElement)) {
            return;
        }

        if (key.dataset.calc === 'copy') {
            copy(root, state);

            return;
        }

        apply(state, key.dataset.calc);
        draw(root, state);

        // Nach einem Mausklick zurueck aufs Feld: sonst haette der zuletzt
        // gedrueckte Knopf die Eingabemarke, und die Eingabetaste betaetigte
        // ihn noch einmal statt zu rechnen. `detail` ist null, wenn der Klick
        // von der Tastatur kam — dann bleibt die Marke, wo sie war.
        if (event.detail !== 0) {
            root.querySelector('[data-calc-panel]').focus();
        }
    });

    root.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            root.open = false;
            root.querySelector('summary').focus();

            return;
        }

        // Ein Knopf oder die Zusammenfassung hat die Eingabemarke: Enter und
        // Leertaste gehören ihnen. Sonst löste eine Eingabe zwei Dinge aus —
        // das Angeklickte und das Gemeinte.
        const onControl = event.target.tagName === 'BUTTON' || event.target.tagName === 'SUMMARY';

        if ((event.key === 'Enter' || event.key === ' ') && onControl) {
            return;
        }

        const key = keyFrom(event);

        if (key === null) {
            return;
        }

        event.preventDefault();
        apply(state, key);
        draw(root, state);
    });

    // Zugeklappt wird von vorn gerechnet. Eine halbe Rechnung, die eine
    // Stunde später noch dasteht, ist keine Hilfe, sondern eine Falle.
    root.addEventListener('toggle', () => {
        if (root.open) {
            root.querySelector('[data-calc-panel]').focus();

            return;
        }

        clear(state);
        draw(root, state);
    });

    draw(root, state);
}

document.querySelectorAll('[data-calculator]').forEach(start);
