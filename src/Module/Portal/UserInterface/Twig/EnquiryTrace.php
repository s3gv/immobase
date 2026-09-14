<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Twig;

use DateTimeImmutable;

/**
 * Eine Zeile im Verlauf der Stammdaten: Betreff, Zeitpunkt, Zustand.
 *
 * Mehr steht dort nicht, und das ist der Punkt: es ist eine **Spur, kein
 * zweiter Posteingang.** Wer in den Stammdaten steht, will wissen, ob es
 * Kontakt gab und wann — gelesen und geantwortet wird dort, wo Anfragen
 * bearbeitet werden.
 */
final readonly class EnquiryTrace
{
    public function __construct(
        public string $id,
        public int $number,
        public string $subject,
        public DateTimeImmutable $at,
        public string $stateKey,
    ) {
    }
}
