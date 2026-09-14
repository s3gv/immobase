<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Application\ActiveManifests;
use App\Module\Plugin\Domain\Tile;
use App\Module\Plugin\Domain\TileRepository;
use App\Shared\Figure\ContributesFigures;
use App\Shared\Figure\Figure;
use App\Shared\Figure\FigureGroup;
use App\Shared\Locale\CurrentLocale;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Die Kacheln der Plugins auf der Uebersicht.
 *
 * **Aus der eigenen Datenbank und ohne Nachfrage beim Plugin.** Die
 * Uebersicht bleibt damit so schnell, wie sie ist, und ein haengendes Plugin
 * kann sie nicht aufhalten.
 *
 * **Was zu alt ist, verschwindet still.** Ein Plugin, das seit einem Tag
 * nichts mehr geliefert hat, laeuft nicht mehr — und eine Kachel mit einer
 * toten Zahl ist schlimmer als keine Kachel.
 *
 * **Jede Quelle prueft ihr eigenes Recht**, auch diese: eine Kachel sieht
 * nur, wer wenigstens eines der Rechte dieses Plugins hat. Sonst staende auf
 * der Startseite eine Zahl aus einem Bereich, den derjenige nicht oeffnen
 * darf — und der Verweis liefe in eine Absage.
 */
final readonly class PluginFigures implements ContributesFigures
{
    /** Laenger als das ist eine Zahl kein Stand mehr, sondern eine Erinnerung. */
    private const string FRESH = '-24 hours';

    public function __construct(
        private TileRepository $tiles,
        private ActiveManifests $manifests,
        private AuthorizationCheckerInterface $mayView,
        private CurrentLocale $locale,
        private UrlGeneratorInterface $urls,
        private ClockInterface $clock,
    ) {
    }

    public function figures(): array
    {
        $fresh = $this->tiles->fresh($this->clock->now()->modify(self::FRESH));

        return array_values(array_map(
            $this->describe(...),
            array_filter($fresh, $this->maySee(...)),
        ));
    }

    private function maySee(Tile $tile): bool
    {
        $manifest = $this->manifests->of($tile->plugin);

        if (null === $manifest) {
            return false;
        }

        foreach ($manifest->permissionKeys() as $key) {
            if ($this->mayView->isGranted($key)) {
                return true;
            }
        }

        return false;
    }

    private function describe(Tile $tile): Figure
    {
        return new Figure(
            FigureGroup::Plugins,
            '',
            $tile->value,
            $tile->tone,
            '' === $tile->path ? '' : $this->urls->generate('app_plugin_page', [
                'plugin' => $tile->plugin,
                'path' => ltrim($tile->path, '/'),
            ]),
            $tile->label($this->locale->code()),
        );
    }
}
