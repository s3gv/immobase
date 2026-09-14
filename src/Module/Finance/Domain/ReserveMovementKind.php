<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

/**
 * Was auf der Erhaltungsruecklage passiert ist.
 *
 * Fuenf Arten, und drei Regeln haengen daran:
 *
 * * **Zufuehrung** und **Sonderumlage** brauchen eine Einheit — sonst ist
 *   nicht nachvollziehbar, wer wie viel eingezahlt hat.
 * * **Zinsen** und **Entnahme** haengen am Objekt: sie treffen die
 *   Gemeinschaft, nicht eine Einheit.
 * * Der **Anfangsbestand** kommt hoechstens einmal vor. Ein zweiter waere
 *   keine Bewegung, sondern eine Korrektur — und die sagt man besser.
 *
 * Zinsen duerfen negativ sein: Verwahrentgelt gibt es.
 */
enum ReserveMovementKind: string
{
    case Opening = 'opening';
    case Contribution = 'contribution';
    case SpecialLevy = 'special_levy';
    case Interest = 'interest';
    case Withdrawal = 'withdrawal';

    public function labelKey(): string
    {
        return 'finance.reserve.kind.'.$this->value;
    }

    /**
     * Laesst sie sich von Hand buchen?
     *
     * Die Sonderumlage nicht mehr: sie entsteht aus einem Beschluss, und was
     * davon ankam, steht an den Zahlungen der Einheiten. Die Ruecklage zeigt
     * sie von dort — siehe {@see LevyComesFromAResolution}.
     */
    public function isBookable(): bool
    {
        return self::SpecialLevy !== $this;
    }

    /** Wer eingezahlt hat, muss nachvollziehbar sein. */
    public function needsAUnit(): bool
    {
        return \in_array($this, [self::Contribution, self::SpecialLevy], true);
    }

    /** Verwahrentgelt gibt es — sonst ist ein Betrag positiv. */
    public function mayBeNegative(): bool
    {
        return self::Interest === $this;
    }

    /** Entnahmen mindern den Bestand, alles andere mehrt ihn. */
    public function reducesTheBalance(): bool
    {
        return self::Withdrawal === $this;
    }
}
