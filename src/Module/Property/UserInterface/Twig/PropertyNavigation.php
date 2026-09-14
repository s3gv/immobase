<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\UserInterface\Twig;

use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Welches Objekt gerade offen ist — fuer die Seitenleiste.
 *
 * Sie soll waehrend der Arbeit an einem Objekt dessen Einheiten zeigen. Das
 * durch jeden Controller zu reichen hiesse, es an fuenf Stellen zu tun und an
 * der sechsten zu vergessen; die Leiste haengt an der Huelle und nicht an der
 * Seite.
 *
 * Abgeleitet aus der Adresse und nicht gemerkt: ein Baum, der behaelt, was man
 * je geoeffnet hat, ist nach einer Woche keine Navigation mehr.
 */
final class PropertyNavigation extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly PropertyRepository $properties,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('open_property', $this->current(...))];
    }

    public function current(): ?Property
    {
        $request = $this->requests->getCurrentRequest();
        $route = $request?->attributes->get('_route');

        if (null === $request || !\is_string($route) || !self::belongsToProperties($route)) {
            return null;
        }

        $number = $request->attributes->get('number');

        return is_numeric($number) ? $this->properties->byNumber((int) $number) : null;
    }

    private static function belongsToProperties(string $route): bool
    {
        return str_starts_with($route, 'app_property') || str_starts_with($route, 'app_unit');
    }
}
