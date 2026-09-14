<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der gewaehlte Verteilerschluessel einer Planzeile.
 *
 * Kennung, Beschriftung und Sorte gehoeren zusammen: die Kennung sagt, welcher
 * gemeint ist, die Beschriftung, wie er auf dem Blatt heisst, und die Sorte,
 * woher seine Anteile kommen. Die beiden Letzteren stehen mit an der Zeile,
 * damit die Freigabe sie einfrieren kann — und damit ein zwischendurch
 * umbenannter Schluessel den Entwurf nicht unlesbar macht.
 *
 * **Keiner gewaehlt ist ein moeglicher Zustand.** Ein Objekt ohne eigene
 * Schluessel, eine Zeile, die jemand anlegt und noch nicht fertig gedacht hat.
 * Die Berechnung meldet die Luecke und verteilt nicht — eine Zeile ohne
 * Schluessel still zu ueberspringen hiesse, ihren Betrag verschwinden zu
 * lassen.
 */
#[ORM\Embeddable]
final class ChosenKey
{
    #[ORM\Column(name: 'key_id', type: Types::GUID, nullable: true)]
    private ?string $id;

    #[ORM\Column(name: 'key_label', type: Types::STRING, length: 120)]
    private string $label;

    /** area | mea | persons | units | metered | fixed */
    #[ORM\Column(name: 'key_kind', type: Types::STRING, length: 16)]
    private string $kind;

    private function __construct(?string $id, string $label, string $kind)
    {
        $this->id = $id;
        $this->label = $label;
        $this->kind = $kind;
    }

    public static function none(): self
    {
        return new self(null, '', '');
    }

    public static function of(?string $id, string $label, string $kind): self
    {
        return null === $id ? self::none() : new self($id, $label, $kind);
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isChosen(): bool
    {
        return null !== $this->id;
    }
}
