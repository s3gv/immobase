<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

/**
 * Wie Symfony ein Konto sieht.
 *
 * Vier Methoden, die es nur wegen `UserInterface` gibt und die keine
 * fachliche Frage beantworten. Getrennt vom Rest, damit in User die Regeln
 * des Kontos stehen und nicht die Anschluesse des Rahmenwerks.
 */
trait SignsInWithSymfony
{
    /**
     * Eine Basisrolle — und es ist nicht fuer alle dieselbe.
     *
     * Was ein Mitarbeiter darf, entscheidet der PermissionVoter anhand des
     * Rechtekatalogs. ROLE_ADMIN gibt es nicht mehr — es war genau die
     * Abkuerzung, die die Rechteverwaltung abloest.
     *
     * **Ein Portalkonto bekommt `ROLE_USER` gar nicht erst.** Der letzte
     * Eintrag in `access_control` verlangt sie fuer jede Adresse, die nicht
     * ausdruecklich freigegeben ist — und damit ist der ganze
     * Verwalterbereich zu, auch die Seite, die es morgen erst gibt. Eine
     * Liste, die jemand pflegen muesste, gibt es nicht.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return $this->isPortalAccount() ? ['ROLE_PORTAL'] : ['ROLE_USER'];
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** Es liegt nichts im Speicher, was zu loeschen waere. */
    public function eraseCredentials(): void
    {
    }
}
