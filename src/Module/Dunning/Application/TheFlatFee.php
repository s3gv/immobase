<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\Notice;
use App\Shared\Money\Money;

/**
 * Die Pauschale nach § 288 Abs. 5 BGB — wem sie zusteht und wie hoch.
 *
 * Sie steht dem Glaeubiger einer Entgeltforderung gegen einen
 * **Nicht-Verbraucher** zu, und zwar **je Forderung**. Deckt ein Schreiben
 * drei davon, sind es drei Pauschalen; deckt es nur Forderungen gegen einen
 * Verbraucher, ist es keine — auch dann nicht, wenn das Haekchen gesetzt war.
 *
 * **Darum wird sie hier gerechnet und nicht im Formular gelesen.** Ein
 * Betragsfeld im Formular hiesse, dass man den Betrag hineinschreiben kann:
 * ein umgebogener POST berechnete einem Verbraucher vierzig Euro, die ihm
 * niemand berechnen darf.
 *
 * Eine Forderung, an der sie schon vermerkt ist, traegt sie nicht noch
 * einmal. Der Vermerk sitzt an der Forderung und nicht am Schreiben — sonst
 * stuende sie beim naechsten Schreiben wieder da.
 */
final readonly class TheFlatFee
{
    public function __construct(private DunningSettings $settings)
    {
    }

    /**
     * Was ein Schreiben an Pauschale traegt.
     *
     * @param list<Claim> $claims die Forderungen, die es deckt
     */
    public function on(Notice $notice, array $claims): Money
    {
        if (!$notice->charges()->flatFeeWanted()) {
            return Money::zero();
        }

        return $this->settings->flatFee()->multipliedBy(\count(array_filter($claims, self::isBorneBy(...))));
    }

    /** Ob sie an dieser Forderung ueberhaupt in Frage kommt. */
    public static function isBorneBy(Claim $claim): bool
    {
        return $claim->debtor()->isCommercial() && !$claim->debtor()->flatFeeClaimed();
    }

    /**
     * Ob sie ueberhaupt zur Wahl steht.
     *
     * @param list<Claim> $claims
     */
    public static function mayBeCharged(array $claims): bool
    {
        return [] !== array_filter($claims, self::isBorneBy(...));
    }
}
