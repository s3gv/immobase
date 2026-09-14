// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Light/Dark umschalten und die Wahl merken.
 *
 * Ohne gespeicherte Wahl folgt die Oberfläche der Systemeinstellung. Sobald
 * jemand einmal umschaltet, gilt seine Wahl — auch wenn das System etwas
 * anderes sagt.
 */

const STORAGE_KEY = 'immobase.theme';

function preferredTheme() {
    let stored = null;

    try {
        stored = window.localStorage.getItem(STORAGE_KEY);
    } catch {
        // Privater Modus oder blockierte Speicherung: dann eben Systemwahl.
    }

    if ('light' === stored || 'dark' === stored) {
        return stored;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);

    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', String('dark' === theme));
    });
}

function toggleTheme() {
    const next = 'dark' === document.documentElement.getAttribute('data-theme') ? 'light' : 'dark';

    try {
        window.localStorage.setItem(STORAGE_KEY, next);
    } catch {
        // Nicht speicherbar — die Wahl gilt dann nur für diese Sitzung.
    }

    applyTheme(next);
}

applyTheme(preferredTheme());

document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target.closest('[data-theme-toggle]') : null;

    if (null !== target) {
        event.preventDefault();
        toggleTheme();
    }
});
