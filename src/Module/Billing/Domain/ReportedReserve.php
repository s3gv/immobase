<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Module\Finance\Contract\ReserveStanding;
use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Stand der Erhaltungsruecklage, wie er im Bericht steht.
 *
 * § 28 Abs. 4 WEG verlangt den **Ist-Stand** zum Stichtag. Daneben steht die
 * Entwicklung im Berichtsjahr — nicht als Zierde: „42.000 Euro" allein sagt
 * nicht, ob im Berichtsjahr das Dach bezahlt wurde.
 *
 * Sie wird mit der Herausgabe eingefroren. Ein Bericht, der seinen
 * Ruecklagenstand jedes Mal neu aus den Buchungen holte, aenderte sich mit
 * der naechsten Entnahme — und zwar rueckwirkend, auf einem Blatt, das
 * jemand schon in der Hand hat.
 *
 * Alle Betraege sind Wirkungen: Entnahmen sind negativ, Zinsen duerfen es
 * sein. Damit ist der Schlussbestand die gerade Summe der Zeilen darueber.
 */
#[ORM\Embeddable]
final class ReportedReserve
{
    #[ORM\Column(name: 'reserve_opening', type: Types::BIGINT)]
    private int $opening;

    #[ORM\Column(name: 'reserve_contributions', type: Types::BIGINT)]
    private int $contributions;

    #[ORM\Column(name: 'reserve_levies', type: Types::BIGINT)]
    private int $specialLevies;

    #[ORM\Column(name: 'reserve_interest', type: Types::BIGINT)]
    private int $interest;

    #[ORM\Column(name: 'reserve_withdrawals', type: Types::BIGINT)]
    private int $withdrawals;

    #[ORM\Column(name: 'reserve_closing', type: Types::BIGINT)]
    private int $closing;

    private function __construct(
        int $opening,
        int $contributions,
        int $specialLevies,
        int $interest,
        int $withdrawals,
        int $closing,
    ) {
        $this->opening = $opening;
        $this->contributions = $contributions;
        $this->specialLevies = $specialLevies;
        $this->interest = $interest;
        $this->withdrawals = $withdrawals;
        $this->closing = $closing;
    }

    public static function nothing(): self
    {
        return new self(0, 0, 0, 0, 0, 0);
    }

    /** Derselbe Stand, wie ihn die Finanzen ausrechnen — hier ist er Inhalt eines Schreibens. */
    public static function of(ReserveStanding $standing): self
    {
        return new self(
            $standing->opening->cents(),
            $standing->contributions->cents(),
            $standing->specialLevies->cents(),
            $standing->interest->cents(),
            $standing->withdrawals->cents(),
            $standing->closing->cents(),
        );
    }

    public function opening(): Money
    {
        return Money::fromCents($this->opening);
    }

    public function contributions(): Money
    {
        return Money::fromCents($this->contributions);
    }

    public function specialLevies(): Money
    {
        return Money::fromCents($this->specialLevies);
    }

    public function interest(): Money
    {
        return Money::fromCents($this->interest);
    }

    public function withdrawals(): Money
    {
        return Money::fromCents($this->withdrawals);
    }

    public function closing(): Money
    {
        return Money::fromCents($this->closing);
    }

    /** Hat sich im Berichtsjahr etwas bewegt? */
    public function stoodStill(): bool
    {
        return 0 === $this->contributions
            && 0 === $this->specialLevies
            && 0 === $this->interest
            && 0 === $this->withdrawals;
    }
}
