<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Flow;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Haelt den Zwischenstand eines Ablaufs in der Sitzung.
 *
 * Bewusst die Sitzung und keine Datenbanktabelle: ein abgebrochener Ablauf
 * soll keine Zeile hinterlassen, die spaeter jemand aufraeumen muss. Braucht
 * ein Fachmodul einen Entwurf, der einen Browser-Neustart ueberlebt,
 * persistiert es ihn selbst.
 */
final readonly class FlowSessionStore
{
    public function __construct(private RequestStack $requests)
    {
    }

    /** Der Zwischenstand, falls einer da ist — sonst nichts. */
    public function find(FlowDefinition $definition): ?FlowState
    {
        /** @var array{current: string, values: array<string, array<string, mixed>>, visited: list<string>}|null $stored */
        $stored = $this->requests->getSession()->get($this->key($definition));

        return null === $stored ? null : FlowState::fromArray($stored);
    }

    public function save(FlowDefinition $definition, FlowState $state): void
    {
        $this->requests->getSession()->set($this->key($definition), $state->toArray());
    }

    public function clear(FlowDefinition $definition): void
    {
        $this->requests->getSession()->remove($this->key($definition));
    }

    private function key(FlowDefinition $definition): string
    {
        return 'flow.'.$definition->id;
    }
}
