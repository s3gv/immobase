<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Contract\SignedInParty;
use App\Module\Auth\Domain\User;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class PartyOfTheSignedIn implements SignedInParty
{
    public function __construct(private Security $security)
    {
    }

    public function partyId(): ?string
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->partyId() : null;
    }
}
