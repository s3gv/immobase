<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Contract;

use DateTimeImmutable;

/**
 * Wem eine Einheit in einem Abschnitt gehoerte.
 *
 * `from` und `to` sind bereits der Schnitt aus Eigentumsdauer und gefragtem
 * Zeitraum — wer im Juli kauft, kommt hier mit dem 1. Juli an. Das Schneiden
 * geschieht dort, wo die Zeitraeume liegen, und nicht beim Aufrufer: sonst
 * muesste jeder Aufrufer es selbst tun, und der zweite taete es anders.
 *
 * Ein Abschnitt endet, sobald sich die Eigentuemerschaft aendert — auch dann,
 * wenn nur einer von zweien wechselt.
 */
final readonly class OwnershipSpan
{
    /**
     * @param list<OwnerShare> $owners mindestens einer
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public array $owners,
    ) {
    }

    /** Die Tage dieses Abschnitts — beide Enden zaehlen mit. */
    public function days(): int
    {
        return (int) $this->from->diff($this->to)->days + 1;
    }

    /** Wer hier steht, in fester Reihenfolge — zum Vergleich zweier Abschnitte. */
    public function fingerprint(): string
    {
        $owners = array_map(
            static fn (OwnerShare $share): string => $share->partyId.':'.$share->mea,
            $this->owners,
        );
        sort($owners);

        return implode('|', $owners);
    }
}
