<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Domain\User;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Ein Code per E-Mail — mit Bremse je Konto.
 *
 * Jeder neue Code entwertet den vorigen. Ohne Grenze koennte, wer das Passwort
 * kennt, das Postfach des Opfers fluten und ihm jeden Code unter der Hand
 * wegnehmen, bevor es ihn eintippt.
 */
final readonly class EmailCodes
{
    public function __construct(
        private ManageSecondFactor $factors,
        #[Target('second_factor_mail')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /** @return string der Hinweis fuer die Seite */
    public function send(User $user): string
    {
        if (!$this->limiter->create('user-'.$user->id())->consume()->isAccepted()) {
            return 'user.factor.error.mail_limit';
        }

        $this->factors->sendEmailCode($user);

        return 'user.factor.sent';
    }
}
