<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ob die Miete mit Umsatzsteuer abgerechnet wird — und mit welchem Satz.
 *
 * **Am Mietverhaeltnis und nicht an der Einheit.** Vermietung ist nach § 4
 * Nr. 12 a UStG steuerfrei; nach § 9 UStG darf der Vermieter darauf
 * verzichten, wenn der Mieter Unternehmer ist und das Objekt fuer Umsaetze
 * verwendet, die den Vorsteuerabzug nicht ausschliessen. Die Option haengt
 * also daran, was **der Mieter** damit macht — und dieselbe Gewerbeeinheit
 * kann nacheinander an einen optierenden und einen nicht optierenden Mieter
 * gehen.
 *
 * Bei Wohnraum gibt es die Option nicht. Wer dort Umsatzsteuer ausweist,
 * schuldet sie nach § 14c, ohne sie je bekommen zu haben — darum wird hier
 * nichts vorbelegt und nichts geraten.
 *
 * Der Satz steht in Basispunkten: 19 % sind 1900.
 */
#[ORM\Embeddable]
final class Taxation
{
    #[ORM\Column(name: 'vat_charged', type: Types::BOOLEAN)]
    private bool $charged;

    #[ORM\Column(name: 'vat_rate_bps', type: Types::INTEGER)]
    private int $rateBps;

    private function __construct(bool $charged, int $rateBps)
    {
        $this->charged = $charged;
        $this->rateBps = $rateBps;
    }

    /** Der Regelfall: steuerfrei, und damit ohne Ausweis. */
    public static function exempt(): self
    {
        return new self(false, 0);
    }

    /**
     * Optiert, mit einem Satz.
     *
     * Ein Satz von null waere keine Option, sondern ein Steuerausweis ueber
     * nichts; er faellt auf die Steuerfreiheit zurueck. Die Datenbank besteht
     * mit derselben Bedingung darauf.
     */
    public static function at(int $rateBps): self
    {
        return $rateBps > 0 ? new self(true, min($rateBps, 10000)) : self::exempt();
    }

    public function isCharged(): bool
    {
        return $this->charged;
    }

    public function rateBps(): int
    {
        return $this->rateBps;
    }
}
