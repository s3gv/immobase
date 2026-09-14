<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use RuntimeException;

/**
 * Auf Wohnraummiete gibt es keine Umsatzsteuer.
 *
 * Die Vermietung ist nach § 4 Nr. 12 a UStG steuerfrei, und die Option nach
 * § 9 UStG steht nur offen, wenn der Mieter Unternehmer ist und das Objekt
 * fuer vorsteuerunschaedliche Umsaetze verwendet. Bei Wohnraum ist das
 * ausgeschlossen.
 *
 * Die Absage steht hier und nicht im Formular: wer sie nur ausblendet,
 * schuetzt vor dem Formular und nicht vor der Anwendung. Und was hier
 * durchginge, stuende spaeter auf einer Rechnung — wer Umsatzsteuer
 * ausweist, schuldet sie nach § 14c, ob er sie bekommen hat oder nicht.
 */
final class TaxOnlyForCommercial extends RuntimeException
{
    public static function of(): self
    {
        return new self('tenancy.error.vat_only_commercial');
    }
}
