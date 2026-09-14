<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Application\Rbac\LastUserManager;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;

/**
 * Was ein Verwalter mit einem fremden Konto tun darf.
 *
 * Die Regeln stehen hier und nicht im Controller, weil dieselben Fragen an
 * drei Stellen gebraucht werden: in der Uebersicht (welche Knoepfe sind
 * abgeschaltet), auf der Detailseite und beim tatsaechlichen Ausfuehren.
 *
 * Auskunft und Ausfuehrung sind trotzdem zweierlei. Beim Zeichnen genuegt
 * `reasonAgainstTouching()`; beim Ausfuehren muss dieselbe Frage *unter der
 * Sperre* noch einmal gestellt werden, weil sonst zwei gleichzeitige Anfragen
 * beide dasselbe letzte Konto treffen. Dafuer ist `guarded()` da.
 */
final readonly class ManageUser
{
    public function __construct(
        private UserRepository $users,
        private LastUserManager $lastManager,
    ) {
    }

    /**
     * Darf $actor dieses Konto anfassen?
     *
     * Zwei Sperren: niemand trifft sich selbst, und das letzte aktive Konto,
     * das noch Benutzer verwalten kann, bleibt stehen. Sonst sperrt sich eine
     * Installation selbst aus, und bei Selbst-Hosting gibt es keinen Support,
     * der es richtet.
     */
    public function reasonAgainstTouching(User $user, string $actorId): ?string
    {
        if ($user->id() === $actorId) {
            return 'user.protected.self';
        }

        if ($this->lastManager->isTheOnlyOne($user)) {
            return 'user.protected.last_manager';
        }

        return null;
    }

    public function mayTouch(User $user, string $actorId): bool
    {
        return null === $this->reasonAgainstTouching($user, $actorId);
    }

    /**
     * Fuehrt eine Aenderung aus — aber nur, wenn sie unter der Sperre noch
     * erlaubt ist.
     *
     * Die Pruefung davor beim Zeichnen der Seite haelt niemanden auf, der das
     * Formular nachbaut, und sie haelt auch keine zweite Anfrage auf, die im
     * selben Augenblick laeuft. Deshalb steht sie hier noch einmal, gemeinsam
     * mit der Aenderung in einer Transaktion.
     *
     * @param callable(): string $change fuehrt aus und gibt die Erfolgsmeldung zurueck
     */
    public function guarded(User $user, string $actorId, callable $change): ChangeOutcome
    {
        return $this->users->guardingManagers(
            AuthPermissions::USERS_EDIT,
            function () use ($user, $actorId, $change): ChangeOutcome {
                $reason = $this->reasonAgainstTouching($user, $actorId);

                return null === $reason
                    ? ChangeOutcome::done($change())
                    : ChangeOutcome::refused($reason);
            },
        );
    }

    public function toggleActivation(User $user, string $actorId): ChangeOutcome
    {
        return $this->guarded($user, $actorId, fn (): string => $this->flip($user));
    }

    public function delete(User $user, string $actorId): ChangeOutcome
    {
        return $this->guarded($user, $actorId, function () use ($user): string {
            $this->users->remove($user);

            return 'user.deleted';
        });
    }

    /** @return 'user.deactivated'|'user.reactivated' */
    private function flip(User $user): string
    {
        if (!$user->status()->isBlocked()) {
            $user->deactivate();
            $this->users->save($user);

            return 'user.deactivated';
        }

        $user->reactivate();
        $this->users->save($user);

        return 'user.reactivated';
    }
}
