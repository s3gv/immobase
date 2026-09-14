<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use InvalidArgumentException;
use SensitiveParameter;

/**
 * Bildet Schluessel und Codes auf ihre gespeicherte Form ab.
 *
 * HMAC mit einem Serverschluessel und nicht der blosse SHA-256, den diese
 * Klasse ersetzt hat. Der Grund liegt bei den *kurzen* Geheimnissen: ein Code
 * fuer den zweiten Faktor hat eine Million Moeglichkeiten, ein
 * Wiederherstellungscode rund 50 Bit. Wer einen Datenbankabzug in die Haende
 * bekommt, probiert die mit einem schnellen Hash in Minuten durch — ohne
 * Ratenbegrenzung, ohne dass es jemand merkt.
 *
 * Mit dem Schluessel geht das nicht: er steht in der Umgebung, nicht in der
 * Datenbank. Ein Abzug allein nuetzt damit nichts.
 *
 * Kein langsamer Passwort-Hash, obwohl er dasselbe Problem loeste: er
 * verhindert die Suche ueber den Index, und der Weg dahin — alle offenen
 * Codes eines Kontos einzeln pruefen — kostet bei zehn
 * Wiederherstellungscodes Sekunden. Bei einer Anmeldung ist das zu viel.
 *
 * Der Schluessel wird aus APP_SECRET abgeleitet und nicht direkt genommen:
 * ein Geheimnis, ein Zweck.
 */
final readonly class TokenHasher
{
    private string $key;

    public function __construct(#[SensitiveParameter] string $secret)
    {
        if ('' === $secret) {
            throw new InvalidArgumentException('Ohne APP_SECRET lassen sich keine Schlüssel ablegen.');
        }

        $this->key = hash_hkdf('sha256', $secret, 32, 'immobase.auth.token');
    }

    public function hash(#[SensitiveParameter] string $plain): string
    {
        return hash_hmac('sha256', $plain, $this->key);
    }

    public function matches(#[SensitiveParameter] string $plain, string $stored): bool
    {
        return hash_equals($stored, $this->hash($plain));
    }
}
