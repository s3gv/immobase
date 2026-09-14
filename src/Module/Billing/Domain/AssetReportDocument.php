<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wer den Bericht bekommen hat.
 *
 * Nur der Empfaenger, nicht der Inhalt — und das ist der Unterschied zum
 * Schreiben einer Abrechnung oder eines Wirtschaftsplans. Dort bekommt jede
 * Einheit eigene Zahlen, weil verteilt wird. Hier wird nichts verteilt: der
 * Bericht spricht ueber das Vermoegen der Gemeinschaft, und das ist fuer
 * alle dasselbe. Ihn je Empfaenger noch einmal einzufrieren hiesse, denselben
 * Text vierzig Mal zu speichern und vierzig Gelegenheiten zu schaffen, dass
 * einer davon abweicht.
 *
 * Name und Anschrift stehen fest, sobald der Bericht heraus ist. Wer umzieht,
 * hat ihn unter der alten Anschrift bekommen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_asset_report_document')]
#[ORM\Index(name: 'billing_asset_document_unit', columns: ['unit_id'])]
class AssetReportDocument
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: AssetReport::class, inversedBy: 'documents')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AssetReport $report;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(name: 'unit_number', type: Types::INTEGER)]
    private int $unitNumber;

    #[ORM\Column(name: 'unit_label', type: Types::STRING, length: 200)]
    private string $unitLabel;

    #[ORM\Embedded(class: Recipient::class, columnPrefix: false)]
    private Recipient $recipient;

    public function __construct(
        AssetReport $report,
        string $unitId,
        int $unitNumber,
        string $unitLabel,
        string $recipientLabel,
        string $recipientAddress,
    ) {
        $this->id = Uuid::v4();
        $this->report = $report;
        $this->unitId = $unitId;
        $this->unitNumber = $unitNumber;
        $this->unitLabel = $unitLabel;
        $this->recipient = new Recipient($recipientLabel, $recipientAddress);
        $report->hold($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function unitNumber(): int
    {
        return $this->unitNumber;
    }

    public function unitLabel(): string
    {
        return $this->unitLabel;
    }

    public function recipient(): Recipient
    {
        return $this->recipient;
    }

    public function reference(): Reference
    {
        return AssetReportReference::of($this->report, $this->unitNumber);
    }
}
