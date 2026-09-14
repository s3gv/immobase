<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Module\Auth\Fixture;

use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Domain\SecondFactorSettings;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserFilter;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use App\Shared\Ui\Page;
use Throwable;

/**
 * Ein Benutzerbestand im Speicher.
 *
 * Fuer Tests, die die Anwendungsschicht pruefen und keine Datenbank brauchen.
 * Als eigene Klasse und nicht als anonyme Klasse im Test: die Schnittstelle
 * waechst, und dann muesste jede anonyme Kopie einzeln nachgezogen werden.
 */
final class InMemoryUserRepository implements UserRepository
{
    /** @var list<User> */
    private array $users;

    private int $nextNumber = 1001;

    /**
     * Ohne Rechtequelle zaehlt nur die Systemrolle.
     *
     * Das genuegt den meisten Tests: sie haben Administratoren und gewoehnliche
     * Konten und keine Matrix dazwischen.
     */
    private ?EffectivePermissions $effective = null;

    /** Der Rechtebestand, der bei einem Ruecklauf mitgenommen wird. */
    private ?InMemoryPermissionAssignments $assignments = null;

    public function __construct(User ...$users)
    {
        $this->users = array_values($users);
    }

    public function save(User $user): void
    {
        foreach ($this->users as $known) {
            if ($known->id() === $user->id()) {
                return;
            }
        }

        $this->users[] = $user;
    }

    public function remove(User $user): void
    {
        $this->users = array_values(array_filter(
            $this->users,
            static fn (User $known): bool => $known->id() !== $user->id(),
        ));
    }

    public function findByEmail(Email $email): ?User
    {
        foreach ($this->users as $user) {
            if ($user->email()->toString() === $email->toString()) {
                return $user;
            }
        }

        return null;
    }

    public function byId(string $id): ?User
    {
        foreach ($this->users as $user) {
            if ($user->id() === $id) {
                return $user;
            }
        }

        return null;
    }

    public function byNumber(int $number): ?User
    {
        foreach ($this->users as $user) {
            if ($user->number() === $number) {
                return $user;
            }
        }

        return null;
    }

    public function nextNumber(): int
    {
        return $this->nextNumber++;
    }

    public function countMatching(UserFilter $filter): int
    {
        return \count($this->users);
    }

    public function matching(UserFilter $filter, Page $page): array
    {
        return $this->users;
    }

    /**
     * Im Speicher gibt es kein Wettrennen: der Aufruf gewinnt, solange das
     * Fenster vorwaerts geht.
     */
    public function consumeTotpStep(User $user, SecondFactorSettings $accepted): bool
    {
        $step = $accepted->usedStep();

        if (null === $step || $step <= ($user->secondFactor()->usedStep() ?? \PHP_INT_MIN)) {
            return false;
        }

        $user->useSecondFactor($accepted);

        return true;
    }

    /** Bestueckt die Attrappe mit einer echten Rechteberechnung. */
    public function knowing(EffectivePermissions $effective): self
    {
        $this->effective = $effective;

        return $this;
    }

    /** Und mit dem Bestand, den eine gescheiterte Aenderung zuruecknimmt. */
    public function rollingBack(InMemoryPermissionAssignments $assignments): self
    {
        $this->assignments = $assignments;

        return $this;
    }

    public function countActiveManagers(string $permissionKey, ?string $exceptUserId = null): int
    {
        return \count(array_filter(
            $this->users,
            fn (User $user): bool => $user->id() !== $exceptUserId
                && $user->status()->isActive()
                && $this->grants($user, $permissionKey),
        ));
    }

    /**
     * Im Speicher gibt es kein Wettrennen — von der Sperre bleibt der
     * Ruecklauf.
     *
     * Den braucht es trotzdem: die Matrix schreibt erst und fragt dann, ob
     * der neue Stand tragbar ist. Ohne Ruecklauf zeigte ein Test hier einen
     * Stand, den es im Betrieb nicht gaebe.
     */
    public function guardingManagers(string $permissionKey, callable $change): mixed
    {
        $snapshot = $this->assignments?->snapshot();

        try {
            return $change();
        } catch (Throwable $failure) {
            if (null !== $this->assignments && null !== $snapshot) {
                $this->assignments->restore($snapshot);
            }

            throw $failure;
        }
    }

    public function forParty(string $partyId): ?User
    {
        foreach ($this->users as $user) {
            if ($user->partyId() === $partyId) {
                return $user;
            }
        }

        return null;
    }

    private function grants(User $user, string $permissionKey): bool
    {
        return null === $this->effective
            ? $user->isAdministrator()
            : $this->effective->allows($user, $permissionKey);
    }
}
