<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Region;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Waehlt den Umriss fuer den Hintergrund.
 *
 * Ist ein Bundesland konfiguriert, gilt es. Steht dort "auto", wird eines
 * zufaellig gewaehlt und fuer die Dauer der Sitzung beibehalten — es soll beim
 * Blaettern nicht springen.
 *
 * Eine Gesamtkarte Deutschlands gibt es bewusst nicht. Die gemeinsame Grenze
 * zweier Laender liegt in den Quelldaten als zwei getrennte Linienzuege vor;
 * jede Vereinfachung laesst sie auseinanderklaffen, und eine Rasterung, die das
 * verhindert, erzeugt sichtbare Treppenstufen. Bei ausreichender Genauigkeit
 * waere die Datei ueber 100 KB gross — eingebettet in jede Seite.
 *
 * Die Bewegung liegt in einer eigenen Klasse, siehe Drift.
 */
final readonly class Background
{
    public const AUTOMATIC = 'auto';

    private const SESSION_KEY = 'immobase.background_region';

    /**
     * @param list<string> $available
     */
    public function __construct(
        private RequestStack $requests,
        private LoggerInterface $logger,
        private string $configured,
        private array $available,
    ) {
    }

    public function region(): string
    {
        if ([] === $this->available) {
            return self::AUTOMATIC;
        }

        if (self::AUTOMATIC === $this->configured) {
            return $this->rememberedChoice();
        }

        if (\in_array($this->configured, $this->available, true)) {
            return $this->configured;
        }

        // Der Wert wird zu einem Dateinamen. Ein Tippfehler in der
        // Konfiguration wuerde sonst jede Seite mit 500 beenden — eine
        // Dekoration darf die Anwendung nicht lahmlegen.
        //
        // Nicht stillschweigend: wer sich vertippt hat, soll erfahren, warum
        // seine Einstellung ignoriert wird.
        $this->logger->warning(
            'IMMOBASE_REGION ist auf "{configured}" gesetzt, dazu gibt es keinen Umriss. '
            .'Es wird zufaellig gewaehlt. Erlaubt sind: {available}',
            ['configured' => $this->configured, 'available' => implode(', ', $this->available)],
        );

        return $this->rememberedChoice();
    }

    private function rememberedChoice(): string
    {
        $session = true === $this->requests->getCurrentRequest()?->hasSession() ? $this->requests->getSession() : null;
        $stored = $session?->get(self::SESSION_KEY);

        if (\is_string($stored) && \in_array($stored, $this->available, true)) {
            return $stored;
        }

        // Der Index kann nicht danebengreifen, aber das laesst sich statisch
        // nicht belegen — deshalb der ausdrueckliche Rueckfall.
        $chosen = $this->available[random_int(0, \count($this->available) - 1)] ?? null;

        if (null === $chosen) {
            return self::AUTOMATIC;
        }

        $session?->set(self::SESSION_KEY, $chosen);

        return $chosen;
    }
}
