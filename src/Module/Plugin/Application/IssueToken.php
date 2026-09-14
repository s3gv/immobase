<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Domain\PluginToken;
use RuntimeException;

/**
 * Ein frisches Token fuer einen startenden Plugin-Prozess.
 *
 * **Bei jedem Start ein neues.** Der Core startet das Plugin selbst und gibt
 * ihm das Token ueber die Umgebung mit; niemand schreibt es ab, niemand
 * traegt es irgendwo ein. Gespeichert ist nur der Abdruck — und das alte gilt
 * ab diesem Moment nicht mehr, auch nicht fuer einen Prozess, der noch von
 * gestern herumliegt.
 */
final readonly class IssueToken
{
    public function __construct(private PluginRepository $plugins)
    {
    }

    public function __invoke(string $name): string
    {
        $plugin = $this->plugins->byName($name)
            ?? throw new RuntimeException(\sprintf('„%s" ist nicht aktiviert.', $name));

        $token = PluginToken::fresh();
        $plugin->reseal(PluginToken::seal($token));
        $this->plugins->save($plugin);

        return $token;
    }
}
