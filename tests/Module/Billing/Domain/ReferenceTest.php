<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Billing\Domain;

use App\Module\Billing\Domain\Reference;
use App\Module\Billing\Domain\StatementKind;
use PHPUnit\Framework\TestCase;

/**
 * Die Nummer, unter der ein Schreiben angesprochen wird.
 *
 * Jedes traegt seine eigene: wer anruft, nennt eine Nummer, und eine, die der
 * ganze Lauf teilt, sagt nicht, um welche Wohnung es geht.
 */
final class ReferenceTest extends TestCase
{
    public function testItNamesKindObjectUnitYearRunAndIteration(): void
    {
        $reference = new Reference(StatementKind::OperatingCosts->shortName(), 20001, 1, 2025, 7, 1);

        self::assertSame('NK-20001/1-2025-7-1', $reference->toString());
    }

    /**
     * Eigentuemer und Mieter derselben Einheit tragen nicht dieselbe Nummer.
     *
     * Bei Sondereigentumsverwaltung bekommen beide im selben Lauf ein
     * Schreiben zur selben Einheit; ohne die Art vorn waeren die Nummern
     * Zeichen fuer Zeichen gleich.
     */
    public function testTheKindTellsTheTwoLettersOfOneUnitApart(): void
    {
        $owner = new Reference(StatementKind::HouseMoney->shortName(), 20001, 1, 2025, 7, 1);
        $tenant = new Reference(StatementKind::OperatingCosts->shortName(), 20001, 1, 2025, 7, 1);

        self::assertSame('HG-20001/1-2025-7-1', $owner->toString());
        self::assertSame('NK-20001/1-2025-7-1', $tenant->toString());
    }

    /**
     * Eine Korrektur behaelt alles bis auf die Iteration.
     *
     * Damit steht auf beiden Schreiben erkennbar dieselbe Abrechnung — die
     * Korrektur ist eine Ergaenzung und kein neuer Vorgang.
     */
    public function testACorrectionOnlyCountsUpTheIteration(): void
    {
        $original = new Reference(StatementKind::HouseMoney->shortName(), 20001, 3, 2025, 7, 1);
        $correction = new Reference(StatementKind::HouseMoney->shortName(), 20001, 3, 2025, 7, 2);

        self::assertSame('HG-20001/3-2025-7-1', $original->toString());
        self::assertSame('HG-20001/3-2025-7-2', $correction->toString());
    }
}
