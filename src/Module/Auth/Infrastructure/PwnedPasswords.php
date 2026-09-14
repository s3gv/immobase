<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Infrastructure;

use App\Module\Auth\Application\LeakedPasswords;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fragt Have I Been Pwned, ohne das Passwort zu verraten.
 *
 * Uebertragen werden die ersten fuenf Zeichen des SHA-1-Hashes. Der Dienst
 * antwortet mit allen ihm bekannten Hashes, die so beginnen — mehrere
 * hundert —, und der Vergleich findet hier statt. Weder das Passwort noch
 * sein vollstaendiger Hash verlassen diesen Server.
 *
 * "Add-Padding" laesst die Antwort immer gleich gross ausfallen. Ohne das
 * verriete ihre Laenge, wonach gefragt wurde.
 *
 * SHA-1 ist hier kein Fehler: die Schnittstelle ist so definiert, und es geht
 * um einen Nachschlag, nicht um Geheimhaltung.
 */
final readonly class PwnedPasswords implements LeakedPasswords
{
    private const string ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
    ) {
    }

    public function isKnown(string $password): bool
    {
        $hash = strtoupper(sha1($password));

        try {
            return $this->matches($this->fetch(substr($hash, 0, 5)), substr($hash, 5));
        } catch (ExceptionInterface|TransportException $failure) {
            // Kein Netz, kein Urteil. Eine Installation ohne Internetzugang
            // soll niemanden aussperren.
            $this->logger->notice('Leak-Prüfung nicht erreichbar: {reason}', ['reason' => $failure->getMessage()]);

            return false;
        }
    }

    private function fetch(string $prefix): string
    {
        return $this->http->request('GET', self::ENDPOINT.$prefix, [
            'headers' => ['Add-Padding' => 'true'],
            'timeout' => 3,
        ])->getContent();
    }

    private function matches(string $answer, string $suffix): bool
    {
        foreach (explode("\n", $answer) as $line) {
            $parts = array_pad(explode(':', trim($line), 2), 2, '0');
            $candidate = (string) ($parts[0] ?? '');
            $count = (string) ($parts[1] ?? '0');

            // Aufgefuellte Zeilen tragen die Anzahl 0 und sind Attrappen.
            if (hash_equals($candidate, $suffix) && '0' !== trim($count)) {
                return true;
            }
        }

        return false;
    }
}
