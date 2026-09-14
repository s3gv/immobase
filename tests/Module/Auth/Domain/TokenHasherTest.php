<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Domain;

use App\Module\Auth\Domain\TokenHasher;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Der Schutz der kurzen Einmal-Codes.
 *
 * Ein Code fuer den zweiten Faktor hat eine Million Moeglichkeiten. Als
 * blosser SHA-256 abgelegt, ist er aus einem Datenbankabzug in Minuten
 * zurueckgerechnet — ohne Ratenbegrenzung und ohne dass es jemand merkt.
 */
final class TokenHasherTest extends TestCase
{
    public function testTheStoredValueIsNotAPlainHashOfTheCode(): void
    {
        $stored = (new TokenHasher('ein-geheimnis'))->hash('123456');

        self::assertNotSame(hash('sha256', '123456'), $stored, 'Sonst genügt eine Regenbogentabelle');
    }

    /**
     * Der Schluessel steht in der Umgebung, nicht in der Datenbank. Wer nur
     * den Abzug hat, kann die gespeicherten Werte nicht nachbauen.
     */
    public function testAnotherSecretYieldsAnotherValue(): void
    {
        self::assertNotSame(
            (new TokenHasher('ein-geheimnis'))->hash('123456'),
            (new TokenHasher('ein anderes'))->hash('123456'),
        );
    }

    public function testTheSameCodeAlwaysYieldsTheSameValue(): void
    {
        $hasher = new TokenHasher('ein-geheimnis');

        self::assertSame($hasher->hash('123456'), $hasher->hash('123456'));
        self::assertTrue($hasher->matches('123456', $hasher->hash('123456')));
        self::assertFalse($hasher->matches('123457', $hasher->hash('123456')));
    }

    public function testWithoutASecretItRefusesToWork(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenHasher('');
    }
}
