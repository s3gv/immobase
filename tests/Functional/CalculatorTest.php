<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Der Rechner spricht die Schreibweise der Anfrage.
 *
 * Gerechnet wird im Browser; was hier geprueft wird, ist das eine, was der
 * Server dazu beitraegt — welches Zeichen die Nachkommastellen abtrennt. Ein
 * Rechner mit Punkt neben einem Betragsfeld mit Komma ist eine Fehlerquelle,
 * und zwar eine, die niemand bemerkt, bis ein Betrag um den Faktor hundert
 * danebenliegt.
 */
final class CalculatorTest extends WebTestCase
{
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTestUser();

        parent::tearDown();
    }

    public function testTheGermanCalculatorUsesTheComma(): void
    {
        $client = self::signedInAs();
        $client->request('GET', '/');

        self::assertSelectorExists('[data-calculator][data-decimal=","]');
    }

    public function testTheEnglishCalculatorUsesThePoint(): void
    {
        $client = self::signedInAs();
        $client->request('GET', '/locale/en');
        $client->request('GET', '/');

        self::assertSelectorExists('[data-calculator][data-decimal="."]');
    }

    protected static function testEmail(): string
    {
        return 'calculator-test@example.org';
    }
}
