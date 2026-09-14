<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Locale;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Uebernimmt die in der Sitzung gemerkte Sprache.
 *
 * Symfony tut das nicht von selbst. Prioritaet 15 liegt nach dem
 * Sitzungs-Listener und vor der Routenaufloesung.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 15)]
final readonly class LocaleListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->hasPreviousSession()) {
            return;
        }

        $locale = $request->getSession()->get('_locale');

        if (\is_string($locale) && '' !== $locale) {
            $request->setLocale($locale);
        }
    }
}
