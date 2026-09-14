<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Auth\Contract\SignedInParty;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Wer im Portal sitzt — und wofuer er spricht.
 *
 * **Das Schloss des ganzen Moduls.** Jede Abfrage im Portal geht hier vorbei
 * und bekommt die Parteikennung aus der Sitzung, nie aus der Adresszeile.
 * Keine Methode dieses Moduls nimmt eine Kennung entgegen, die von aussen
 * kam, ohne sie gegen diese Antwort zu halten.
 *
 * Der Grund steht in jeder zweiten Meldung ueber Datenlecks in
 * Mieterportalen: eine Zahl in der Adresszeile hochzaehlen und die Daten des
 * Nachbarn sehen. Das kann hier nicht passieren, weil die Adresszeile gar
 * nicht gefragt wird.
 *
 * Die Riegel davor — `ROLE_PORTAL` statt `ROLE_USER` und ein leerer
 * Rechtesatz — halten Fremde aus dem Bereich heraus. Dieser hier haelt
 * Berechtigte voneinander fern.
 */
final readonly class PortalIdentity
{
    public function __construct(private SignedInParty $signedIn)
    {
    }

    /**
     * Die Partei des Angemeldeten.
     *
     * Kein Rueckgabewert null: wer hier ankommt, ist durch `access_control`
     * und damit ueber `ROLE_PORTAL` gekommen — fehlte die Partei trotzdem,
     * waere das kein Sonderfall, sondern ein Widerspruch.
     */
    public function partyId(): string
    {
        $partyId = $this->signedIn->partyId();

        if (null === $partyId) {
            throw new AccessDeniedHttpException('Dieses Konto spricht für niemanden.');
        }

        return $partyId;
    }

    /** Gehoert dieser Datensatz dem Angemeldeten? */
    public function owns(?string $partyId): bool
    {
        return null !== $partyId && $partyId === $this->partyId();
    }
}
