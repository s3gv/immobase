<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Was in einen Lauf eingegangen ist.
 *
 * Zwei Aufgaben in einer Zeile. Erstens haelt sie fest, was der Mensch
 * angehakt hat — daraus rechnet die Korrekturpruefung den heutigen Stand
 * nach. Zweitens macht der Fremdschluessel darauf die Quelle unloeschbar:
 * eine Kostenposition, auf der eine Abrechnung steht, verschwindet nicht mehr.
 *
 * Die Zeilen entstehen **schon im Entwurf** und nicht erst bei der Freigabe.
 * Damit schuetzt auch ein Entwurf seine Quellen — eine Kostenposition unter
 * einem laufenden Entwurf wegzuloeschen wuerde ihn still falsch machen. Ein
 * Entwurf ist loeschbar und gibt die Sperre damit wieder frei.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_source')]
#[ORM\UniqueConstraint(name: 'billing_source_cost', columns: ['statement_id', 'cost_year_id'])]
#[ORM\UniqueConstraint(name: 'billing_source_payment', columns: ['statement_id', 'payment_id'])]
class StatementSource
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'statement_id', type: Types::GUID)]
    private string $statementId;

    #[ORM\Column(name: 'cost_year_id', type: Types::GUID, nullable: true)]
    private ?string $costYearId = null;

    #[ORM\Column(name: 'payment_id', type: Types::GUID, nullable: true)]
    private ?string $paymentId = null;

    private function __construct(string $statementId)
    {
        $this->id = Uuid::v4();
        $this->statementId = $statementId;
    }

    public static function cost(string $statementId, string $costYearId): self
    {
        $source = new self($statementId);
        $source->costYearId = $costYearId;

        return $source;
    }

    public static function payment(string $statementId, string $paymentId): self
    {
        $source = new self($statementId);
        $source->paymentId = $paymentId;

        return $source;
    }

    public function statementId(): string
    {
        return $this->statementId;
    }

    public function costYearId(): ?string
    {
        return $this->costYearId;
    }

    public function paymentId(): ?string
    {
        return $this->paymentId;
    }

    /** Die Kennung der Quelle, egal welcher Sorte. */
    public function sourceId(): string
    {
        return $this->costYearId ?? $this->paymentId ?? '';
    }
}
