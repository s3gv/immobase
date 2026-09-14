<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Manifest\Manifest;
use App\Module\Plugin\Domain\PluginState;

/**
 * Ein Plugin, so wie die Einstellungsseite es zeigt.
 *
 * Gefunden, aktiviert oder ausgesetzt — und im schlimmsten Fall fehlerhaft.
 * Der Fehler steht im Klartext da und nicht als Abwesenheit: ein Plugin, das
 * einfach nicht auftaucht, laesst den Betreiber raten, ob er es falsch
 * abgelegt hat.
 */
final readonly class FoundPlugin
{
    public function __construct(
        public string $name,
        public ?Manifest $manifest,
        public string $problem,
        public ?PluginState $state,
        public string $installedVersion,
        public bool $wantsMore,
        public bool $onDisk,
    ) {
    }

    public function isInstalled(): bool
    {
        return null !== $this->state;
    }

    public function isBroken(): bool
    {
        return '' !== $this->problem;
    }

    public function label(string $locale): string
    {
        return $this->manifest?->label($locale) ?? $this->name;
    }

    /**
     * Die Fassung, die gilt.
     *
     * Bei einer Installation die bestaetigte und nicht die auf der Platte:
     * sonst staende oben schon die neue Nummer, waehrend darunter steht, dass
     * sie noch nicht gilt.
     */
    public function version(): string
    {
        return $this->isInstalled() ? $this->installedVersion : ($this->manifest->version ?? '');
    }

    /** Steht eine neue Fassung bereit, die noch niemand bestaetigt hat? */
    public function hasNewVersion(): bool
    {
        return $this->isInstalled() && null !== $this->manifest
            && $this->manifest->version !== $this->installedVersion;
    }
}
