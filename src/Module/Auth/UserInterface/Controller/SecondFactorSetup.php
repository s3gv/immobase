<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Domain\SecondFactor;
use App\Module\Auth\Domain\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Der Schritt, in dem jemand seinen zweiten Faktor einrichtet.
 *
 * Zwischen "App gewaehlt" und "Code bestaetigt" liegt ein Geheimnis, das noch
 * nirgends gespeichert werden darf: erst der bestaetigte Code belegt, dass es
 * in der App angekommen ist. Solange liegt es in der Sitzung.
 *
 * Wird es nie bestaetigt, verfaellt es mit der Sitzung — am Konto bleibt
 * nichts zurueck, und niemand ist ausgesperrt.
 */
final readonly class SecondFactorSetup
{
    private const string PENDING = 'auth.factor_setup';

    public function __construct(
        private ManageSecondFactor $factors,
        private EmailCodes $codes,
        private RequestStack $requests,
    ) {
    }

    /**
     * Was der Schritt gerade anzeigen soll: die Wahl und, bei der App, das
     * Geheimnis zum Abtippen. Der QR-Code kommt aus einer eigenen Adresse.
     *
     * @return array{choice: string, secret: ?string}
     */
    public function offer(): array
    {
        $pending = $this->pending();

        return [
            'choice' => \is_string($pending['choice'] ?? null) ? $pending['choice'] : '',
            'secret' => \is_string($pending['secret'] ?? null) ? $pending['secret'] : null,
        ];
    }

    /**
     * @return string|null Hinweis oder Fehler; null heisst: Schritt erledigt
     */
    public function handle(User $user, Request $request): ?string
    {
        // Ueberspringen ist ausdruecklich erlaubt und beendet den Schritt.
        // Ein zweiter Faktor, den man nicht ueberspringen kann, sperrt am Ende
        // die aus, die kein zweites Geraet haben.
        if ('' !== $request->request->getString('skip')) {
            $this->requests->getSession()->remove(self::PENDING);

            return null;
        }

        // Ein eingerichteter Faktor wird nicht ueberschrieben, sondern erst
        // abgeschaltet — und das verlangt einen Code des alten. Sonst
        // genuegte eine fremde Sitzung oder ein Link zum Zuruecksetzen, um
        // die eigene App an ein fremdes Konto zu haengen.
        if ($user->secondFactor()->kind()->isSet()) {
            $this->requests->getSession()->remove(self::PENDING);

            return 'user.factor.error.already_set';
        }

        return match ($request->request->getString('choose')) {
            'app' => $this->beginApp($user),
            'email' => $this->beginEmail($user),
            'restart' => $this->forget(),
            default => $this->confirm($user, $request->request->getString('code')),
        };
    }

    public function forget(): string
    {
        $this->requests->getSession()->remove(self::PENDING);

        return 'user.factor.restarted';
    }

    /**
     * Nach dem Bestaetigen: die einmalig sichtbaren Codes.
     *
     * @return list<string>
     */
    public function takeRecoveryCodes(): array
    {
        $session = $this->requests->getSession();
        /** @var list<string> $codes */
        $codes = $session->get('auth.recovery_codes', []);
        $session->remove('auth.recovery_codes');

        return $codes;
    }

    private function beginApp(User $user): string
    {
        $this->remember([
            'choice' => SecondFactor::App->value,
            'secret' => $this->factors->newAppSecret(),
            'account' => $user->email()->toString(),
        ]);

        return 'user.factor.scan';
    }

    private function beginEmail(User $user): string
    {
        $message = $this->codes->send($user);

        if ('user.factor.sent' === $message) {
            $this->remember(['choice' => SecondFactor::Email->value, 'secret' => null]);
        }

        return $message;
    }

    private function confirm(User $user, string $code): ?string
    {
        $pending = $this->pending();
        $choice = \is_string($pending['choice'] ?? null) ? $pending['choice'] : '';
        $secret = \is_string($pending['secret'] ?? null) ? $pending['secret'] : '';

        $codes = match ($choice) {
            SecondFactor::App->value => $this->factors->confirmApp($user, $secret, $code),
            SecondFactor::Email->value => $this->factors->confirmEmail($user, $code),
            default => null,
        };

        if (null === $codes) {
            return '' === $choice ? 'user.factor.error.choose' : 'user.factor.error.code';
        }

        $this->requests->getSession()->remove(self::PENDING);
        $this->requests->getSession()->set('auth.recovery_codes', $codes);

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function pending(): array
    {
        /** @var array<string, mixed> $pending */
        $pending = $this->requests->getSession()->get(self::PENDING, []);

        return $pending;
    }

    /**
     * @param array<string, mixed> $pending
     */
    private function remember(array $pending): void
    {
        $this->requests->getSession()->set(self::PENDING, $pending);
    }
}
