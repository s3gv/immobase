// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

/**
 * Bewegt den Hintergrundumriss.
 *
 * Die Position ist eine reine Funktion der absoluten Uhrzeit: je zwei
 * überlagerte Schwingungen pro Achse, mit teilerfremden Perioden. Daraus folgt
 * dreierlei.
 *
 * Erstens wiederholt sich die Bahn praktisch nie — die kleinste gemeinsame
 * Periode zweier Primzahlen in Sekunden liegt bei Stunden bis Tagen. Eine
 * CSS-Animation kann das nicht: sie läuft zwangsläufig dieselbe Bahn ab und
 * kehrt um.
 *
 * Zweitens bleibt die Bewegung durch die Amplituden beschränkt, sodass immer
 * etwas im Bild ist und der Umriss nicht davonwandert.
 *
 * Drittens gibt es beim Neuladen und beim Seitenwechsel keinen Sprung: es wird
 * kein Zustand mitgeführt, der verloren gehen könnte. Die Uhr läuft weiter, die
 * Bewegung auch.
 */

const shape = document.querySelector('[data-drift]');

function readDrift(element) {
    try {
        return JSON.parse(element.getAttribute('data-drift'));
    } catch {
        return null;
    }
}

function positionAt(drift, seconds) {
    const [px1, px2, py1, py2] = drift.periods;
    const [phx1, phx2, phy1, phy2] = drift.phases;

    // Zwei Schwingungen je Achse, die zweite mit halber Amplitude. Die Summe
    // ist nie größer als die Amplitude — daher die Beschränkung.
    const x = drift.centreX
        + drift.amplitudeX * 0.67 * Math.sin((2 * Math.PI * seconds) / px1 + phx1)
        + drift.amplitudeX * 0.33 * Math.sin((2 * Math.PI * seconds) / px2 + phx2);

    const y = drift.centreY
        + drift.amplitudeY * 0.67 * Math.sin((2 * Math.PI * seconds) / py1 + phy1)
        + drift.amplitudeY * 0.33 * Math.sin((2 * Math.PI * seconds) / py2 + phy2);

    return [x, y];
}

function apply(element, drift, seconds) {
    const [x, y] = positionAt(drift, seconds);
    element.style.transform = `translate(${x.toFixed(2)}%, ${y.toFixed(2)}%)`;
}

if (null !== shape) {
    const drift = readDrift(shape);

    if (null !== drift) {
        const stillness = window.matchMedia('(prefers-reduced-motion: reduce)');
        // Die Einstellung der Installation kann Bewegung wegnehmen, nie
        // erzwingen — deshalb ein Oder und keine Überschreibung.
        const settled = () => stillness.matches
            || 'reduced' === document.documentElement.getAttribute('data-motion');

        const start = () => {
            if (settled()) {
                // Eine dauerhaft driftende Fläche ist für Menschen mit
                // vestibulären Beschwerden unbenutzbar. Dann eine feste
                // Position, aber dieselbe wie zu diesem Zeitpunkt.
                apply(shape, drift, Date.now() / 1000);
                return;
            }

            let last = 0;

            const step = (now) => {
                // Rund zehn Bilder pro Sekunde reichen: die Bewegung legt nur
                // wenige Pixel pro Sekunde zurück.
                if (now - last > 100) {
                    last = now;
                    apply(shape, drift, Date.now() / 1000);
                }

                window.requestAnimationFrame(step);
            };

            window.requestAnimationFrame(step);
        };

        start();
        stillness.addEventListener('change', () => window.location.reload());
    }
}
