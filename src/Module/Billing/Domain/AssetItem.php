<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine erfasste Position des Gemeinschaftsvermoegens.
 *
 * Erfasst und nicht gerechnet, und das ist eine Entscheidung: es gibt in
 * dieser Anwendung keine Buchhaltung, aus der ein Kontostand kaeme. Eine
 * gerechnete Zahl waere hier eine erfundene.
 *
 * **Der Betrag darf fehlen.** Bei einem Gegenstand ist das der Normalfall —
 * die Gartengeraete gehoeren in die Aufstellung, auch wenn sie niemand
 * bewertet hat. Sie werden dann gelistet und nicht summiert; eine Null waere
 * die Behauptung, sie seien nichts wert. Bei Konten und Verbindlichkeiten
 * haelt der fehlende Betrag die Herausgabe auf.
 *
 * Eingefroren wird sie nicht eigens: nach der Herausgabe aendert sich am
 * Bericht nichts mehr, und eine berichtigte Fassung bekommt eigene Zeilen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_asset_item')]
class AssetItem
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AssetReport::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssetReport $report;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $ordering;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: AssetKind::class)]
    private AssetKind $kind;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label = '';

    /** Leer heisst: nicht bewertet. Nicht: null Euro wert. */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $amount = null;

    #[ORM\Column(name: 'is_earmarked', type: Types::BOOLEAN)]
    private bool $earmarked = false;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    public function __construct(AssetReport $report, int $ordering, AssetKind $kind)
    {
        $this->id = Uuid::v4();
        $this->report = $report;
        $this->ordering = $ordering;
        $this->kind = $kind;
        $report->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ordering(): int
    {
        return $this->ordering;
    }

    public function kind(): AssetKind
    {
        return $this->kind;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function amount(): ?Money
    {
        return null === $this->amount ? null : Money::fromCents($this->amount);
    }

    public function isValued(): bool
    {
        return null !== $this->amount;
    }

    public function isEarmarked(): bool
    {
        return $this->earmarked && $this->kind->mayBeEarmarked();
    }

    /**
     * Was die Position zum Vermoegen beitraegt.
     *
     * Eine unbewertete traegt nichts bei — und wird darum auch nicht mit null
     * mitgezaehlt, sondern gesondert genannt.
     */
    public function effect(): Money
    {
        $amount = $this->amount();

        if (null === $amount) {
            return Money::zero();
        }

        return $this->kind->reducesTheAssets() ? $amount->multipliedBy(-1) : $amount;
    }

    public function describe(string $label, ?Money $amount, bool $earmarked, string $note): void
    {
        $this->label = trim($label);
        $this->amount = $amount?->cents();
        $this->earmarked = $earmarked && $this->kind->mayBeEarmarked();
        $this->note = Trimmed::orNull($note) ?? '';
    }

    /**
     * Dieselbe Position in einer berichtigten Fassung — mitsamt Betrag.
     *
     * Berichtigt wird derselbe Stichtag: die Konten standen an dem Tag so,
     * wie sie standen, und nur eine Angabe war falsch. Wer dafuer fuenfzehn
     * unveraenderte Betraege neu eintippen muesste, tippt sich einen neuen
     * Fehler.
     */
    public function copyInto(AssetReport $report): self
    {
        $copy = $this->carryInto($report);
        $copy->amount = $this->amount;

        return $copy;
    }

    /**
     * Dieselbe Position im Bericht des naechsten Jahres — ohne Betrag.
     *
     * Der Stichtag ist ein anderer, also ist der Stand ein anderer. Ein
     * vorbelegter Kontostand von vor einem Jahr saehe aus wie eingegeben und
     * waere die gefaehrlichste Zahl im ganzen Bericht. Die Bezeichnungen
     * duerfen mit: sie aendern sich selten, und sie abzutippen ist die
     * Arbeit, die niemand machen will.
     */
    public function carryInto(AssetReport $report): self
    {
        $copy = new self($report, $this->ordering, $this->kind);
        $copy->label = $this->label;
        $copy->earmarked = $this->earmarked;
        $copy->note = $this->note;

        return $copy;
    }
}
