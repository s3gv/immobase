<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Die beschlossene Massnahme, fuer die eine Kostenposition da ist.
 *
 * Optional und meistens leer: die Grundsteuer gehoert zu keinem Beschluss.
 * Bei der Handwerkerrechnung, die aus einem Budgetplan bezahlt wird, ist sie
 * die Antwort auf die Frage, die sonst niemand beantworten kann — **wofuer
 * war das Geld, das die Eigentuemer eingezahlt haben**.
 *
 * Die Nummer ist die des Beschlusses, ohne Einheit und ohne Fassung; dieselbe
 * steht an der Sonderumlage, die die Massnahme bezahlt. Der Name steht
 * daneben und ist eingefroren: er soll lesbar bleiben, ohne dass die Finanzen
 * dafuer bei jeder Zeile in Billing nachfragen.
 */
#[ORM\Embeddable]
final class ChosenMeasure
{
    #[ORM\Column(name: 'measure_reference', type: Types::STRING, length: 40)]
    private string $reference;

    #[ORM\Column(name: 'measure_label', type: Types::STRING, length: 200)]
    private string $label;

    private function __construct(string $reference, string $label)
    {
        $this->reference = $reference;
        $this->label = $label;
    }

    public static function none(): self
    {
        return new self('', '');
    }

    /** Ohne Nummer gibt es keine Massnahme — ein Name allein waere Freitext. */
    public static function of(string $reference, string $label): self
    {
        $chosen = Trimmed::orNull($reference);

        return null === $chosen ? self::none() : new self($chosen, Trimmed::orNull($label) ?? '');
    }

    public function reference(): string
    {
        return $this->reference;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isChosen(): bool
    {
        return '' !== $this->reference;
    }
}
