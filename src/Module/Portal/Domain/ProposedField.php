<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\Domain;

use App\Shared\Identity\Uuid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Feld eines Vorschlags: Schluessel, bisheriger Wert, gewuenschter Wert.
 *
 * **Der bisherige Wert wird mitgeschrieben und nicht nachgeschlagen.** Er ist
 * der Stand, auf den sich der Vorschlag bezieht; hat ihn inzwischen jemand
 * anders geaendert, soll genau das auffallen — und das geht nur, wenn es
 * beide Werte gibt.
 *
 * Die Beschriftung steht als Schluessel mit dabei, damit ein Vorschlag auch
 * dann noch zu lesen ist, wenn das besitzende Modul das Feld inzwischen nicht
 * mehr freigibt.
 */
#[ORM\Entity]
#[ORM\Table(name: 'portal_proposed_field')]
#[ORM\Index(name: 'portal_proposed_field_proposal', columns: ['proposal_id'])]
class ProposedField
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Proposal::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Proposal $proposal;

    #[ORM\Column(name: 'field_key', type: Types::STRING, length: 100)]
    private string $key;

    #[ORM\Column(name: 'label_key', type: Types::STRING, length: 200)]
    private string $labelKey;

    #[ORM\Column(name: 'was_value', type: Types::TEXT)]
    private string $was;

    #[ORM\Column(name: 'wanted_value', type: Types::TEXT)]
    private string $wanted;

    public function __construct(Proposal $proposal, string $key, string $labelKey, string $was, string $wanted)
    {
        $this->id = Uuid::v4();
        $this->proposal = $proposal;
        $this->key = $key;
        $this->labelKey = $labelKey;
        $this->was = $was;
        $this->wanted = $wanted;

        $proposal->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function labelKey(): string
    {
        return $this->labelKey;
    }

    public function was(): string
    {
        return $this->was;
    }

    public function wanted(): string
    {
        return $this->wanted;
    }
}
