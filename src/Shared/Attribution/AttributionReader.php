<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Shared\Attribution;

use JsonException;

/**
 * Liest die von tools/generate-attributions.php erzeugte Datei.
 *
 * Fehlt oder bricht sie, wird nichts geworfen: eine unvollstaendige
 * Namensnennung ist ein Problem, eine Anwendung, die deswegen gar nicht mehr
 * startet, ein groesseres. Das Modal zeigt dann eben weniger — und die
 * Pruefung in bin/check faengt den Fall, bevor er ausgeliefert wird.
 */
final readonly class AttributionReader
{
    public function __construct(private string $file)
    {
    }

    /**
     * @return list<array<mixed>>
     */
    public function manual(): array
    {
        return $this->section('manual');
    }

    /**
     * @return list<array<mixed>>
     */
    public function packages(): array
    {
        return $this->section('packages');
    }

    /**
     * @return list<array<mixed>>
     */
    private function section(string $name): array
    {
        $document = $this->document();
        $section = $document[$name] ?? null;

        if (!\is_array($section)) {
            return [];
        }

        return array_values(array_filter($section, \is_array(...)));
    }

    /**
     * @return array<mixed>
     */
    private function document(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        try {
            $document = json_decode((string) file_get_contents($this->file), true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return \is_array($document) ? $document : [];
    }
}
