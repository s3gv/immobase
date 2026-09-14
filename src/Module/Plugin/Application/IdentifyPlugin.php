<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Contract\PluginIdentity;
use App\Module\Plugin\Domain\Manifest\ManifestFault;
use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginToken;
use SensitiveParameter;

/**
 * Aus einem Token wird ein Plugin — oder nichts.
 *
 * **Ausgesetzte zaehlen nicht.** Aussetzen soll wirklich abschalten; ein
 * Token, das danach weiterlaeuft, machte den Schalter zur Zierde.
 *
 * Gesucht wird ueber den Abdruck und nicht ueber das Token: gespeichert ist
 * nur er, und das ist der Grund, warum ein Datenbankabzug keine gueltigen
 * Ausweise enthaelt.
 */
final readonly class IdentifyPlugin
{
    public function __construct(
        private PluginRepository $plugins,
        private ReadsManifests $manifests,
    ) {
    }

    public function __invoke(#[SensitiveParameter] string $token): ?PluginIdentity
    {
        if (!PluginToken::looksLikeOne($token)) {
            return null;
        }

        $plugin = $this->plugins->byTokenHash(PluginToken::seal($token));

        if (null === $plugin || !$plugin->isActive()) {
            return null;
        }

        try {
            $manifest = $this->manifests->stored($plugin->name(), $plugin->manifest());
        } catch (ManifestFault) {
            // Das bestaetigte Manifest laesst sich nicht mehr lesen. Dann ist
            // auch nicht mehr bekannt, was bestaetigt wurde — und ohne das
            // gibt es nichts herauszugeben.
            return null;
        }

        return new PluginIdentity($plugin->name(), $manifest->reads);
    }
}
