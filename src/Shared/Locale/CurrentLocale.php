<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Locale;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Die Sprache der laufenden Anfrage.
 *
 * Sie kommt aus der Anfrage und nicht aus einem Parameter am Aufrufort: sonst
 * muesste jede Vorlage sie durchreichen, und irgendeine vergisst es. Ausserhalb
 * einer Anfrage — im Konsolenbefehl — gilt die voreingestellte.
 */
final readonly class CurrentLocale
{
    public function __construct(
        private RequestStack $requests,
        #[Autowire('%kernel.default_locale%')]
        private string $fallback,
    ) {
    }

    public function code(): string
    {
        return $this->requests->getCurrentRequest()?->getLocale() ?? $this->fallback;
    }
}
