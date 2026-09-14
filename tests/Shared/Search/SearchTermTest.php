<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Search;

use App\Shared\Search\SearchTerm;
use PHPUnit\Framework\TestCase;

/**
 * Der Suchbegriff — einmal aufbereitet, damit alle Module dasselbe suchen.
 *
 * Was hier geprueft wird, sind die Zusagen, auf die sich jede Quelle
 * verlaesst: dass die Platzhalter draussen sind, dass eine Nummer als Nummer
 * erkannt wird und dass eine IBAN mit Leerzeichen dieselbe ist wie ohne.
 */
final class SearchTermTest extends TestCase
{
    public function testNothingAndTooShortAreTheSameAnswer(): void
    {
        self::assertNull(SearchTerm::orNull(null));
        self::assertNull(SearchTerm::orNull('   '));
        self::assertNull(SearchTerm::orNull('a'), 'Ein Zeichen ist keine Frage');
        self::assertNotNull(SearchTerm::orNull('ab'), 'Zwei Zeichen sind eine');
    }

    /** Der getippte Text bleibt fuer die Anzeige erhalten, klein wird verglichen. */
    public function testTheTypedTextSurvivesForTheHeading(): void
    {
        $term = SearchTerm::orNull('  Rosenweg  ');

        self::assertNotNull($term);
        self::assertSame('Rosenweg', $term->raw);
        self::assertSame('rosenweg', $term->text);
        self::assertSame('%rosenweg%', $term->contains());
    }

    /**
     * Ein Prozentzeichen ist in einem LIKE ein Platzhalter.
     *
     * Ohne diese Zusicherung faende „%" jeden Datensatz der Anwendung — und
     * zwar an jeder Quelle, weil jede dasselbe Muster benutzt.
     */
    public function testWildcardsAreNotInput(): void
    {
        $term = SearchTerm::orNull('%_ro\\senweg%');

        self::assertNotNull($term);
        self::assertSame('%rosenweg%', $term->contains());
    }

    /** „100" soll die Nummer 100 finden und nicht jede, in der eine 100 steht. */
    public function testANumberIsANumber(): void
    {
        $number = SearchTerm::orNull('20001');
        $word = SearchTerm::orNull('20001a');

        self::assertNotNull($number);
        self::assertNotNull($word);
        self::assertTrue($number->isNumber());
        self::assertSame(20001, $number->number());
        self::assertFalse($word->isNumber());
    }

    /** Gespeichert steht die IBAN am Stueck, getippt wird sie in Vierergruppen. */
    public function testAnIbanIsTheSameWithAndWithoutSpaces(): void
    {
        $spaced = SearchTerm::orNull('de02 1203 0000');
        $compact = SearchTerm::orNull('DE0212030000');

        self::assertNotNull($spaced);
        self::assertNotNull($compact);
        self::assertSame('DE0212030000', $spaced->compact());
        self::assertSame($compact->compact(), $spaced->compact());
    }
}
