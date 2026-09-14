<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Empfaenger, der kein PDF bekommen soll.
 *
 * Nur die Ausnahmen stehen hier — die Vorgabe ist, dass jeder eines bekommt.
 * Eine Zeile je Abwahl statt einer Liste in einer Spalte: die Auswahl faellt
 * im Entwurf, die Dokumente entstehen erst bei der Freigabe, und dazwischen
 * muss sie irgendwo liegen.
 *
 * Der Schluessel ist der des Vorschlags — Einheit, Art und Zeitraum. Aendert
 * sich der Empfaengerkreis, laeuft eine Abwahl ins Leere, und das ist
 * richtig: sie galt jemandem, den es nicht mehr gibt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_no_pdf')]
#[ORM\UniqueConstraint(name: 'billing_no_pdf_one', columns: ['statement_id', 'recipient_key'])]
class WithoutPdf
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'statement_id', type: Types::GUID)]
    private string $statementId;

    #[ORM\Column(name: 'recipient_key', type: Types::STRING, length: 120)]
    private string $recipientKey;

    public function __construct(string $statementId, string $recipientKey)
    {
        $this->id = Uuid::v4();
        $this->statementId = $statementId;
        $this->recipientKey = $recipientKey;
    }

    public function statementId(): string
    {
        return $this->statementId;
    }

    public function recipientKey(): string
    {
        return $this->recipientKey;
    }
}
