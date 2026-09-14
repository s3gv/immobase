<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Domain;

/**
 * Die Schluessel der Organisationsangaben — an einem Ort.
 *
 * Sie stehen hier und nicht am Contract: fremde Module lesen sie nicht
 * einzeln, sondern fragen einmal nach {@see \App\Module\Settings\Contract\Organisation}.
 * Innerhalb des Moduls muessen Formular, Speicherung und Abfrage sich aber
 * auf dieselben Namen einigen, und eine frei geschriebene Zeichenkette faellt
 * erst auf, wenn eine Angabe stillschweigend leer bleibt.
 *
 * Die Reihenfolge ist die des Formulars: Firmierung, Anschrift, Kontakt,
 * Bank, Impressum.
 */
final class OrganisationKeys
{
    public const string NAME = 'organisation.name';
    public const string STREET = 'organisation.street';
    public const string POSTAL_CODE = 'organisation.postal_code';
    public const string CITY = 'organisation.city';
    public const string PHONE = 'organisation.phone';
    public const string EMAIL = 'organisation.email';
    public const string WEBSITE = 'organisation.website';
    public const string IBAN = 'organisation.iban';
    public const string BIC = 'organisation.bic';
    public const string ACCOUNT_HOLDER = 'organisation.account_holder';
    public const string MANAGEMENT = 'organisation.management';
    public const string REGISTER_COURT = 'organisation.register_court';
    public const string REGISTER_NUMBER = 'organisation.register_number';
    public const string VAT_ID = 'organisation.vat_id';

    private function __construct()
    {
    }

    /**
     * Feldname im Formular auf Schluessel.
     *
     * Eine Liste und keine vierzehn Zeilen im Controller: was hier steht,
     * wird gelesen, geschrieben und gezeichnet — dreimal dieselbe Aufzaehlung
     * waere dreimal dieselbe Gelegenheit, eine zu vergessen.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'name' => self::NAME,
            'street' => self::STREET,
            'postalCode' => self::POSTAL_CODE,
            'city' => self::CITY,
            'phone' => self::PHONE,
            'email' => self::EMAIL,
            'website' => self::WEBSITE,
            'iban' => self::IBAN,
            'bic' => self::BIC,
            'accountHolder' => self::ACCOUNT_HOLDER,
            'management' => self::MANAGEMENT,
            'registerCourt' => self::REGISTER_COURT,
            'registerNumber' => self::REGISTER_NUMBER,
            'vatId' => self::VAT_ID,
        ];
    }
}
