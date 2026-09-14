<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie abgestimmt wurde — und was daraus folgt.
 *
 * Die beiden Zahlen werden getippt statt gerechnet: **nach welchem Prinzip
 * gestimmt wird, steht in der Gemeinschaftsordnung** und nicht bei uns. Kopf-,
 * Objekt- oder Wertprinzip — die Versammlung zaehlt, wir nehmen das Ergebnis
 * entgegen.
 *
 * Die zustimmenden Miteigentumsanteile rechnet die Anwendung dagegen selbst:
 * sie kennt die Anteile, und welche Einheit zugestimmt hat, steht als
 * {@see BudgetApproval} daneben. Zwei Wege zu derselben Zahl waeren einer zu
 * viel.
 *
 * Die **Kostentragung** steht hier, weil sie mit der Freigabe feststeht und
 * danach nicht mehr neu gerechnet werden darf: wer zahlt, hat die Versammlung
 * entschieden, nicht der naechste Seitenaufruf.
 */
#[ORM\Embeddable]
final class Verdict
{
    #[ORM\Column(name: 'votes_cast', type: Types::INTEGER)]
    private int $cast;

    #[ORM\Column(name: 'votes_for', type: Types::INTEGER)]
    private int $for;

    #[ORM\Column(name: 'cost_bearing', type: Types::STRING, length: 24, nullable: true, enumType: CostBearing::class)]
    private ?CostBearing $bearing;

    private function __construct(int $cast, int $for, ?CostBearing $bearing)
    {
        $this->cast = $cast;
        $this->for = $for;
        $this->bearing = $bearing;
    }

    public static function none(): self
    {
        return new self(0, 0, null);
    }

    public function counted(int $cast, int $for): self
    {
        $cast = max(0, $cast);

        return new self($cast, min($cast, max(0, $for)), $this->bearing);
    }

    /** Mit der Freigabe steht fest, wer traegt. */
    public function bearing(CostBearing $bearing): self
    {
        return new self($this->cast, $this->for, $bearing);
    }

    public function cast(): int
    {
        return $this->cast;
    }

    public function for(): int
    {
        return $this->for;
    }

    public function wasCounted(): bool
    {
        return $this->cast > 0;
    }

    public function bears(): ?CostBearing
    {
        return $this->bearing;
    }

    /**
     * Mehr als zwei Drittel der abgegebenen Stimmen — § 21 Abs. 2 Nr. 1 WEG.
     *
     * „Mehr als" ist woertlich zu nehmen: bei drei abgegebenen Stimmen
     * genuegen zwei nicht.
     */
    public function isQualified(): bool
    {
        return $this->cast > 0 && $this->for * 3 > $this->cast * 2;
    }
}
