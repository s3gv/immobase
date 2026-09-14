<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Contract\AuthenticatedUser;
use App\Module\Auth\Contract\UserDirectory;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserFilter;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use App\Shared\Ui\Page;
use InvalidArgumentException;

final readonly class LookupUsers implements UserDirectory
{
    public function __construct(private UserRepository $users)
    {
    }

    public function byEmail(string $email): ?AuthenticatedUser
    {
        try {
            $address = Email::fromString($email);
        } catch (InvalidArgumentException) {
            // Eine unsinnige Adresse ist kein Fehler, sondern schlicht kein
            // Treffer. Das aufrufende Modul soll die Wertobjekte dieses Moduls
            // nicht kennen muessen — also auch nicht deren Ausnahmen.
            return null;
        }

        $user = $this->users->findByEmail($address);

        if (null === $user) {
            return null;
        }

        return $this->toContract($user);
    }

    /**
     * Ueber alle Seiten und nicht nur die erste: eine Verwaltung mit
     * einundfuenfzig Konten haette sonst eines, das in der Auswahl fehlt.
     * Portalkonten blendet die Abfrage grundsaetzlich aus.
     */
    public function colleagues(): array
    {
        $filter = UserFilter::none();
        $first = Page::of(1, $this->users->countMatching($filter));
        $found = [];

        for ($number = 1; $number <= $first->pages; ++$number) {
            foreach ($this->users->matching($filter, Page::of($number, $first->total)) as $user) {
                $found[$user->id()] = $user->displayName();
            }
        }

        return $found;
    }

    private function toContract(User $user): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: $user->id(),
            number: $user->number(),
            email: $user->email()->toString(),
            displayName: $user->displayName(),
            jobTitle: $user->name()->jobTitle(),
            isActive: $user->status()->isActive(),
            isAdministrator: $user->isAdministrator(),
        );
    }
}
