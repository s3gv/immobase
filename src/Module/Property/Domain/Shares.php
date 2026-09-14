<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Property\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der Nenner, in dem die Miteigentumsanteile dieses Objekts stehen.
 *
 * Er gilt fuer alle Anteile zugleich. Ihn spaeter zu aendern hiesse, jeden
 * erfassten Anteil umzurechnen — deshalb geht es nur, solange keiner erfasst
 * ist.
 *
 * „Erfasst" schliesst die Anteile der **Eigentuemer** ein. Einen davon kann
 * man eintragen, waehrend die Einheit selbst noch bei null steht — genau
 * dafuer ist der Schritt da, wenn die Teilungserklaerung erst halb abgetippt
 * ist. Nur die Einheit zu fragen hiesse, diese Anteile stillschweigend zu
 * verschieben: aus 100/1000 wuerde 100/10000.
 *
 * Ob welche erfasst sind, weiss das Objekt und nicht dieses Wertobjekt: es
 * bekommt die Antwort gereicht und entscheidet damit.
 */
#[ORM\Embeddable]
final class Shares
{
    #[ORM\Column(name: 'mea_denominator', type: Types::INTEGER, enumType: MeaDenominator::class)]
    private MeaDenominator $denominator;

    private function __construct(MeaDenominator $denominator)
    {
        $this->denominator = $denominator;
    }

    /** Tausendstel — was in den meisten Teilungserklaerungen steht. */
    public static function inThousandths(): self
    {
        return new self(MeaDenominator::Thousand);
    }

    public function denominator(): MeaDenominator
    {
        return $this->denominator;
    }

    /**
     * @throws MeaAlreadyDistributed
     */
    public function scaledTo(MeaDenominator $denominator, bool $anyDistributed): self
    {
        if ($denominator !== $this->denominator && $anyDistributed) {
            throw new MeaAlreadyDistributed();
        }

        return new self($denominator);
    }
}
