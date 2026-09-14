<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Money\Money;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Die Nebenforderungen eines Schreibens.
 *
 * Mahnkosten und die Pauschale nach § 288 Abs. 5 BGB. Beide sind kein Teil
 * der Forderung, sondern Schaden aus dem Verzug — und beide gehoeren darum
 * zum Schreiben und nicht zur Forderung.
 *
 * **Die Zahlungserinnerung traegt nie Mahnkosten.** Das entscheidet die Stufe
 * und nicht eine Einstellung: Mahnkosten sind Verzugsschaden, und das erste
 * Schreiben loest den Verzug erst aus (BGH VIII ZR 95/18). Dieselbe
 * Entscheidung sagt auch, dass nur tatsaechliche Kosten hineingehoeren —
 * Porto, Papier, Umschlag, nicht die Arbeitszeit.
 *
 * **Bei der Pauschale wird die Entscheidung gespeichert und nicht der
 * Betrag.** Ob sie angesetzt wird, sagt der Bearbeiter; wie hoch sie ausfaellt,
 * rechnet die Anwendung — vierzig Euro je Forderung, die sie ueberhaupt
 * traegt. Stuende der Betrag im Formular, koennte ihn jemand hineinschreiben,
 * und ein Verbraucher bekaeme eine Pauschale, die ihm niemand berechnen darf.
 */
#[ORM\Embeddable]
final class Charges
{
    #[ORM\Column(type: Types::BIGINT)]
    private int $costs;

    #[ORM\Column(name: 'flat_fee_wanted', type: Types::BOOLEAN)]
    private bool $flatFeeWanted;

    #[ORM\Column(name: 'flat_fee', type: Types::BIGINT)]
    private int $flatFee;

    private function __construct(int $costs, bool $flatFeeWanted, int $flatFee)
    {
        $this->costs = $costs;
        $this->flatFeeWanted = $flatFeeWanted;
        $this->flatFee = $flatFeeWanted ? $flatFee : 0;
    }

    public static function none(): self
    {
        return new self(0, false, 0);
    }

    /** Was die Stufe nicht tragen darf, traegt sie auch nicht. */
    public static function of(DunningLevel $level, Money $costs, bool $wantsTheFlatFee): self
    {
        return new self($level->mayCharge() ? $costs->cents() : 0, $wantsTheFlatFee, 0);
    }

    /** Der gerechnete Betrag — null Forderungen, die sie tragen, heisst null Euro. */
    public function amounting(Money $flatFee): self
    {
        return new self($this->costs, $this->flatFeeWanted, $flatFee->cents());
    }

    public function costs(): Money
    {
        return Money::fromCents($this->costs);
    }

    public function flatFeeWanted(): bool
    {
        return $this->flatFeeWanted;
    }

    public function flatFee(): Money
    {
        return Money::fromCents($this->flatFee);
    }

    public function total(): Money
    {
        return $this->costs()->plus($this->flatFee());
    }
}
