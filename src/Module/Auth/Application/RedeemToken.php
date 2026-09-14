<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Application;

use App\Module\Auth\Domain\SignInToken;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\TokenRepository;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use Symfony\Component\Clock\ClockInterface;

/**
 * Loest einen Schluessel aus einer E-Mail ein.
 *
 * Deaktivierte Konten kommen auch mit gueltigem Schluessel nicht herein: wer
 * abgeschaltet wurde, soll sich nicht ueber einen alten Link zurueckholen.
 */
final readonly class RedeemToken
{
    public function __construct(
        private TokenRepository $tokens,
        private UserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function owner(string $plain, TokenPurpose $purpose): ?User
    {
        $token = $this->find($plain, $purpose);

        if (null === $token) {
            return null;
        }

        $user = $this->users->byId($token->userId());

        return null !== $user && !$user->status()->isBlocked() ? $user : null;
    }

    public function find(string $plain, TokenPurpose $purpose): ?SignInToken
    {
        return $this->tokens->findUsable($plain, $purpose, $this->clock->now());
    }

    /**
     * @return bool ob dieser Aufruf den Schluessel verbraucht hat
     *
     * Der Rueckgabewert ist nicht schmueckendes Beiwerk: bei zwei
     * gleichzeitigen Anfragen mit demselben Link gewinnt genau eine, und die
     * andere darf nicht so tun, als sei sie es gewesen
     */
    public function consume(string $plain, TokenPurpose $purpose): bool
    {
        $token = $this->find($plain, $purpose);

        return null !== $token && $this->tokens->consume($token, $this->clock->now());
    }
}
