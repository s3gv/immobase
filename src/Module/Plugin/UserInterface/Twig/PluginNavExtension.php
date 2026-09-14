<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\UserInterface\Twig;

use App\Module\Plugin\Application\ActiveManifests;
use App\Shared\Locale\CurrentLocale;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Die Menuepunkte der aktiven Plugins.
 *
 * Aus der bestaetigten Momentaufnahme, nicht von der Platte: die Navigation
 * wird bei jedem Seitenaufbau gezeichnet.
 *
 * Jeder Eintrag traegt sein Recht mit; ob er erscheint, entscheidet die
 * Vorlage. **Ohne aktive Plugins ist die Liste leer** — dann steht in der
 * Seitenleiste auch keine Zwischenueberschrift, und der Core sieht aus wie
 * einer ohne Plugins, weil er genau das ist.
 */
final class PluginNavExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActiveManifests $manifests,
        private readonly UrlGeneratorInterface $urls,
        private readonly CurrentLocale $locale,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('plugin_nav', $this->entries(...))];
    }

    /**
     * @return list<array{url: string, label: string, icon: string, permission: string, plugin: string}>
     */
    public function entries(): array
    {
        $found = [];

        foreach ($this->manifests->all() as [$name, $manifest]) {
            foreach ($manifest->nav as $entry) {
                $found[] = [
                    'url' => $this->urls->generate('app_plugin_page', [
                        'plugin' => $name,
                        'path' => ltrim($entry->path, '/'),
                    ]),
                    'label' => $entry->labels[$this->locale->code()] ?? $entry->labels['en'] ?? $name,
                    'icon' => $entry->icon,
                    'permission' => $entry->permission,
                    'plugin' => $name,
                ];
            }
        }

        return $found;
    }
}
