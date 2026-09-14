<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Ob die Liste, die gerade gezeigt wird, gefiltert ist.
 *
 * Fuer den Satz, der unter einer leeren Liste steht. „Noch keinen
 * Wirtschaftsplan angelegt" ist falsch, wenn zehn davon da sind und nur
 * keiner zur Suche passt — der Leser sucht dann den Fehler bei den Daten
 * statt beim Filter.
 *
 * Gefragt wird die Adresse und nicht der Filter selbst: es gibt zehn
 * Filterklassen, und jeder von ihnen dieselbe Frage anzuerziehen hiesse, sie
 * zehnmal zu beantworten und bei der elften zu vergessen. Was in der Adresse
 * steht, hat jemand eingegeben — mit Ausnahme der Seitenzahl, die nur sagt,
 * welcher Ausschnitt gezeigt wird.
 */
final class ListFilter extends AbstractExtension
{
    private const NOT_A_FILTER = ['page'];

    public function __construct(private readonly RequestStack $requests)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('list_is_filtered', $this->isFiltered(...))];
    }

    public function isFiltered(): bool
    {
        $query = $this->requests->getCurrentRequest()?->query->all() ?? [];

        foreach ($query as $name => $value) {
            if (!\in_array($name, self::NOT_A_FILTER, true) && self::isGiven($value)) {
                return true;
            }
        }

        return false;
    }

    /** Ein leeres Feld ist kein Filter — „alle Objekte" schickt es mit. */
    private static function isGiven(mixed $value): bool
    {
        if (\is_array($value)) {
            return [] !== $value;
        }

        return \is_scalar($value) && '' !== trim((string) $value);
    }
}
