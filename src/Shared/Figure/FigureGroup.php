<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Figure;

/**
 * Wovon eine Zahl auf der Uebersicht handelt.
 *
 * Vier Fragen, die jemand beim Einloggen hat: Wie steht es ums Geld? Was
 * verwalte ich? Wie weit ist das Abrechnungsjahr? Wartet jemand auf eine
 * Antwort? In dieser Reihenfolge stehen sie auch da.
 */
enum FigureGroup: string
{
    case Money = 'money';
    case Stock = 'stock';
    case Year = 'year';
    case Enquiries = 'enquiries';

    /** Was Plugins beisteuern — immer zuletzt, denn es kann fehlen. */
    case Plugins = 'plugins';

    public function labelKey(): string
    {
        return 'figure.group.'.$this->value;
    }

    /**
     * @return list<self>
     */
    public static function inOrder(): array
    {
        return [self::Money, self::Stock, self::Year, self::Enquiries, self::Plugins];
    }
}
