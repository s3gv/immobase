<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie ein Anteil zustande kam.
 *
 * Die Rechtsprechung verlangt, dass ein durchschnittlicher Mieter den
 * Verteilerschluessel erkennen und seinen Anteil **nachrechnen** kann. Das
 * geht nur, wenn der ganze Weg dasteht: welcher Schluessel, wie er heisst,
 * was er bedeutet, welcher Teil vom Ganzen — und, wenn zeitanteilig gerechnet
 * wurde, wie viele Tage von wie vielen.
 *
 * Steht zusammen, weil es zusammengehoert: ein Anteil ohne seinen Nenner ist
 * eine Zahl ohne Aussage, und die Tage ohne den Anteil erklaeren nichts.
 */
#[ORM\Embeddable]
final class Distribution
{
    #[ORM\Column(name: 'key_label', type: Types::STRING, length: 120)]
    private string $key;

    /** „nach Wohnfläche" — der Satz, der den Schluessel erklaert. */
    #[ORM\Column(name: 'key_explanation', type: Types::STRING, length: 400)]
    private string $explanation;

    /**
     * Anteil und Ganzes — als Text und nicht als Dezimalspalte.
     *
     * Sie werden nie gerechnet, nur gezeigt. Eine `DECIMAL(14,3)` fuellte
     * „78,40" auf „78,400" auf, und dann stuende in der Vorschau etwas
     * anderes als auf dem zugestellten Schreiben. Was geprueft wurde, muss
     * das sein, was ankommt — bis auf die Stelle hinter dem Komma.
     */
    #[ORM\Column(name: 'share_of', type: Types::STRING, length: 32)]
    private string $shareOf;

    #[ORM\Column(name: 'share_total', type: Types::STRING, length: 32)]
    private string $shareTotal;

    #[ORM\Column(name: 'total_quantity', type: Types::STRING, length: 32, nullable: true)]
    private ?string $quantity;

    #[ORM\Column(name: 'unit_of_measure', type: Types::STRING, length: 8, nullable: true)]
    private ?string $measure;

    #[ORM\Column(name: 'days_of', type: Types::SMALLINT, nullable: true)]
    private ?int $daysOf;

    #[ORM\Column(name: 'days_total', type: Types::SMALLINT, nullable: true)]
    private ?int $daysTotal;

    private function __construct(
        string $key,
        string $explanation,
        string $shareOf,
        string $shareTotal,
        ?string $quantity,
        ?string $measure,
        ?int $daysOf,
        ?int $daysTotal,
    ) {
        $this->key = $key;
        $this->explanation = $explanation;
        $this->shareOf = $shareOf;
        $this->shareTotal = $shareTotal;
        $this->quantity = $quantity;
        $this->measure = $measure;
        $this->daysOf = $daysOf;
        $this->daysTotal = $daysTotal;
    }

    public static function by(
        string $key,
        string $explanation,
        string $shareOf,
        string $shareTotal,
    ): self {
        return new self($key, $explanation, $shareOf, $shareTotal, null, null, null, null);
    }

    /**
     * Dieselbe Verteilung, anders erklaert.
     *
     * Der Wirtschaftsplan braucht das: nach „erfasstem Verbrauch" verteilt er
     * nicht — fuer ein kuenftiges Jahr gibt es keinen. Er nimmt den des
     * Vorjahres, und das muss auf dem Blatt stehen, sonst behauptet die Zeile
     * eine Messung, die es nicht gibt.
     */
    public function explainedBy(string $explanation): self
    {
        return new self(
            $this->key,
            $explanation,
            $this->shareOf,
            $this->shareTotal,
            $this->quantity,
            $this->measure,
            $this->daysOf,
            $this->daysTotal,
        );
    }

    /** Dieselbe Verteilung, aber mit einer erfassten Menge dahinter. */
    public function measuring(?string $quantity, ?string $measure): self
    {
        return new self(
            $this->key,
            $this->explanation,
            $this->shareOf,
            $this->shareTotal,
            $quantity,
            $measure,
            $this->daysOf,
            $this->daysTotal,
        );
    }

    /** Dieselbe Verteilung, zeitanteilig auf diese Tage. */
    public function forDays(int $daysOf, int $daysTotal): self
    {
        return new self(
            $this->key,
            $this->explanation,
            $this->shareOf,
            $this->shareTotal,
            $this->quantity,
            $this->measure,
            $daysOf,
            $daysTotal,
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function explanation(): string
    {
        return $this->explanation;
    }

    public function shareOf(): string
    {
        return $this->shareOf;
    }

    public function shareTotal(): string
    {
        return $this->shareTotal;
    }

    public function quantity(): ?string
    {
        return $this->quantity;
    }

    public function measure(): ?string
    {
        return $this->measure;
    }

    public function daysOf(): ?int
    {
        return $this->daysOf;
    }

    public function daysTotal(): ?int
    {
        return $this->daysTotal;
    }

    /** Wurde hier nach Tagen geteilt? */
    public function isSplitByDay(): bool
    {
        return null !== $this->daysOf && $this->daysOf !== $this->daysTotal;
    }
}
