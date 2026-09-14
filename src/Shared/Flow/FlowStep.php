<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Flow;

/**
 * Ein Schritt in einem gefuehrten Ablauf.
 *
 * Beschriftung und Erklaerung sind Uebersetzungsschluessel, keine Texte: kein
 * Anzeigetext steht hartkodiert im Code.
 *
 * Die Erklaerung ist Pflicht, nicht optional. Ein Schritt ohne Erklaerung ist
 * genau das, was das Multi-Step-Prinzip vermeiden soll.
 */
final readonly class FlowStep
{
    /**
     * @param string|null $titleKey Ueberschrift im Panel, falls sie sich von
     *                              der Beschriftung in der Liste
     *                              unterscheiden soll
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $explanationKey,
        private ?string $titleKey = null,
    ) {
    }

    /**
     * Die Ueberschrift ueber dem Schritt.
     *
     * Meist dasselbe wie in der Liste — die soll aber kurz sein, und manchmal
     * braucht die Ueberschrift mehr Worte: "Sicherheit" in der Liste,
     * "Zwei-Faktor-Authentifizierung" darueber.
     */
    public function titleKey(): string
    {
        return $this->titleKey ?? $this->labelKey;
    }
}
