<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Ui;

use Symfony\Component\HttpFoundation\Request;

/**
 * Ob eine Uebersicht auch Vergangenes zeigt.
 *
 * Beim Oeffnen will man wissen, was gilt: was beendet, archiviert oder
 * deaktiviert ist, steht nicht im Weg. Die Geschichte sucht man bewusst —
 * dafuer der Schalter.
 *
 * Angefangenes bleibt immer sichtbar. Ein Entwurf oder eine offene Einladung
 * ist keine Vergangenheit, sondern Arbeit, die noch aussteht.
 *
 * Ein ausdruecklich gewaehlter Status schlaegt den Schalter: wer „Beendet"
 * auswaehlt, will Beendetes sehen, und eine leere Liste waere eine
 * Nicht-Antwort auf eine klare Frage.
 */
final readonly class Past
{
    public const string PARAMETER = 'vergangene';

    private function __construct(public bool $shown)
    {
    }

    public static function hidden(): self
    {
        return new self(false);
    }

    public static function shown(): self
    {
        return new self(true);
    }

    /**
     * Aus der Adresszeile — und aus einem Status, der selbst Vergangenes ist.
     */
    public static function from(Request $request, bool $chosenIsPast = false): self
    {
        return new self($chosenIsPast || '' !== trim($request->query->getString(self::PARAMETER)));
    }
}
