<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\UserInterface\Twig;

use App\Module\Plugin\Application\ActiveManifests;
use App\Shared\Locale\CurrentLocale;
use Collator;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Beschriftungen fuer Rechte, auch fuer die, die der Core nicht kennt.
 *
 * Die Rechte der Module stehen in den Uebersetzungsdateien. Die eines
 * Plugins koennen dort nicht stehen — der Core kennt den Wortlaut eines
 * fremden Plugins nicht, und ein Drittanbieter kann unsere Dateien nicht
 * ergaenzen. Sie kommen deshalb aus dem Manifest.
 *
 * `{{ permission.labelKey|permission_text }}` gibt dasselbe wie `|trans`,
 * solange der Schluessel zu einem Modul gehoert. Gehoert er zu einem Plugin,
 * setzt es die Beschriftung aus Manifest und Aktion zusammen: „Auswertungen"
 * plus „Anzeigen".
 *
 * `|by_area_label` ordnet eine nach Bereich gegliederte Liste nach dieser
 * Beschriftung. Die Schluessel sind englisch, die Oberflaeche nicht — nach
 * Schluessel stuende „Protokoll" (audit) vorn.
 */
final class PermissionTextExtension extends AbstractExtension
{
    public function __construct(
        private readonly ActiveManifests $manifests,
        private readonly TranslatorInterface $translator,
        private readonly CurrentLocale $locale,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('permission_text', $this->text(...)),
            new TwigFilter('by_area_label', $this->byAreaLabel(...)),
        ];
    }

    /**
     * @template T
     *
     * @param array<string, T> $areas Bereich auf seinen Inhalt
     *
     * @return array<string, T>
     */
    public function byAreaLabel(array $areas): array
    {
        $collator = new Collator($this->locale->code());

        uksort($areas, fn (string $a, string $b): int => (int) $collator->compare(
            $this->text('permission.area.'.$a),
            $this->text('permission.area.'.$b),
        ));

        return $areas;
    }

    public function text(string $key): string
    {
        $parts = explode('.', $key);

        if (4 === \count($parts) && 'permission' === $parts[0]) {
            return $this->labelOf($key, $parts[1], $parts[2], $parts[3]);
        }

        if (3 === \count($parts) && 'permission' === $parts[0] && 'area' === $parts[1]) {
            return $this->areaOf($parts[2]) ?? $this->translator->trans($key);
        }

        return $this->translator->trans($key);
    }

    private function labelOf(string $key, string $area, string $action, string $kind): string
    {
        $label = $this->areaOf($area);

        if (null === $label) {
            return $this->translator->trans($key);
        }

        // Ein Plugin erklaert seine Rechte nicht; es benennt sie. Eine
        // erfundene Erklaerung waere schlechter als keine.
        return 'explanation' === $kind
            ? ''
            : $label.' — '.$this->translator->trans('permission.action.'.$action);
    }

    private function areaOf(string $area): ?string
    {
        foreach ($this->manifests->all() as [, $manifest]) {
            foreach ($manifest->permissions as $declared) {
                if ($declared->area === $area) {
                    return $declared->labels[$this->locale->code()] ?? $declared->labels['en'] ?? $area;
                }
            }
        }

        return null;
    }
}
