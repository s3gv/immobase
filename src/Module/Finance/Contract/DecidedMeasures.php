<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Contract;

/**
 * Welche Massnahmen ein Objekt beschlossen hat.
 *
 * Damit eine Rechnung sagen kann, wofuer sie da ist. Die Finanzen fragen
 * hier, wenn jemand eine Kostenposition einer Massnahme zuordnet; Billing
 * beantwortet es. Der Vertrag steht bei den Finanzen, damit sie Billing nicht
 * kennen muessen — dieselbe Bauart wie {@see StatementSources}.
 *
 * **Nur beschlossene.** Ein Entwurf ist kein Vorgang, auf den sich eine
 * Ausgabe berufen koennte.
 */
interface DecidedMeasures
{
    /**
     * @return list<DecidedMeasure> das juengste Beschlussjahr zuerst
     */
    public function forProperty(string $propertyId): array;
}
