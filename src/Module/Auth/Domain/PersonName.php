<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie ein Mensch heisst und was er tut.
 *
 * Die drei Angaben gehoeren zusammen: sie werden gemeinsam erfasst, gemeinsam
 * geaendert und gemeinsam angezeigt. Der Beruf steht bewusst hier und nicht
 * bei den Rollen — "Sachbearbeiterin" ist eine Auskunft ueber den Menschen,
 * keine Berechtigung.
 *
 * Leer ist ein gueltiger Zustand: ein frisch eingeladenes Konto kennt seinen
 * Namen noch nicht.
 */
#[ORM\Embeddable]
final readonly class PersonName
{
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $givenName;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $familyName;

    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $jobTitle;

    private function __construct(string $givenName, string $familyName, string $jobTitle)
    {
        $this->givenName = $givenName;
        $this->familyName = $familyName;
        $this->jobTitle = $jobTitle;
    }

    public static function unknown(): self
    {
        return new self('', '', '');
    }

    public static function of(string $givenName, string $familyName, string $jobTitle = ''): self
    {
        return new self(
            Trimmed::required($givenName, 'Vorname'),
            Trimmed::required($familyName, 'Nachname'),
            Trimmed::orNull($jobTitle) ?? '',
        );
    }

    public function givenName(): string
    {
        return $this->givenName;
    }

    public function familyName(): string
    {
        return $this->familyName;
    }

    public function jobTitle(): string
    {
        return $this->jobTitle;
    }

    public function isKnown(): bool
    {
        return '' !== $this->givenName && '' !== $this->familyName;
    }

    public function full(): string
    {
        return trim($this->givenName.' '.$this->familyName);
    }

    /**
     * Die Bestandteile, an denen ein Passwort scheitern soll.
     *
     * @return list<string>
     */
    public function parts(): array
    {
        return array_values(array_filter(
            [$this->givenName, $this->familyName],
            static fn (string $part): bool => '' !== $part,
        ));
    }
}
