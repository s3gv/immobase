<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Adressen, die das Haus verlassen — in einer E-Mail, einem Link zum Setzen
 * des Passworts.
 *
 * Sie kommen immer aus `DEFAULT_URI` und nie aus der Anfrage. Waehrend einer
 * Anfrage naehme der Router sonst deren Host, und den schreibt der Absender:
 * mit `Host: evil.example` bekaeme das Opfer eine echte Mail von hier, deren
 * Link den Token an einen fremden Server traegt.
 */
final readonly class PublicUrls
{
    public function __construct(
        private UrlGeneratorInterface $urls,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $address,
    ) {
    }

    /** @param array<string, mixed> $parameters */
    public function absolute(string $route, array $parameters = []): string
    {
        $context = $this->urls->getContext();
        $this->urls->setContext(RequestContext::fromUri($this->address));

        try {
            return $this->urls->generate($route, $parameters, UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->urls->setContext($context);
        }
    }
}
