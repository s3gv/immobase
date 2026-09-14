<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dashboard\UserInterface\Twig;

use App\Module\Dashboard\Application\MyReminders;
use App\Module\Dashboard\Domain\Reminder;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `{{ my_reminders() }}` fuer die Klappe in der Kopfzeile.
 *
 * **Die Vorlage fragt, nicht der Controller.** Die Kopfzeile steht auf jeder
 * Seite; jeden Controller der Anwendung um diese Liste zu erweitern hiesse,
 * sie an sechzig Stellen zu holen. Dieselbe Entscheidung wie bei
 * `dunning_pressure()` und `pending_corrections()`.
 *
 * Ohne Anmeldung nichts. Die Anmeldeseite traegt zwar keine Kopfzeile — aber
 * eine Ausnahme dort waere ein Fehler auf der einzigen Seite, die immer
 * erreichbar sein muss.
 */
final class ReminderBadge extends AbstractExtension
{
    public function __construct(
        private readonly MyReminders $reminders,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('my_reminders', $this->drawer(...))];
    }

    /**
     * @return array{next: list<Reminder>, past: list<Reminder>}
     */
    public function drawer(): array
    {
        return null === $this->security->getUser()
            ? ['next' => [], 'past' => []]
            : $this->reminders->inTheDrawer();
    }
}
