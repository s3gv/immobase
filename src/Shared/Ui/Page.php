<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Ui;

/**
 * Ein Ausschnitt aus einer Liste.
 *
 * Die Seitengroesse steht hier und nicht in jedem Modul: sonst zeigt die eine
 * Uebersicht dreissig Zeilen und die naechste hundert, und niemand kann sagen,
 * warum.
 *
 * Die gewuenschte Seite wird zurechtgerueckt statt abgelehnt. Sie kommt aus
 * der Adresszeile und ist damit Eingabe — "?page=0" oder "?page=999" ist kein
 * Fehler, sondern eine Angabe, die es so nicht gibt.
 */
final readonly class Page
{
    public const int PER_PAGE = 50;

    private function __construct(
        public int $number,
        public int $pages,
        public int $total,
    ) {
    }

    public static function of(int $requested, int $total): self
    {
        $counted = max(0, $total);
        $pages = max(1, (int) ceil($counted / self::PER_PAGE));

        return new self(min(max(1, $requested), $pages), $pages, $counted);
    }

    public function offset(): int
    {
        return ($this->number - 1) * self::PER_PAGE;
    }

    public function limit(): int
    {
        return self::PER_PAGE;
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total;
    }
}
