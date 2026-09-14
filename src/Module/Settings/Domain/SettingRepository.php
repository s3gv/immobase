<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Domain;

interface SettingRepository
{
    public function find(string $name): ?Setting;

    public function save(Setting $setting): void;

    /**
     * Mehrere Einstellungen gemeinsam — ganz oder gar nicht.
     *
     * Die Organisation ist ein Stand und keine vierzehn Einzelangaben: eine
     * Anschrift aus der neuen Strasse und dem alten Ort waere schlimmer als
     * die alte Anschrift. Wer einen Briefkopf setzt, muss sich darauf
     * verlassen koennen, dass zusammengehoert, was zusammensteht.
     *
     * @param list<Setting> $settings
     */
    public function saveAll(array $settings): void;
}
