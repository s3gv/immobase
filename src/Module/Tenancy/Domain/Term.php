<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Number\Quantity;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * Die Laufzeit: von wann bis wann, uebergeben wann, kuendbar mit welcher Frist.
 *
 * Leeres Ende heisst unbefristet — der Regelfall bei Wohnraum. Ein
 * Zeitmietvertrag traegt eines, und dann steht es da.
 *
 * Die Uebergabe ist nicht der Mietbeginn: Schluessel werden vorher oder
 * nachher uebergeben, und bei einem Streit ueber den Zustand der Wohnung ist
 * genau dieses Datum das gesuchte.
 *
 * Ein Ende vor dem Beginn gibt es nicht. Es waere kein Zeitraum, sondern ein
 * Zahlendreher — und die Staffel, die auf dem Beginn aufsetzt, haette danach
 * keinen Boden mehr.
 */
#[ORM\Embeddable]
final class Term
{
    #[ORM\Column(name: 'starts_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startsOn = null;

    #[ORM\Column(name: 'ends_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $endsOn = null;

    #[ORM\Column(name: 'handed_over_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $handedOverOn = null;

    #[ORM\Column(name: 'notice_period_months', type: Types::SMALLINT, nullable: true)]
    private ?int $noticePeriodMonths = null;

    private function __construct()
    {
    }

    public static function unknown(): self
    {
        return new self();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function of(
        ?DateTimeImmutable $startsOn,
        ?DateTimeImmutable $endsOn,
        ?DateTimeImmutable $handedOverOn,
        ?int $noticePeriodMonths,
    ): self {
        if (null !== $startsOn && null !== $endsOn && $endsOn < $startsOn) {
            throw new InvalidArgumentException('Das Mietende kann nicht vor dem Mietbeginn liegen.');
        }

        $noticePeriodMonths = Quantity::orNull($noticePeriodMonths, 'Die Kündigungsfrist');
        $term = new self();
        $term->startsOn = $startsOn;
        $term->endsOn = $endsOn;
        $term->handedOverOn = $handedOverOn;
        $term->noticePeriodMonths = $noticePeriodMonths;

        return $term;
    }

    public function startsOn(): ?DateTimeImmutable
    {
        return $this->startsOn;
    }

    public function endsOn(): ?DateTimeImmutable
    {
        return $this->endsOn;
    }

    public function handedOverOn(): ?DateTimeImmutable
    {
        return $this->handedOverOn;
    }

    public function noticePeriodMonths(): ?int
    {
        return $this->noticePeriodMonths;
    }

    /**
     * Dieselbe Laufzeit mit einem Ende.
     *
     * Das Beenden setzt es und laesst den Rest stehen. Ein Ende vor dem
     * Beginn faellt dabei durch dieselbe Pruefung wie beim Erfassen — beenden
     * kann man nur, was angefangen hat.
     *
     * Ohne Tag gibt es kein Ende: „beendet, aber wir wissen nicht wann" waere
     * ein Zustand, aus dem keine Abrechnung mehr herausfindet. Die Regel steht
     * hier, weil sie eine Regel ueber die Laufzeit ist.
     *
     * @throws TenancyNeedsAnEnd
     * @throws InvalidArgumentException
     */
    public function endingOn(?DateTimeImmutable $endsOn): self
    {
        if (null === $endsOn) {
            throw new TenancyNeedsAnEnd();
        }

        return self::of($this->startsOn, $endsOn, $this->handedOverOn, $this->noticePeriodMonths);
    }

    public function isOpenEnded(): bool
    {
        return null === $this->endsOn;
    }
}
