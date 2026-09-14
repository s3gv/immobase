<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Security;

use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Application\RecordSignIn;
use App\Module\Auth\Domain\SecondFactor;
use App\Module\Auth\Domain\User;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Der zweite Schritt der Anmeldung — als eigener Authenticator.
 *
 * Nicht als Controller mit Security::login(): die Firewall soll die Anmeldung
 * selbst vornehmen, mit allem, was dazugehoert — Sitzungswechsel gegen
 * Session Fixation, Ereignisse, Token im Speicher. Wer das von Hand nachbaut,
 * vergisst einen Teil davon, und zwar den, der still fehlt.
 *
 * Anders als beim Passwort gibt es hier keine Zugangsdaten zu pruefen: das
 * Passwort war schon richtig, sonst laege kein Vermerk in der Sitzung. Der
 * Code entscheidet, und geprueft wird er hier.
 */
final class SecondFactorAuthenticator extends AbstractAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly PendingSecondFactor $pending,
        private readonly ManageSecondFactor $factors,
        private readonly RecordSignIn $signIn,
        private readonly UrlGeneratorInterface $urls,
        private readonly ClockInterface $clock,
        #[Target('second_factor')]
        private readonly RateLimiterFactoryInterface $secondFactorLimiter,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST')
            && 'app_second_factor' === $request->attributes->get('_route')
            && '' === $request->request->getString('resend');
    }

    public function authenticate(Request $request): Passport
    {
        $user = $this->pending->waiting($this->clock->now()->getTimestamp());

        if (null === $user) {
            throw new CustomUserMessageAuthenticationException('user.factor.error.expired');
        }

        // Ohne Bremse waere ein sechsstelliger Code in Minuten durchprobiert.
        //
        // Sie haengt am Konto und nicht an der Sitzung: wer das Passwort
        // kennt, kann sich beliebig viele frische Sitzungen holen und haette
        // je Sitzung wieder fuenf Versuche. Am Konto gilt die Grenze fuer
        // alle zusammen — egal aus wie vielen Browsern.
        if (!$this->secondFactorLimiter->create('user-'.$user->id())->consume()->isAccepted()) {
            throw new CustomUserMessageAuthenticationException('user.factor.error.too_many');
        }

        if (!$this->accepts($user, $request->request->getString('code'), '' !== $request->request->getString('recovery'))) {
            throw new CustomUserMessageAuthenticationException('user.factor.error.code');
        }

        return new SelfValidatingPassport(
            new UserBadge($user->getUserIdentifier()),
            [new CsrfTokenBadge('second_factor', $request->request->getString('_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();

        if ($user instanceof User) {
            ($this->signIn)($user);
            $this->warnIfRecoveryCodesRunOut($request, $user);
        }

        $this->pending->forget();

        // Dieselbe Unterscheidung wie beim ersten Schritt: ein Portalkonto hat
        // ROLE_USER nicht und liefe auf dem Dashboard in ein 403.
        if ($user instanceof User && $user->isPortalAccount()) {
            return new RedirectResponse($this->urls->generate('app_portal_home'));
        }

        return new RedirectResponse(
            $this->getTargetPath($request->getSession(), $firewallName) ?? $this->urls->generate('app_dashboard'),
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->urls->generate('app_second_factor'));
    }

    private function accepts(User $user, string $code, bool $recovery): bool
    {
        if ($recovery) {
            return $this->factors->acceptsRecoveryCode($user, $code);
        }

        return SecondFactor::App === $user->secondFactor()->kind()
            ? $this->factors->acceptsAppCode($user, $code)
            : $this->factors->acceptsEmailCode($user, $code);
    }

    /**
     * Bei zwei oder weniger wird es knapp — der Hinweis kommt nach der
     * Anmeldung und nicht davor. Davor waere er eine Huerde mehr in einem
     * Moment, in dem jemand hereinwill.
     */
    private function warnIfRecoveryCodesRunOut(Request $request, User $user): void
    {
        if ($this->factors->remainingRecoveryCodes($user) > 2) {
            return;
        }

        $session = $request->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'user.recovery.running_out');
        }
    }
}
