<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Module\Party\Application\PartyDraft;
use App\Shared\Contact\Email;
use App\Shared\Flow\ListInstruction;
use App\Shared\Text\Trimmed;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Liest die Angaben eines Schritts und prueft sie.
 *
 * Getrennt vom Ablauf-Controller, weil sonst beides in einer Klasse steht:
 * das Fuehren durch die Schritte und das Verstehen dessen, was eingetippt
 * wurde.
 *
 * Die Meldungen sind Uebersetzungsschluessel. Die Wertobjekte der Domaene
 * werfen zwar auch, aber mit Texten fuer Entwickler.
 */
final readonly class PartyStepInput
{
    /**
     * @return array<string, mixed>
     */
    public function collect(string $step, Request $request): array
    {
        return match ($step) {
            'kind' => [
                'kind' => $request->request->getString('kind', 'person'),
                'roles' => $this->strings($request, 'roles'),
            ],
            'name' => [
                'name' => $request->request->getString('name'),
                'givenName' => $request->request->getString('givenName'),
            ],
            'address' => ['entries' => $this->rows($request, 'entries')],
            'contact' => [
                'emails' => $this->strings($request, 'emails'),
                'phones' => $this->strings($request, 'phones'),
                'taxNumber' => $request->request->getString('taxNumber'),
            ],
            'note' => ['note' => $request->request->getString('note')],
            default => [],
        };
    }

    /**
     * Wendet eine Listenanweisung auf die Angaben eines Schritts an.
     *
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    public function applyTo(array $step, ListInstruction $instruction): array
    {
        return $instruction->applyTo($step, $this->addition($instruction));
    }

    /**
     * Was beim Hinzufuegen zu einer Liste entsteht.
     *
     * Bei Anschriften eine leere Zeile der gewaehlten Art, sonst ein leeres
     * Textfeld.
     *
     * @return array<string, string>|string
     */
    public function addition(ListInstruction $instruction): array|string
    {
        if ('entries' !== $instruction->field) {
            return '';
        }

        $kind = '' === $instruction->argument ? 'street' : $instruction->argument;

        return ['kind' => $kind, 'addition' => '', 'line' => '', 'postalCode' => '', 'city' => ''];
    }

    /**
     * Der erste Schritt, der noch nicht in Ordnung ist.
     *
     * Beim Bearbeiten laesst sich ueber die Schrittliste springen. Damit kann
     * jemand das Pruefen erreichen, obwohl ein frueherer Schritt unvollstaendig
     * ist — ohne diese Suche baute die Domaene daraus einen Datensatz und
     * scheiterte mit einem Serverfehler.
     *
     * @param array<string, array<string, mixed>> $values
     *
     * @return array{step: string, errors: array<string, string>}|null
     */
    public function firstProblem(array $values): ?array
    {
        foreach (['kind', 'name', 'address', 'contact'] as $step) {
            $errors = $this->errors($step, $values);

            if ([] !== $errors) {
                return ['step' => $step, 'errors' => $errors];
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $values
     *
     * @return array<string, string>
     */
    public function errors(string $step, array $values): array
    {
        $draft = new PartyDraft($values);

        return match ($step) {
            'kind' => [] === $draft->roles() ? ['roles' => 'party.error.roles'] : [],
            'name' => null === Trimmed::orNull($draft->name()) ? ['name' => 'party.error.name'] : [],
            'address' => $this->addressErrors($draft),
            'contact' => $this->contactErrors($draft),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function addressErrors(PartyDraft $draft): array
    {
        $rows = $draft->addressRows();

        if ([] === $rows) {
            return ['entries' => 'party.error.address_required'];
        }

        foreach ($rows as $row) {
            if ('' === $row['line'] || '' === $row['postalCode'] || '' === $row['city']) {
                return ['entries' => 'party.error.address_incomplete'];
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function contactErrors(PartyDraft $draft): array
    {
        $emails = $draft->emails();

        if ([] === $emails) {
            return ['emails' => 'party.error.email_required'];
        }

        foreach ($emails as $email) {
            try {
                Email::fromString($email);
            } catch (InvalidArgumentException) {
                return ['emails' => 'party.error.email_invalid'];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function strings(Request $request, string $name): array
    {
        return array_values(array_filter($request->request->all($name), \is_string(...)));
    }

    /**
     * @return list<array<mixed>>
     */
    private function rows(Request $request, string $name): array
    {
        return array_values(array_filter($request->request->all($name), \is_array(...)));
    }
}
