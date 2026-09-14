<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Application;

use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\Message;
use App\Module\Portal\Domain\Proposal;
use App\Module\Portal\Domain\ProposedField;
use App\Shared\Change\ChangeableField;
use App\Shared\Change\RecordKind;
use Symfony\Component\Clock\ClockInterface;

/**
 * Einen Aenderungswunsch stellen.
 *
 * **Was `fieldsOf()` nicht nennt, kommt nicht durch.** Die Werte aus dem
 * Formular werden gegen die Felder gehalten, die das besitzende Modul
 * freigibt — ein umgebogenes Formular traegt damit nichts ein, was die
 * Stammdaten nicht aendern lassen wuerden.
 *
 * **Und nur, was sich aendert.** Ein Vorschlag, der zwanzig unveraenderte
 * Felder mitschickt, waere beim Freigeben eine Tabelle, in der man das eine
 * Geaenderte suchen muss.
 */
final readonly class ProposeAChange
{
    public function __construct(
        private ChangeableRecords $records,
        private WhatIsMine $mine,
        private EnquiryRepository $enquiries,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Die Felder, die dieser Mensch an diesem Datensatz vorschlagen darf.
     *
     * Leer heisst: nicht seiner, oder daran gibt es nichts vorzuschlagen. Der
     * Unterschied geht die Oberflaeche nichts an — beides fuehrt auf
     * dieselbe leere Seite.
     *
     * @return list<ChangeableField>
     */
    public function fieldsOf(RecordKind $kind, string $recordId): array
    {
        if (!$this->mine->owns($kind, $recordId)) {
            return [];
        }

        return $this->records->of($kind)?->fieldsOf($recordId) ?? [];
    }

    /**
     * Aus den geaenderten Feldern wird eine Anfrage mit einem Vorschlag
     * daran.
     *
     * Null heisst: es hat sich nichts geaendert. Wer nichts aendert, schickt
     * nichts — das ist keine Fehlermeldung wert, aber auch keine Anfrage.
     *
     * @param array<string, string> $values
     */
    public function propose(
        RecordKind $kind,
        string $recordId,
        string $partyId,
        string $who,
        string $subject,
        string $body,
        array $values,
    ): ?Enquiry {
        $changed = $this->changed($kind, $recordId, $values);

        if ([] === $changed) {
            return null;
        }

        $now = $this->clock->now();
        $enquiry = new Enquiry($this->enquiries->nextNumber(), $partyId, $subject, $now);
        new Message($enquiry, null, $who, $body, $now);
        $proposal = new Proposal($enquiry, $kind, $recordId, $now);

        foreach ($changed as $field) {
            new ProposedField($proposal, $field['key'], $field['labelKey'], $field['was'], $field['wanted']);
        }

        $this->enquiries->save($enquiry);

        return $enquiry;
    }

    /**
     * @param array<string, string> $values
     *
     * @return list<array{key: string, labelKey: string, was: string, wanted: string}>
     */
    private function changed(RecordKind $kind, string $recordId, array $values): array
    {
        $changed = [];

        foreach ($this->fieldsOf($kind, $recordId) as $field) {
            $wanted = self::tidy($values[$field->key] ?? $field->value);

            if ($wanted !== self::tidy($field->value)) {
                $changed[] = [
                    'key' => $field->key,
                    'labelKey' => $field->labelKey,
                    'was' => $field->value,
                    'wanted' => $wanted,
                ];
            }
        }

        return $changed;
    }

    /**
     * Zeilenenden vereinheitlichen, bevor verglichen wird.
     *
     * Ein Browser schickt aus einem mehrzeiligen Feld „\r\n" zurueck, in der
     * Datenbank steht „\n". Ohne diese Zeile gilt jede Liste von
     * E-Mail-Adressen als geaendert, sobald das Formular abgeschickt wird —
     * und im Vorschlag stuenden drei Zeilen, die niemand angefasst hat.
     */
    private static function tidy(string $value): string
    {
        return trim(str_replace("\r\n", "\n", $value));
    }
}
