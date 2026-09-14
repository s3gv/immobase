<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Zeitraum, ueber den ein Lauf abrechnet — eingefroren.
 *
 * Die Regel steht am Objekt: Tag und Monat, an denen das Wirtschaftsjahr
 * beginnt. Sie darf sich aendern — eine Verwaltung stellt vom Kalenderjahr
 * auf den 1. Juli um. Ein Lauf, der seinen Zeitraum jedes Mal neu aus dem
 * Objekt zusammensetzte, waere nach einer solchen Umstellung ein Lauf ueber
 * einen anderen Zeitraum: Mietzeiten, Vorauszahlungen und Zeitanteile wuerden
 * gegen etwas gerechnet, das so nie abgerechnet wurde, und eine Korrektur
 * korrigierte nicht mehr ihr Original.
 *
 * Der erste Tag ist die einzige Angabe. Die Jahreszahl folgt aus ihm — ein
 * Wirtschaftsjahr heisst nach dem Jahr, in dem es beginnt — und das Ende ist
 * der Tag vor dem naechsten Beginn. Sie steht trotzdem in einer eigenen
 * Spalte: danach wird gesucht und gruppiert. Zwei Spalten, eine Eingabe, kein
 * Widerspruch moeglich.
 */
#[ORM\Embeddable]
final class FiscalPeriod
{
    #[ORM\Column(name: 'fiscal_year', type: Types::SMALLINT)]
    private int $year;

    #[ORM\Column(name: 'period_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $from;

    private function __construct(int $year, DateTimeImmutable $from)
    {
        $this->year = $year;
        $this->from = $from;
    }

    public static function beginningOn(DateTimeImmutable $from): self
    {
        return new self((int) $from->format('Y'), $from);
    }

    public function year(): int
    {
        return $this->year;
    }

    public function from(): DateTimeImmutable
    {
        return $this->from;
    }

    /** Der Tag vor dem naechsten Beginn. */
    public function to(): DateTimeImmutable
    {
        return $this->from->modify('+1 year')->modify('-1 day');
    }
}
