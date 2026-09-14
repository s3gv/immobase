<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Application;

use App\Module\Settings\Contract\ApplicationSettings;
use App\Module\Settings\Contract\LogoImage;
use App\Module\Settings\Contract\Organisation;
use App\Module\Settings\Domain\Logo;
use App\Module\Settings\Domain\LogoRepository;
use App\Module\Settings\Domain\OrganisationKeys;
use App\Module\Settings\Domain\Setting;
use App\Module\Settings\Domain\SettingIsTooLong;
use App\Module\Settings\Domain\SettingRepository;
use App\Shared\Pdf\Sender;
use App\Shared\Pdf\SenderOfLetters;

/**
 * Liest und schreibt Einstellungen.
 *
 * Die Namen der Optionen stehen als Konstanten am Contract — dort, wo auch
 * die lesenden Module sie finden.
 */
final readonly class Settings implements ApplicationSettings, SenderOfLetters
{
    public function __construct(
        private SettingRepository $settings,
        private LogoRepository $logos,
    ) {
    }

    public function organisation(): Organisation
    {
        $values = [];

        foreach (OrganisationKeys::all() as $field => $key) {
            $values[$field] = $this->text($key);
        }

        return new Organisation(...$values);
    }

    /**
     * Die Absenderangaben fuer einen Briefkopf.
     *
     * Dieselben Angaben wie in {@see Organisation()}, nur auf das
     * zusammengestrichen, was oben auf ein Blatt gehoert — und mit dem Logo
     * schon dabei, damit ein Brief nicht zweimal fragen muss.
     */
    public function sender(): Sender
    {
        $organisation = $this->organisation();

        return new Sender(
            $organisation->name,
            $organisation->street,
            $organisation->postalCode,
            $organisation->city,
            $this->logo()?->bytes,
        );
    }

    public function logo(): ?LogoImage
    {
        $logo = $this->logos->current();

        return null === $logo ? null : new LogoImage($logo->bytes(), Logo::CONTENT_TYPE);
    }

    public function bool(string $key, bool $default): bool
    {
        $setting = $this->settings->find($key);

        return null === $setting ? $default : '1' === $setting->value();
    }

    public function setBool(string $key, bool $value): void
    {
        $this->set($key, $value ? '1' : '0');
    }

    public function int(string $key, int $default): int
    {
        $value = $this->settings->find($key)?->value();

        // `is_numeric` liesse „12,5" und „1e3" durch; beides ist keine Zahl
        // von Tagen und keine Anzahl Cent.
        return null !== $value && 1 === preg_match('/^-?\d+$/D', $value) ? (int) $value : $default;
    }

    public function setInt(string $key, int $value): void
    {
        $this->set($key, (string) $value);
    }

    public function text(string $key, string $default = ''): string
    {
        return $this->settings->find($key)?->value() ?? $default;
    }

    /**
     * Eine leere Angabe loescht nicht, sie steht als leer da.
     *
     * „Nicht angegeben" und „nicht vorhanden" sind hier dasselbe: eine
     * frische Installation hat keine Zeile, und wer ein Feld leert, will
     * genau das.
     */
    public function setText(string $key, string $value): void
    {
        $this->set($key, trim($value));
    }

    /**
     * Mehrere Angaben als ein Stand.
     *
     * Geschrieben wird gemeinsam oder gar nicht: die Organisation steht
     * spaeter im Briefkopf, und ein Briefkopf aus der neuen Strasse und dem
     * alten Ort waere schlimmer als der alte Briefkopf.
     *
     * @param array<string, string> $values Schluessel auf Angabe
     *
     * @throws SettingIsTooLong
     */
    public function setTexts(array $values): void
    {
        $settings = [];

        foreach ($values as $key => $value) {
            $settings[] = $this->changed($key, trim($value));
        }

        $this->settings->saveAll($settings);
    }

    private function set(string $key, string $value): void
    {
        $this->settings->save($this->changed($key, $value));
    }

    /**
     * @throws SettingIsTooLong
     */
    private function changed(string $key, string $value): Setting
    {
        $setting = $this->settings->find($key);

        if (null === $setting) {
            return new Setting($key, $value);
        }

        $setting->changeTo($value);

        return $setting;
    }
}
