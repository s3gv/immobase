<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Die abgelegten Dateien eines Formulars.
 *
 * Ein Mehrfachfeld liefert ein Feld aus Eintraegen, und ein leeres
 * Dateifeld liefert einen Eintrag, der keine Datei ist. Beides an drei
 * Stellen auseinanderzunehmen waere drei Gelegenheiten, eines davon zu
 * vergessen.
 */
final class UploadedFiles
{
    private function __construct()
    {
    }

    /**
     * @return list<UploadedFile>
     */
    public static function from(Request $request, string $field = 'files'): array
    {
        $sent = $request->files->all()[$field] ?? [];
        $kept = [];

        foreach (\is_array($sent) ? $sent : [$sent] as $file) {
            if ($file instanceof UploadedFile) {
                $kept[] = $file;
            }
        }

        return $kept;
    }
}
