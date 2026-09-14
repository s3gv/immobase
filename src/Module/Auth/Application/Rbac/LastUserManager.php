<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application\Rbac;

use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;

/**
 * Die Regel, die verhindert, dass sich eine Installation selbst aussperrt.
 *
 * Frueher hiess sie „letzter Administrator". Seit Rechte weiterreichbar sind,
 * waere das zu eng: eine Installation, in der jemand die Verwaltung
 * uebernommen und den Administrator danach geloescht hat, haette dann keine
 * Sperre mehr. Es geht um das letzte aktive Konto, das noch `users.edit`
 * hat — egal, woher es das hat.
 *
 * Beide Zahlen kommen aus der Datenbank und keine aus dem geladenen Konto:
 * zwischen dem Laden und dieser Frage kann jemand anders dessen Rollen
 * geaendert haben, und ein zu altes „der kann ohnehin nichts verwalten" waere
 * genau die Auskunft, die die Sperre aushebelt.
 *
 * Die Frage allein genuegt nicht — sie muss unter derselben Sperre stehen wie
 * die Aenderung, die auf sie folgt. Siehe UserRepository::guardingManagers().
 */
final readonly class LastUserManager
{
    public function __construct(private UserRepository $users)
    {
    }

    public function isTheOnlyOne(User $user): bool
    {
        // Bleibt sonst noch jemand? Dann ist dieses Konto nicht der letzte.
        if ($this->users->countActiveManagers(AuthPermissions::USERS_EDIT, $user->id()) > 0) {
            return false;
        }

        // Sonst bleibt nur die Frage, ob es ueberhaupt eines ist. Ein Konto,
        // das nichts verwalten kann, schuetzt auch nichts.
        return $this->users->countActiveManagers(AuthPermissions::USERS_EDIT) > 0;
    }
}
