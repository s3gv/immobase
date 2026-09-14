<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\Domain;

use App\Shared\Contact\Email;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;

/**
 * Wie jemand erreichbar ist.
 *
 * Mindestens eine E-Mail-Adresse ist Pflicht — sie traegt spaeter den
 * Portalzugang. Telefonnummern sind es nicht: dafuer gaebe es keinen
 * vergleichbaren Grund.
 *
 * Als Liste und nicht als einzelnes Feld, weil Menschen mehrere haben. Die
 * erste ist die, unter der sie erreicht werden; die Reihenfolge ist damit
 * eine Angabe und kein Zufall.
 */
#[ORM\Embeddable]
final class ContactDetails
{
    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $emails = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $phones = [];

    private function __construct()
    {
    }

    /**
     * @param list<Email>  $emails mindestens eine
     * @param list<string> $phones
     */
    public static function of(array $emails, array $phones = []): self
    {
        $addresses = array_values(array_unique(array_map(
            static fn (Email $email): string => $email->toString(),
            $emails,
        )));

        if ([] === $addresses) {
            throw new InvalidArgumentException('Mindestens eine E-Mail-Adresse ist nötig.');
        }

        $details = new self();
        $details->emails = $addresses;
        $details->phones = self::cleaned($phones);

        return $details;
    }

    /**
     * @return list<string>
     */
    public function emails(): array
    {
        return array_values($this->emails);
    }

    /**
     * Die Adresse, unter der jemand erreicht wird.
     *
     * Beim Erfassen ist mindestens eine Pflicht. Fehlt hier trotzdem eine, ist
     * der Datensatz auf einem Weg entstanden, den es nicht geben darf — dann
     * lieber laut scheitern als stillschweigend leer bleiben.
     */
    public function primary(): string
    {
        return $this->emails()[0] ?? throw new LogicException('Kontaktangaben ohne E-Mail-Adresse.');
    }

    /**
     * @return list<string>
     */
    public function phones(): array
    {
        return array_values($this->phones);
    }

    /**
     * @param list<string> $phones
     *
     * @return list<string>
     */
    private static function cleaned(array $phones): array
    {
        $trimmed = array_map(trim(...), $phones);

        return array_values(array_filter($trimmed, static fn (string $phone): bool => '' !== $phone));
    }
}
