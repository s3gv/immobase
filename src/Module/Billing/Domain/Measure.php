<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Was vorhat, wer einen Budgetplan aufstellt.
 *
 * Bezeichnung, Art und das Jahr, in dem es losgeht — die drei Angaben, aus
 * denen sich alles Weitere ergibt. Die **Art** ist dabei die folgenreichste
 * Angabe des ganzen Ablaufs: an ihr haengt, wer die Kosten traegt.
 *
 * **Die Amortisation ist eine Behauptung mit Folgen.** Wer sie eintraegt,
 * sagt: diese Massnahme rechnet sich in so vielen Jahren. Trifft das zu,
 * tragen die Kosten nach § 21 Abs. 2 Nr. 2 WEG alle Eigentuemer — auch ohne
 * qualifizierte Mehrheit. Als angemessen gelten rund zehn Jahre; eine
 * Photovoltaikanlage liegt in aller Regel darunter.
 */
#[ORM\Embeddable]
final class Measure
{
    /** Der uebliche Rahmen fuer „angemessen" — laenger ist begruendungsbeduerftig. */
    public const int REASONABLE_YEARS = 10;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label;

    #[ORM\Column(name: 'measure_kind', type: Types::STRING, length: 16, enumType: MeasureKind::class)]
    private MeasureKind $kind;

    #[ORM\Column(name: 'first_year', type: Types::SMALLINT)]
    private int $firstYear;

    /** In wie vielen Jahren sie sich rechnet — leer heisst: nicht behauptet. */
    #[ORM\Column(name: 'amortises_in', type: Types::SMALLINT, nullable: true)]
    private ?int $amortisesIn;

    private function __construct(string $label, MeasureKind $kind, int $firstYear, ?int $amortisesIn)
    {
        $this->label = $label;
        $this->kind = $kind;
        $this->firstYear = $firstYear;
        $this->amortisesIn = $amortisesIn;
    }

    public static function of(string $label, MeasureKind $kind, int $firstYear, ?int $amortisesIn = null): self
    {
        return new self(
            Trimmed::orNull($label) ?? '',
            $kind,
            $firstYear,
            null === $amortisesIn || $amortisesIn < 1 ? null : min(99, $amortisesIn),
        );
    }

    public function label(): string
    {
        return $this->label;
    }

    public function kind(): MeasureKind
    {
        return $this->kind;
    }

    public function firstYear(): int
    {
        return $this->firstYear;
    }

    public function amortisesIn(): ?int
    {
        return $this->amortisesIn;
    }

    /** Rechnet sie sich in angemessener Zeit? */
    /**
     * Wohin die Sonderumlage dieser Massnahme fliessen darf.
     *
     * Gewuenscht ist das eine, erlaubt manchmal nur das andere: die
     * Erhaltungsruecklage ist zweckgebunden (§ 19 Abs. 2 Nr. 4 WEG). Wer nach
     * § 21 Abs. 3 WEG allein fuer eine bauliche Veraenderung zahlt, legte sein
     * Geld dort in das Vermoegen aller — darum gibt es diesen Weg nur bei
     * Erhaltung, und zwar unabhaengig davon, was jemand eintraegt.
     */
    public function levyMay(LevyPurpose $wanted): LevyPurpose
    {
        return $this->kind->mayUseTheReserve() ? $wanted : LevyPurpose::ForTheMeasure;
    }

    public function amortisesReasonably(): bool
    {
        return null !== $this->amortisesIn && $this->amortisesIn <= self::REASONABLE_YEARS;
    }
}
