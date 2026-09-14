<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Twig;

use App\Module\Billing\Domain\BillingPermissions;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Einmal beim Anmelden zaehlen.
 *
 * Der Zeitpunkt, an dem die Zahl entsteht, ohne dass jemand darauf wartet:
 * wer sich anmeldet, sieht die Uebersicht ohnehin erst nach dem Laden der
 * ersten Seite. Danach bleibt sie stehen, bis jemand die Abrechnungen
 * oeffnet oder auf „Neu prüfen" drueckt.
 *
 * Gezaehlt wird nur fuer Konten, die Abrechnungen ueberhaupt sehen duerfen —
 * fuer alle anderen waere es Arbeit fuer eine Zahl, die nirgends erscheint.
 */
#[AsEventListener(event: LoginSuccessEvent::class)]
final readonly class CountOnLogin
{
    public function __construct(
        private CorrectionBadge $badge,
        private AuthorizationCheckerInterface $mayView,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        if ($this->mayView->isGranted(BillingPermissions::VIEW)) {
            $this->badge->refresh();
        }
    }
}
