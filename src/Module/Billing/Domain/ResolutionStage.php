<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Wie weit ein Wirtschaftsplan ist — und seit wann.
 *
 * Zustand und Tage gehoeren zusammen und werden nur zusammen gesetzt. Der
 * Herausgabetag sagt, was den Eigentuemern vor der Versammlung vorlag; der
 * Beschlusstag ist das Briefdatum der beschlossenen Fassung und steht auf
 * jedem Schreiben.
 *
 * Der Beschlusstag ist mehr als eine Notiz: weil ein PDF spaeter erneut
 * erzeugt wird, muss er derselbe bleiben — „heute" waere morgen ein anderes
 * Dokument.
 */
#[ORM\Embeddable]
final class ResolutionStage
{
    #[ORM\Column(type: Types::STRING, length: 16, enumType: ResolutionStatus::class)]
    private ResolutionStatus $status;

    #[ORM\Column(name: 'proposed_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $proposedOn;

    #[ORM\Column(name: 'released_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $releasedOn;

    private function __construct(
        ResolutionStatus $status,
        ?DateTimeImmutable $proposedOn,
        ?DateTimeImmutable $releasedOn,
    ) {
        $this->status = $status;
        $this->proposedOn = $proposedOn;
        $this->releasedOn = $releasedOn;
    }

    public static function pending(): self
    {
        return new self(ResolutionStatus::Draft, null, null);
    }

    /** Herausgegeben, damit die Versammlung darueber beschliessen kann. */
    public function proposed(DateTimeImmutable $day): self
    {
        return new self(ResolutionStatus::Proposed, $day, null);
    }

    /**
     * Beschlossen.
     *
     * Der Herausgabetag bleibt stehen: er sagt, was den Eigentuemern vorlag,
     * und das aendert der Beschluss nicht mehr.
     */
    public function released(DateTimeImmutable $day): self
    {
        return new self(ResolutionStatus::Released, $this->proposedOn, $day);
    }

    /**
     * Geaendert — damit ist die Vorlage keine mehr.
     *
     * Der Herausgabetag sagt, **was** den Eigentuemern an diesem Tag vorlag.
     * Sobald sich daran etwas aendert, stimmt dieser Satz nicht mehr, und ein
     * Blatt mit dem alten Tag behauptete etwas, das so nie herausging. Also
     * faellt der Plan zurueck auf Entwurf und will neu vorgelegt werden.
     *
     * Ein beschlossener Plan bleibt unberuehrt: an ihm aendert sich nichts
     * mehr, und wenn doch jemand hier ankaeme, waere Zurueckfallen die
     * schlimmere Antwort.
     */
    public function revised(): self
    {
        return ResolutionStatus::Proposed === $this->status ? self::pending() : $this;
    }

    public function status(): ResolutionStatus
    {
        return $this->status;
    }

    public function wasProposed(): bool
    {
        return null !== $this->proposedOn;
    }

    /** Laesst sich hier noch etwas aendern? */
    public function isOpen(): bool
    {
        return $this->status->isOpen();
    }

    public function proposedOn(): ?DateTimeImmutable
    {
        return $this->proposedOn;
    }

    /** Das Briefdatum — null, solange nichts beschlossen ist. */
    public function day(): ?DateTimeImmutable
    {
        return $this->releasedOn;
    }
}
