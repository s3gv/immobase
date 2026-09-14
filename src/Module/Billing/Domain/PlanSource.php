<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Was in einen Wirtschaftsplan eingegangen ist.
 *
 * Dieselben zwei Aufgaben wie {@see StatementSource}: festhalten, worauf ein
 * Plan steht, und die Quelle damit unloeschbar machen. Nur sind es hier keine
 * Jahreswerte, sondern die gepflegten Listen — die Kostenart und der
 * Verteilerschluessel.
 *
 * Warum das noetig ist, obwohl das freigegebene Schreiben alles eingefroren
 * traegt: eine Korrektur rechnet den Plan neu. Verschwaende der Schluessel
 * dazwischen, liesse sich nicht mehr sagen, wie verteilt wurde — und ein
 * Vorschuss, der ein Jahr lang gilt, wird oefter korrigiert als eine
 * Abrechnung.
 *
 * Die Zeilen entstehen schon im Entwurf. Ein Entwurf ist loeschbar und gibt
 * die Sperre damit wieder frei.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_plan_source')]
#[ORM\UniqueConstraint(name: 'billing_plan_source_key', columns: ['plan_id', 'key_id'])]
#[ORM\UniqueConstraint(name: 'billing_plan_source_kind', columns: ['plan_id', 'cost_kind_id'])]
class PlanSource
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(name: 'plan_id', type: Types::GUID)]
    private string $planId;

    #[ORM\Column(name: 'key_id', type: Types::GUID, nullable: true)]
    private ?string $keyId = null;

    #[ORM\Column(name: 'cost_kind_id', type: Types::GUID, nullable: true)]
    private ?string $costKindId = null;

    private function __construct(string $planId)
    {
        $this->id = Uuid::v4();
        $this->planId = $planId;
    }

    public static function key(string $planId, string $keyId): self
    {
        $source = new self($planId);
        $source->keyId = $keyId;

        return $source;
    }

    public static function costKind(string $planId, string $costKindId): self
    {
        $source = new self($planId);
        $source->costKindId = $costKindId;

        return $source;
    }

    public function planId(): string
    {
        return $this->planId;
    }

    public function keyId(): ?string
    {
        return $this->keyId;
    }

    public function costKindId(): ?string
    {
        return $this->costKindId;
    }

    /** Die Kennung der Quelle, egal welcher Sorte. */
    public function sourceId(): string
    {
        return $this->keyId ?? $this->costKindId ?? '';
    }
}
