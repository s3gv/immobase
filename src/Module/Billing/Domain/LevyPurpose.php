<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

/**
 * Wohin die Sonderumlage fliesst.
 *
 * Zwei Wege, und der Beschluss nennt einen davon:
 *
 * * **In die Erhaltungsruecklage.** Der uebliche Weg bei einer
 *   Erhaltungsmassnahme: die Eigentuemer zahlen ein, die Ruecklage waechst,
 *   und die Rechnungen werden als Entnahme daraus bezahlt. In der
 *   Jahresabrechnung steht sie dann nicht als Vorschuss — dort ergaebe sie ein
 *   Guthaben, dem keine Kosten gegenueberstehen. Sie steht im
 *   Ruecklagenauszug.
 * * **Direkt fuer die Massnahme.** Dann ist sie ein Vorschuss wie das
 *   Hausgeld: das Geld ist fuer diese Rechnungen bestimmt und wird in der
 *   Abrechnung dem Kostenanteil gegenuebergestellt.
 *
 * Bei einer **baulichen Veraenderung** gibt es nur den zweiten. Die
 * Erhaltungsruecklage ist zweckgebunden (§ 19 Abs. 2 Nr. 4 WEG) und gehoert
 * allen nach Miteigentumsanteilen; Geld, das nach § 21 Abs. 3 WEG nur die
 * Zustimmenden aufbringen, dort einzulegen machte es zum Vermoegen aller.
 */
enum LevyPurpose: string
{
    case ForTheReserve = 'reserve';
    case ForTheMeasure = 'measure';

    public function labelKey(): string
    {
        return 'billing.budget.levy_purpose.'.$this->value;
    }

    public function feedsTheReserve(): bool
    {
        return self::ForTheReserve === $this;
    }

    /** Was eine Massnahme dieser Art vorschlaegt. */
    public static function forA(MeasureKind $kind): self
    {
        return $kind->mayUseTheReserve() ? self::ForTheReserve : self::ForTheMeasure;
    }
}
