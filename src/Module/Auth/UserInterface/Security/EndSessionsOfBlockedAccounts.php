<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Security;

use App\Module\Auth\Domain\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ein deaktiviertes Konto ist sofort draussen — nicht erst bei der naechsten
 * Anmeldung.
 *
 * Symfony laedt das Konto bei jeder Anfrage frisch und vergleicht Passwort,
 * Rollen und Kennung, aber nicht den Status. Ohne diesen Riegel arbeitete ein
 * ausgeschiedener Mitarbeiter oder ein Mieter, dem das Portal entzogen wurde,
 * in seiner offenen Sitzung einfach weiter.
 *
 * Direkt nach der Firewall (Prioritaet 8), vor jedem Controller.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final readonly class EndSessionsOfBlockedAccounts
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        // Ohne Sitzung kein Konto. Und ohne diese Abfrage fasste schon die
        // Frage nach dem Token die Sitzung an — dann waere jede Antwort
        // privat, auch eine, die ausdruecklich zwischengespeichert werden soll.
        if (!$event->isMainRequest() || !$event->getRequest()->hasPreviousSession()) {
            return;
        }

        $user = $this->tokens->getToken()?->getUser();

        if (!$user instanceof User || $user->canSignIn()) {
            return;
        }

        $this->tokens->setToken(null);
        $request = $event->getRequest();

        if ($request->hasSession()) {
            $request->getSession()->invalidate();
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_login')));
    }
}
