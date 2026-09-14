<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Security;

use App\Module\Auth\Application\RecordSignIn;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use App\Shared\Contact\Email;
use InvalidArgumentException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Die Anmeldung mit Passwort — und, wenn eingerichtet, einem zweiten Schritt.
 *
 * Symfonys form_login kann den Zwischenschritt nicht, weil es nach dem
 * Passwort sofort anmeldet. Hier wird bei eingerichtetem zweitem Faktor
 * stattdessen nur gemerkt, wer wartet, und auf die Bestaetigungsseite
 * geleitet. Angemeldet wird erst dort.
 */
final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly PendingSecondFactor $pending,
        private readonly RecordSignIn $signIn,
        private readonly TokenStorageInterface $tokens,
        private readonly ClockInterface $clock,
        private readonly UserRepository $users,
        private readonly PasswordHasherFactoryInterface $hashers,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && 'app_login' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $email = $request->request->getString('_username');
        $password = $request->request->getString('_password');
        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            new UserBadge($email, fn (string $identifier): User => $this->load($identifier, $password)),
            new PasswordCredentials($password),
            [new CsrfTokenBadge('authenticate', $request->request->getString('_csrf_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();

        if ($user instanceof User && $user->secondFactor()->kind()->isSet()) {
            return $this->askForSecondFactor($request, $user);
        }

        if ($user instanceof User) {
            ($this->signIn)($user);
        }

        return new RedirectResponse($this->landingPageFor($request, $token, $firewallName));
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        $request->getSession()->set(SecurityRequestAttributes::AUTHENTICATION_ERROR, $exception);

        return new RedirectResponse($this->getLoginUrl($request));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('app_login');
    }

    /**
     * Das Konto zur Adresse — und gleich lange Arbeit, wenn es keines gibt.
     *
     * Symfony prueft das Passwort nur fuer ein Konto, das existiert und sich
     * anmelden darf. Ohne den Hash hier antwortete eine unbekannte oder
     * deaktivierte Adresse um die Zeit eines Hashes schneller, und die
     * Stoppuhr verriete, was die Meldung verschweigt.
     */
    private function load(string $identifier, string $password): User
    {
        try {
            $user = $this->users->findByEmail(Email::fromString($identifier));
        } catch (InvalidArgumentException) {
            $user = null;
        }

        if (null === $user || !$user->canSignIn()) {
            $this->hashers->getPasswordHasher(User::class)->hash($password);
        }

        return $user ?? throw new UserNotFoundException();
    }

    /**
     * Wohin nach der Anmeldung — und das ist nicht fuer alle dasselbe.
     *
     * Ein Portalkonto hat `ROLE_USER` nicht und liefe auf dem Dashboard in ein
     * 403. Auch eine gemerkte Zieladresse hilft ihm nicht: sie zeigt auf die
     * Seite, an der es vorhin abgewiesen wurde. Fuer es gilt deshalb das
     * Portal, und zwar ohne Wenn und Aber.
     */
    private function landingPageFor(Request $request, TokenInterface $token, string $firewallName): string
    {
        $user = $token->getUser();

        if ($user instanceof User && $user->isPortalAccount()) {
            return $this->urls->generate('app_portal_home');
        }

        return $this->getTargetPath($request->getSession(), $firewallName)
            ?? $this->urls->generate('app_dashboard');
    }

    /**
     * Das Passwort stimmt — angemeldet wird trotzdem nicht.
     *
     * An dieser Stelle hat Symfony den Token bereits gesetzt; eine
     * Weiterleitung allein wuerde niemanden wieder abmelden. Er wird deshalb
     * ausdruecklich verworfen — danach schreibt der Kontext-Listener auch
     * nichts in die Sitzung. Was bleibt, ist ein Vermerk, wer wartet.
     */
    private function askForSecondFactor(Request $request, User $user): Response
    {
        $this->tokens->setToken(null);
        $this->pending->remember($user, $this->clock->now()->getTimestamp());

        return new RedirectResponse($this->urls->generate('app_second_factor'));
    }
}
