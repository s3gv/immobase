// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Die Ablagefläche für Dateien.
 *
 * Ohne JavaScript bleibt sie ein gewöhnliches Dateifeld — das ist der
 * Rückfall, nicht der Notbehelf: wer auf „Datei wählen" klickt, kommt genauso
 * ans Ziel. Dazu kommt hier das Ablegen per Maus und die Liste dessen, was
 * gewählt wurde.
 *
 * Die Grenzen — wie viele Dateien, wie groß, wie lange aufbewahrt — stehen
 * über der Fläche im HTML und werden hier nicht wiederholt: geprüft wird
 * ohnehin auf dem Server, und eine zweite Fassung derselben Zahl liefe
 * auseinander.
 */

function show(zone, files) {
    const list = zone.querySelector('[data-dropzone-list]');

    if (!list) {
        return;
    }

    list.textContent = files.length === 0
        ? list.dataset.empty
        : Array.from(files).map((file) => file.name).join(', ');
}

document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type="file"]');

    if (!(input instanceof HTMLInputElement)) {
        return;
    }

    const list = zone.querySelector('[data-dropzone-list]');

    if (list) {
        list.dataset.empty = list.textContent.trim();
    }

    input.addEventListener('change', () => show(zone, input.files));

    ['dragenter', 'dragover'].forEach((name) => {
        zone.addEventListener(name, (event) => {
            event.preventDefault();
            zone.classList.add('is-over');
        });
    });

    ['dragleave', 'drop'].forEach((name) => {
        zone.addEventListener(name, () => zone.classList.remove('is-over'));
    });

    zone.addEventListener('drop', (event) => {
        event.preventDefault();
        input.files = event.dataTransfer.files;
        show(zone, input.files);
    });
});
