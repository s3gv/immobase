<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Domain\SecondFactor;
use App\Module\Auth\Domain\User;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Den zweiten Faktor abschalten — im Verwalterbereich wie im Portal.
 *
 * **Nur mit gueltigem Code.** Sonst genuegte ein unbeaufsichtigter Bildschirm,
 * um die Huerde loszuwerden, die der Faktor sein soll. Beim Faktor E-Mail
 * kommt der Code auf Wunsch von hier; vorher gab es keinen Weg zu ihm.
 *
 * **Mit derselben Bremse wie bei der Anmeldung**, am Konto: ein sechsstelliger
 * Code liesse sich sonst hier statt dort durchprobieren.
 */
final readonly class TurnOffSecondFactor
{
    public function __construct(
        private ManageSecondFactor $factors,
        private EmailCodes $codes,
        #[Target('second_factor')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    /** @return array{bool, string} ob abgeschaltet wurde, und der Hinweis dazu */
    public function __invoke(User $user, Request $request): array
    {
        $kind = $user->secondFactor()->kind();

        if (SecondFactor::Email === $kind && '' !== $request->request->getString('send')) {
            return [false, $this->codes->send($user)];
        }

        if (!$this->limiter->create('user-'.$user->id())->consume()->isAccepted()) {
            return [false, 'user.factor.error.too_many'];
        }

        $code = $request->request->getString('code');
        $accepted = SecondFactor::App === $kind
            ? $this->factors->acceptsAppCode($user, $code)
            : $this->factors->acceptsEmailCode($user, $code);

        if (!$accepted) {
            return [false, 'user.factor.error.code'];
        }

        $this->factors->turnOff($user);

        return [true, 'user.factor.turned_off'];
    }
}
