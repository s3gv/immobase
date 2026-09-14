<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\UserInterface\Controller;

use App\Module\Settings\Application\Settings;
use App\Module\Settings\Domain\OrganisationKeys;
use App\Module\Settings\Domain\Setting;
use App\Shared\Bank\Bic;
use App\Shared\Bank\Iban;
use App\Shared\Bank\NotABic;
use App\Shared\Bank\NotAnIban;
use App\Shared\Contact\Email;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was aus dem Organisationsformular kommt.
 *
 * Geprueft wird, was sich pruefen laesst: die IBAN ueber ihre Pruefziffer,
 * die BIC ueber ihre Gestalt, die E-Mail ueber {@see Email}. Der Rest ist
 * Text und wird als solcher genommen — eine Firmierung hat keine Form.
 *
 * Gespeichert wird erst, wenn alles durchgeht. Ein halb uebernommenes
 * Formular waere schlimmer als eines, das zurueckkommt: danach stuende ein
 * Teil da und der andere nicht, und niemand saehe, welcher.
 */
final readonly class OrganisationInput
{
    public function __construct(private Settings $settings)
    {
    }

    /**
     * @return array<string, string> Feldname auf Fehlerschluessel — leer heisst: gespeichert
     */
    public function save(Request $request): array
    {
        $values = self::readFrom($request);
        $errors = self::checked($values);

        if ([] !== $errors) {
            return $errors;
        }

        $texts = [];

        foreach (OrganisationKeys::all() as $field => $key) {
            $texts[$key] = $values[$field] ?? '';
        }

        $this->settings->setTexts($texts);

        return [];
    }

    /**
     * Die Werte in der Schreibweise, in der sie gespeichert wuerden.
     *
     * IBAN und BIC werden normalisiert — getippt wird in Gruppen, gespeichert
     * am Stueck.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private static function checked(array &$values): array
    {
        $errors = [];

        try {
            $values['iban'] = Iban::orNull($values['iban'] ?? '')?->toString() ?? '';
        } catch (NotAnIban) {
            $errors['iban'] = 'settings.error.iban';
        }

        try {
            $values['bic'] = Bic::orNull($values['bic'] ?? '')?->toString() ?? '';
        } catch (NotABic) {
            $errors['bic'] = 'settings.error.bic';
        }

        if ('' !== ($values['email'] ?? '')) {
            try {
                $values['email'] = Email::fromString($values['email'] ?? '')->toString();
            } catch (InvalidArgumentException) {
                $errors['email'] = 'settings.error.email';
            }
        }

        return [...$errors, ...self::tooLong($values)];
    }

    /**
     * Was nicht in die Ablage passt, sagt das Formular und nicht die Spalte.
     *
     * Geprueft wird nach dem Normalisieren: die IBAN ohne Leerzeichen ist
     * kuerzer als die getippte, und abgewiesen werden soll, was wirklich
     * gespeichert wuerde.
     *
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private static function tooLong(array $values): array
    {
        $errors = [];

        foreach ($values as $field => $value) {
            if (mb_strlen($value) > Setting::MOST_CHARACTERS) {
                $errors[$field] = 'settings.error.too_long';
            }
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    private static function readFrom(Request $request): array
    {
        $values = [];

        foreach (array_keys(OrganisationKeys::all()) as $field) {
            $values[$field] = trim($request->request->getString($field));
        }

        return $values;
    }
}
