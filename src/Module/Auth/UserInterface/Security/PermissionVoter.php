<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Security;

use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Application\Rbac\PermissionCatalogue;
use App\Module\Auth\Domain\User;
use App\Shared\Security\Permission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\CacheableVoterInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Beantwortet `is_granted('parties.view')`.
 *
 * Zustaendig nur fuer Attribute, die im Katalog stehen. Symfonys eigene
 * Pruefungen — ROLE_USER, IS_AUTHENTICATED — laufen unberuehrt weiter, und ein
 * vertippter Schluessel faellt nicht diesem Pruefer zu: er hat dann gar keinen,
 * und ohne Pruefer verweigert Symfony. Dass es dazu nicht kommt, sichern die
 * Katalogtests.
 *
 * @extends Voter<string, mixed>
 */
final class PermissionVoter extends Voter implements CacheableVoterInterface
{
    public function __construct(
        private readonly PermissionCatalogue $catalogue,
        private readonly EffectivePermissions $effective,
    ) {
    }

    public function supportsAttribute(string $attribute): bool
    {
        return Permission::looksLikeKey($attribute) && $this->catalogue->has($attribute);
    }

    /** Rechte gelten fuer den ganzen Bereich, nicht fuer einzelne Datensaetze. */
    public function supportsType(string $subjectType): bool
    {
        return true;
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $this->effective->allows($user, $attribute);
    }
}
