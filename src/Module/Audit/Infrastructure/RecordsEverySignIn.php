<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure;

use App\Module\Audit\Application\WhoActed;
use App\Module\Audit\Domain\ActorKind;
use App\Module\Audit\Domain\AuditEntry;
use App\Module\Audit\Domain\AuditRepository;
use App\Shared\Audit\AuditAction;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Wer sich angemeldet hat — aus der Verwaltung wie aus dem Portal.
 *
 * Beide gehen durch dieselben Ereignisse der Anmeldung, und beide gehoeren
 * ins Protokoll: eine Anmeldung im Portal ist ein Mieter an seinen eigenen
 * Daten, eine in der Verwaltung jemand an allen. Welche von beiden es war,
 * steht an der Zeile.
 *
 * **Die gescheiterte Anmeldung nennt nur, was getippt wurde.** Ob es zu
 * dieser Adresse ein Konto gibt, sagt das Protokoll nicht — das waere genau
 * die Auskunft, die die Anmeldung selbst verweigert.
 */
final readonly class RecordsEverySignIn
{
    public function __construct(
        private AuditRepository $entries,
        private WhoActed $who,
        private ClockInterface $clock,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        $this->write(
            AuditAction::SignedIn,
            $user->getUserIdentifier(),
            \in_array('ROLE_PORTAL', $user->getRoles(), true) ? ActorKind::Portal : ActorKind::Staff,
        );
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onFailure(LoginFailureEvent $event): void
    {
        $typed = $event->getRequest()->request->getString('_username');

        $this->write(AuditAction::SignInFailed, '' === $typed ? '—' : $typed, ActorKind::Unknown);
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();

        if (null === $user) {
            return;
        }

        $this->write(
            AuditAction::SignedOut,
            $user->getUserIdentifier(),
            \in_array('ROLE_PORTAL', $user->getRoles(), true) ? ActorKind::Portal : ActorKind::Staff,
        );
    }

    /**
     * Der Name kommt aus der Anmeldung selbst und nicht aus {@see WhoActed}.
     *
     * Beim Anmelden gibt es die Sitzung noch nicht, beim Abmelden nicht mehr;
     * die Adresse ist das Einzige, was in beiden Momenten feststeht. Wo der
     * volle Name bekannt ist, steht er trotzdem dabei.
     */
    private function write(AuditAction $action, string $identifier, ActorKind $kind): void
    {
        // Bei der gescheiterten Anmeldung bleibt stehen, was getippt wurde:
        // einen Namen nachzuschlagen hiesse zu verraten, ob es das Konto
        // gibt — und genau das sagt die Anmeldung selbst nicht.
        $name = ActorKind::Unknown === $kind ? $identifier : $this->who->nameFor($identifier);

        $this->entries->append([new AuditEntry($this->clock->now(), $action, $name, $kind)]);
    }
}
