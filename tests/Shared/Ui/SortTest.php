<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Ui;

use App\Shared\Ui\Sort;
use PHPUnit\Framework\TestCase;

/**
 * Die Sortierung kommt aus der Adresszeile und ist damit Eingabe.
 */
final class SortTest extends TestCase
{
    private const array ALLOWED = ['number', 'starts_on', 'status'];

    public function testTakesWhatIsAllowed(): void
    {
        $sort = Sort::of('starts_on', Sort::DESCENDING, self::ALLOWED, Sort::by('number'));

        self::assertSame('starts_on', $sort->field);
        self::assertTrue($sort->isDescending());
        self::assertSame('DESC', $sort->sql());
    }

    /**
     * Ein Feld, das es nicht gibt, ist kein Fehler. Sonst beantwortete eine
     * Uebersicht eine verdrehte Adresse mit einer Fehlerseite.
     */
    public function testAnUnknownFieldFallsBackInsteadOfFailing(): void
    {
        $fallback = Sort::by('number', Sort::DESCENDING);

        $sort = Sort::of('id; DROP TABLE tenancy', Sort::ASCENDING, self::ALLOWED, $fallback);

        self::assertSame('number', $sort->field);
        self::assertTrue($sort->isDescending(), 'Auch die Richtung kommt aus der Voreinstellung');
    }

    public function testAnUnknownDirectionIsAscending(): void
    {
        $sort = Sort::of('status', 'seitwärts', self::ALLOWED, Sort::by('number'));

        self::assertSame(Sort::ASCENDING, $sort->direction);
        self::assertSame('ASC', $sort->sql());
    }

    /**
     * Eine fremde Spalte beginnt aufsteigend, die eigene dreht um — alles
     * andere waere ein Knopf, dessen Wirkung man raten muss.
     */
    public function testAClickStartsAscendingAndThenTurnsAround(): void
    {
        $sort = Sort::by('number');

        self::assertSame(Sort::ASCENDING, $sort->nextDirectionFor('starts_on'));
        self::assertSame(Sort::DESCENDING, $sort->nextDirectionFor('number'));
        self::assertSame(Sort::ASCENDING, Sort::by('number', Sort::DESCENDING)->nextDirectionFor('number'));
    }

    public function testTellsAssistiveTechnologyWhatIsSorted(): void
    {
        $sort = Sort::by('number', Sort::DESCENDING);

        self::assertSame('descending', $sort->ariaFor('number'));
        self::assertSame('none', $sort->ariaFor('starts_on'));
        self::assertSame('ascending', Sort::by('number')->ariaFor('number'));
    }
}
