<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Pdf;

/**
 * Wer den Brief schickt — so viel, wie ein Briefkopf davon braucht.
 *
 * Bewusst schmal: Name, Anschrift, Logo. Ein Briefkopf traegt keine
 * Bankverbindung und keine Registernummer; die stehen im Fuss oder im Text,
 * und was hier nicht steht, kann auch nicht versehentlich oben landen.
 *
 * Liegt in Shared, damit {@see LetterHead} ohne ein Fachmodul auskommt. Wer
 * die Angaben hat, reicht sie ueber {@see SenderOfLetters} herein.
 */
final readonly class Sender
{
    public function __construct(
        public string $name,
        public string $street,
        public string $postalCode,
        public string $city,
        /** Die Bytes unmittelbar: ein PDF entsteht auf dem Server und braucht keinen Umweg ueber HTTP. */
        public ?string $logo = null,
    ) {
    }

    /** Die Absenderzeile ueber dem Anschriftfeld — ohne leere Glieder. */
    public function oneLine(): string
    {
        $parts = array_filter(
            [$this->name, $this->street, trim($this->postalCode.' '.$this->city)],
            static fn (string $part): bool => '' !== $part,
        );

        return implode(' · ', $parts);
    }
}
