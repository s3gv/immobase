<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Welche Vorauszahlung gemeint ist.
 *
 * Vier Sorten, zwei Zahler: Hausgeld und Sonderumlage zahlt der Eigentuemer,
 * die Nebenkosten der Mieter. Die Zahlungen liegen trotzdem in einer Tabelle
 * — die Frage „ist das Geld gekommen" ist jedes Mal dieselbe, und vier
 * Tabellen dafuer waeren viermal dieselbe Oberflaeche.
 *
 * Die **Sonderumlage** ist der Sonderfall unter ihnen: sie wiederholt sich
 * nicht. Hausgeld und Nebenkosten kommen aus einer Staffel und entstehen
 * daraus Monat fuer Monat; eine Sonderumlage wird einmal beschlossen und ist
 * an ihrem Tag faellig. Sie wird deshalb geschrieben und nicht abgeleitet —
 * und sie faellt auch nicht weg, wenn sich eine Staffel aendert.
 *
 * Und es gibt sie **zweimal**, weil der Beschluss zwei Wege kennt:
 *
 * * Die Sonderumlage **fuer die Massnahme** ist ein Vorschuss wie das
 *   Hausgeld. Sie steht in der Jahresabrechnung und wird dort dem Kostenanteil
 *   gegenuebergestellt.
 * * Die Sonderumlage **zur Erhaltungsruecklage** ist keiner. Sie fuellt die
 *   Ruecklage, und aus der werden die Rechnungen bezahlt. In der
 *   Ergebnisrechnung hat sie nichts verloren — dort erschiene ein Guthaben,
 *   das keines ist; sie steht im Ruecklagenauszug.
 *
 * Der Unterschied haengt am Beschluss und nicht an der Zahlung. Er steht
 * trotzdem hier, weil jede Stelle, die eine Zahlung in der Hand haelt, ihn
 * kennen muss — und weil eine Zahlung, die nach ihrem Budgetplan fragen
 * muesste, um sich selbst zu verstehen, keine Auskunft mehr gaebe.
 */
enum AdvanceKind: string
{
    case HouseMoney = 'house_money';
    case OperatingCosts = 'operating_costs';
    case SpecialLevy = 'special_levy';
    case ReserveLevy = 'reserve_levy';

    public function labelKey(): string
    {
        return 'finance.payment.kind.'.$this->value;
    }

    /** Wer sie schuldet — fuer die Abrechnung, die daraus Empfaenger macht. */
    public function isOwedByTheOwner(): bool
    {
        return self::OperatingCosts !== $this;
    }

    /**
     * Entsteht sie aus einer Staffel?
     *
     * Wer aus einer Staffel kommt, wird bei jedem Blick auf das Jahr
     * nachgezogen — neue Faelligkeiten kommen dazu, weggefallene verschwinden.
     * Eine Sonderumlage steht dagegen fuer sich: sie ist beschlossen, und ein
     * Blick auf die Zahlungsseite nimmt einen Beschluss nicht zurueck.
     */
    public function comesFromASchedule(): bool
    {
        return !$this->isALevy();
    }

    /** Beschlossen statt gestaffelt — beide Wege der Sonderumlage. */
    public function isALevy(): bool
    {
        return \in_array($this, [self::SpecialLevy, self::ReserveLevy], true);
    }

    /**
     * Fliesst sie der Erhaltungsruecklage zu?
     *
     * Dann ist sie kein Vorschuss auf die Kosten des Jahres: das Geld liegt
     * auf der Ruecklage, bis eine Rechnung daraus bezahlt wird.
     */
    public function feedsTheReserve(): bool
    {
        return self::ReserveLevy === $this;
    }
}
