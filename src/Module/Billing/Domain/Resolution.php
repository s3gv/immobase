<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Beschluss, mit dem die Vorschuesse gelten.
 *
 * Beschlossen wird nach § 28 Abs. 1 WEG nicht der Plan, sondern die
 * Vorschuesse — dieselbe Verschiebung wie bei der Abrechnung, wo nur noch die
 * Abrechnungsspitze beschlossen wird. Was hier steht, ist deshalb die
 * Grundlage jeder Forderung, die daraus entsteht: wer ein Hausgeld
 * bestreitet, fragt nach Datum und Ergebnis.
 *
 * Alle drei Angaben sind freiwillig. Ein Plan darf aufgestellt sein, bevor
 * die Versammlung getagt hat, und eine Verwaltung, die ihre Beschlusssammlung
 * anders fuehrt, soll hier nichts erfinden muessen. Was dasteht, steht auf dem
 * Schreiben; was fehlt, fehlt dort auch.
 */
#[ORM\Embeddable]
final class Resolution
{
    #[ORM\Column(name: 'decided_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $decidedOn;

    /** „einstimmig", „mit 8 von 10 Stimmen" — wie es im Protokoll steht. */
    #[ORM\Column(name: 'decision_outcome', type: Types::STRING, length: 200)]
    private string $outcome;

    /** Die Nummer in der Beschluss-Sammlung nach § 24 Abs. 7 WEG. */
    #[ORM\Column(name: 'decision_number', type: Types::STRING, length: 32)]
    private string $number;

    private function __construct(?DateTimeImmutable $decidedOn, string $outcome, string $number)
    {
        $this->decidedOn = $decidedOn;
        $this->outcome = $outcome;
        $this->number = $number;
    }

    public static function none(): self
    {
        return new self(null, '', '');
    }

    public static function of(?DateTimeImmutable $decidedOn, string $outcome, string $number): self
    {
        return new self(
            $decidedOn,
            Trimmed::orNull($outcome) ?? '',
            Trimmed::orNull($number) ?? '',
        );
    }

    public function decidedOn(): ?DateTimeImmutable
    {
        return $this->decidedOn;
    }

    public function outcome(): string
    {
        return $this->outcome;
    }

    public function number(): string
    {
        return $this->number;
    }

    /** Gibt es ueberhaupt etwas zu schreiben? */
    public function isRecorded(): bool
    {
        return null !== $this->decidedOn;
    }
}
