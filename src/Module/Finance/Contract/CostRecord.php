<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

use App\Shared\Money\Money;

/**
 * Ein Kostenposten eines Wirtschaftsjahres, so viel wie die Abrechnung
 * davon sehen muss.
 *
 * Bewusst flach und ohne Entity: wer verteilt, braucht Betrag, Schluessel und
 * Beschriftungen — nicht die Kostenposition mit ihren Jahren, ihrer Historie
 * und ihren Regeln.
 *
 * Die Beschriftungen kommen mit, weil die Abrechnung sie **einfriert**. Wird
 * eine Kostenart spaeter umbenannt, steht auf dem alten Schreiben weiter der
 * alte Name — so, wie er zugestellt wurde.
 */
final readonly class CostRecord
{
    public function __construct(
        /** Die Kennung des Jahreswertes — sie geht als Quelle in die Abrechnung ein. */
        public string $costYearId,
        public int $itemNumber,
        /** Die Kennung der Kostenart — der Wirtschaftsplan haelt sie fest. */
        public string $costKindId,
        public string $kindLabel,
        public bool $apportionable,
        /** Teilt sich der Posten bei einem Wechsel tagesgenau? */
        public bool $splitsByDay,
        public string $keyId,
        public string $keyLabel,
        /** area | mea | persons | units | metered | fixed */
        public string $keyKind,
        public Money $total,
        /**
         * Die Umsatzsteuer, die im Betrag steckt.
         *
         * Nur fuer Mietverhaeltnisse mit Umsatzsteuer von Belang: dort wird
         * netto umgelegt. Sie verteilt sich mit denselben Gewichten wie der
         * Betrag.
         */
        public Money $inputTax,
        /** m3, l, kwh … — nur bei erfassten Schluesseln */
        public ?string $measure,
        /** @var array<string, MeteredValue> Kennung der Einheit auf ihren Wert */
        public array $perUnit,
    ) {
    }
}
