<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Money\Money;

/**
 * Der Bericht, wie er auf dem Blatt steht.
 *
 * Dieselbe Gestalt fuer den Entwurf und fuer das zugestellte Schreiben: beim
 * Entwurf sind Ruecklage und Forderungen eben gerechnet, beim herausgegebenen
 * kommen sie aus dem, was eingefroren wurde. Die Vorschau zeigt damit genau
 * das, was herausgeht — und nicht eine zweite Rechnung, die ihr aehnelt.
 *
 * **Die Darlehen stehen fuer sich.** Sie werden gerechnet und nicht erfasst,
 * und sie mindern das Vermoegen wie eine Verbindlichkeit — aber sie stehen in
 * einem eigenen Block, damit sichtbar bleibt, welche Zahl aus dem
 * Tilgungsplan kommt und welche jemand eingetragen hat.
 *
 * **Die Ruecklage wird nicht addiert.** Ihr Geld liegt auf einem der Konten.
 * Wer den Kontostand aufzaehlt und die Ruecklage danebenlegt, hat das Vermoegen
 * beschrieben; wer beides addiert, hat dasselbe Geld zweimal gezaehlt.
 */
final readonly class ReportedAssets
{
    /**
     * @param list<AssetItem>     $items
     * @param list<ReportedClaim> $claims
     * @param list<ReportedDebt>  $debts
     */
    public function __construct(
        public ReportedReserve $reserve,
        public array $items,
        public array $claims,
        public array $debts,
    ) {
    }

    /** @return list<AssetItem> */
    public function of(AssetKind $kind): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (AssetItem $item): bool => $kind === $item->kind(),
        ));
    }

    /** Was auf den Konten liegt. */
    public function inTheBank(): Money
    {
        return self::sum($this->of(AssetKind::Bank));
    }

    /** Was davon die Erhaltungsruecklage traegt. */
    public function earmarked(): Money
    {
        return self::sum(array_values(array_filter(
            $this->of(AssetKind::Bank),
            static fn (AssetItem $item): bool => $item->isEarmarked(),
        )));
    }

    /** Die Verbindlichkeiten, positiv — sie werden abgezogen, nicht negativ hingeschrieben. */
    public function owed(): Money
    {
        return self::sum($this->of(AssetKind::Liability));
    }

    /** Die bewerteten Gegenstaende. Die unbewerteten stehen daneben und zaehlen nicht mit. */
    public function held(): Money
    {
        return self::sum($this->of(AssetKind::Holding));
    }

    /**
     * Wie viele Gegenstaende ohne Wert dastehen.
     *
     * Der Bericht sagt es, statt sie zu null zu machen. Gezaehlt werden nur
     * die, die ohne Wert dastehen **duerfen**: ein Konto ohne Stand ist keine
     * unbewertete Position, sondern eine Luecke, und die haelt die Herausgabe
     * ohnehin auf.
     */
    public function unvalued(): int
    {
        return \count(array_filter(
            $this->items,
            static fn (AssetItem $item): bool => !$item->isValued() && !$item->kind()->needsAnAmount(),
        ));
    }

    public function claimed(): Money
    {
        $sum = Money::zero();

        foreach ($this->claims as $claim) {
            $sum = $sum->plus($claim->amount);
        }

        return $sum;
    }

    /**
     * Was die Darlehen am Stichtag noch schulden.
     *
     * Positiv wie {@see owed()} — abgezogen wird in der Summe, nicht in der
     * Zahl. Sie steht neben den erfassten Verbindlichkeiten und nicht darin:
     * die eine wird getippt, die andere gerechnet, und wer das mischt, sucht
     * spaeter die Quelle einer Zahl.
     */
    public function borrowed(): Money
    {
        $sum = Money::zero();

        foreach ($this->debts as $debt) {
            $sum = $sum->plus($debt->outstanding);
        }

        return $sum;
    }

    /** Guthaben und Forderungen und bewertete Gegenstaende, minus Verbindlichkeiten und Darlehen. */
    public function total(): Money
    {
        return $this->inTheBank()
            ->plus($this->claimed())
            ->plus($this->held())
            ->minus($this->owed())
            ->minus($this->borrowed());
    }

    /**
     * Was auf den zweckgebundenen Konten fehlt.
     *
     * Die eigentliche Frage an einen Vermoegensbericht: ist das Geld, das die
     * Ruecklage ausweist, auch da? Null heisst gedeckt.
     *
     * Ohne ein einziges zweckgebundenes Konto gibt es die Frage nicht — dann
     * hat niemand gesagt, wo die Ruecklage liegt, und eine Fehlbetragsmeldung
     * waere eine Auskunft ueber die Eingabe und nicht ueber das Geld.
     */
    public function missingFromTheReserve(): ?Money
    {
        if ([] === array_filter($this->of(AssetKind::Bank), static fn (AssetItem $item): bool => $item->isEarmarked())) {
            return null;
        }

        $short = $this->reserve->closing()->minus($this->earmarked());

        return $short->isNegative() || $short->isZero() ? null : $short;
    }

    /** @param list<AssetItem> $items */
    private static function sum(array $items): Money
    {
        $sum = Money::zero();

        foreach ($items as $item) {
            $sum = $sum->plus($item->amount() ?? Money::zero());
        }

        return $sum;
    }
}
