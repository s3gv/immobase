<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Woher eine Hausgeldstufe kommt.
 *
 * Zwei Herkuenfte, und der Unterschied ist keine Nebensache: eine Stufe aus
 * einem freigegebenen Wirtschaftsplan ist der Betrag, den die Versammlung
 * beschlossen hat. Eine von Hand eingetragene ist eine Behauptung der
 * Verwaltung. Wer die Hoehe eines Vorschusses bestreitet, fragt zuerst danach.
 *
 * Die Referenz steht als Text da und nicht als Verweis: sie soll lesbar
 * bleiben, auch wenn es den Plan einmal nicht mehr gaebe — und die Finanzen
 * duerfen die Abrechnungen nicht kennen.
 *
 * **Von Hand geaendert loescht die Herkunft nicht.** Die Versammlung darf
 * anders beschliessen, und die Verwaltung darf sich vertippen; beides muss
 * moeglich sein. Was nicht sein darf, ist dass eine stillschweigend geaenderte
 * Zahl wie ein Beschluss aussieht.
 */
#[ORM\Embeddable]
final class Origin
{
    #[ORM\Column(name: 'plan_reference', type: Types::STRING, length: 64, nullable: true)]
    private ?string $planReference;

    #[ORM\Column(name: 'changed_by_hand', type: Types::BOOLEAN)]
    private bool $changedByHand;

    private function __construct(?string $planReference, bool $changedByHand)
    {
        $this->planReference = $planReference;
        $this->changedByHand = $changedByHand;
    }

    /** Jemand hat sie eingetragen. */
    public static function byHand(): self
    {
        return new self(null, false);
    }

    /** Sie stammt aus einem freigegebenen Wirtschaftsplan. */
    public static function fromPlan(string $reference): self
    {
        return new self($reference, false);
    }

    /**
     * Dieselbe Herkunft, aber jemand hat den Betrag angefasst.
     *
     * Bei einer von Hand angelegten Stufe aendert das nichts: sie war schon
     * von Hand, und „von Hand geaendert" neben „von Hand angelegt" waere eine
     * Auskunft ohne Inhalt.
     */
    public function touched(): self
    {
        return null === $this->planReference ? $this : new self($this->planReference, true);
    }

    public function planReference(): ?string
    {
        return $this->planReference;
    }

    public function isFromAPlan(): bool
    {
        return null !== $this->planReference;
    }

    public function wasChangedByHand(): bool
    {
        return $this->changedByHand;
    }
}
