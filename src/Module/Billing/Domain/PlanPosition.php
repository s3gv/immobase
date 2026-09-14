<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Zeile des Gesamtwirtschaftsplans.
 *
 * Sie traegt beide Zahlen: den Ist-Wert des Vorjahres und den Planwert. Das
 * ist keine Bequemlichkeit, sondern die Pflichtangabe — ein Plan muss „nach
 * Grund und Hoehe nachpruefbar" sein, und eine Zahl allein ist das nie. Die
 * Veraenderung dazwischen rechnet sich daraus; sie wird nicht gespeichert,
 * weil sie sonst eine zweite Wahrheit waere.
 *
 * **Einmalig** ist das Haekchen gegen den haeufigsten Fehler der Praxis: eine
 * Dachreparatur aus dem Vorjahr, unbesehen fortgeschrieben, hebt die
 * Vorschuesse eines ganzen Jahres. Die Zeile bleibt trotzdem stehen, mit
 * einem Planwert von null — wer sie suchte, faende sie sonst nicht und
 * fragte sich, ob sie vergessen wurde.
 *
 * Die Begruendung ist das, was den Plan in der Versammlung traegt. Sie ist
 * freiwillig, weil eine fortgeschriebene Zahl sich selbst erklaert.
 *
 * Die Beschriftungen stehen dabei und nicht nur die Kennungen: die Freigabe
 * friert sie ein, und bis dahin soll ein umbenannter Schluessel die Zeile
 * nicht unlesbar machen.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_plan_position')]
class PlanPosition
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Plan::class, inversedBy: 'positions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Plan $plan;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $ordering;

    #[ORM\Column(name: 'line_kind', type: Types::STRING, length: 16, enumType: PlanLineKind::class)]
    private PlanLineKind $lineKind;

    /** Leer bei der Ruecklage: sie ist keine Kostenart, sondern ein Beschluss. */
    #[ORM\Column(name: 'cost_kind_id', type: Types::GUID, nullable: true)]
    private ?string $costKindId;

    #[ORM\Column(name: 'cost_kind_label', type: Types::STRING, length: 200)]
    private string $costKindLabel = '';

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $apportionable = false;

    #[ORM\Embedded(class: ChosenKey::class, columnPrefix: false)]
    private ChosenKey $key;

    #[ORM\Column(name: 'previous_amount', type: Types::BIGINT)]
    private int $previous = 0;

    /**
     * Der Jahreswert, aus dem der Vorjahreswert stammt.
     *
     * Nicht nur der Betrag, sondern die Herkunft: bei einem erfassten
     * Schluessel verteilt der Plan nach dem **Verbrauch** des Vorjahres, und
     * der haengt an genau diesem Jahreswert. Ueber die Kostenart liesse er
     * sich nicht finden — zwei Vertraege derselben Art sind zwei Positionen.
     */
    #[ORM\Column(name: 'previous_source_id', type: Types::GUID, nullable: true)]
    private ?string $previousSourceId = null;

    #[ORM\Column(name: 'planned_amount', type: Types::BIGINT)]
    private int $planned = 0;

    #[ORM\Column(name: 'is_one_off', type: Types::BOOLEAN)]
    private bool $oneOff = false;

    #[ORM\Column(type: Types::TEXT)]
    private string $reason = '';

    /**
     * Eine leere Zeile.
     *
     * Kostenart und Schluessel kommen ueber {@see reclassify()} und
     * {@see distributeBy()} dazu — beide traegt die Zeile mitsamt ihrer
     * Beschriftung, und beide aendern sich, solange der Plan ein Entwurf ist.
     * Sie in den Konstruktor zu nehmen hiesse, acht Angaben zu verlangen, von
     * denen drei doppelt vorkommen.
     */
    public function __construct(Plan $plan, int $ordering, PlanLineKind $lineKind)
    {
        $this->id = Uuid::v4();
        $this->plan = $plan;
        $this->ordering = $ordering;
        $this->lineKind = $lineKind;
        $this->costKindId = null;
        $this->key = ChosenKey::none();
        $plan->add($this);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function ordering(): int
    {
        return $this->ordering;
    }

    public function sortAt(int $ordering): void
    {
        $this->ordering = $ordering;
    }

    public function lineKind(): PlanLineKind
    {
        return $this->lineKind;
    }

    public function isReserve(): bool
    {
        return $this->lineKind->isReserve();
    }

    public function costKindId(): ?string
    {
        return $this->costKindId;
    }

    public function costKindLabel(): string
    {
        return $this->costKindLabel;
    }

    public function isApportionable(): bool
    {
        return $this->apportionable;
    }

    public function key(): ChosenKey
    {
        return $this->key;
    }

    public function distributeBy(?string $keyId, string $keyLabel, string $keyKind): void
    {
        $this->key = ChosenKey::of($keyId, $keyLabel, $keyKind);
    }

    /** Der Ist-Wert des Vorjahres — die Zahl, gegen die geplant wird. */
    public function previous(): Money
    {
        return Money::fromCents($this->previous);
    }

    public function previousSourceId(): ?string
    {
        return $this->previousSourceId;
    }

    public function had(Money $previous, ?string $sourceId = null): void
    {
        $this->previous = $previous->cents();
        $this->previousSourceId = $sourceId;
    }

    /** Ein einmaliger Posten plant sich selbst auf null. */
    public function planned(): Money
    {
        return $this->oneOff ? Money::zero() : Money::fromCents($this->planned);
    }

    /**
     * Was jemand eingetragen hat — auch wenn es einmalig ist.
     *
     * Das Formular zeigt diese Zahl, nicht die gerechnete: wer das Haekchen
     * wieder herausnimmt, soll seinen Betrag wiederfinden und nicht eine Null.
     */
    public function entered(): Money
    {
        return Money::fromCents($this->planned);
    }

    public function isOneOff(): bool
    {
        return $this->oneOff;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function plan(Money $planned, bool $oneOff, string $reason): void
    {
        $this->planned = $planned->cents();
        $this->oneOff = $oneOff;
        $this->reason = Trimmed::orNull($reason) ?? '';
    }

    /** Eine andere Kostenart — mit ihrer Beschriftung und ihrer Umlagefaehigkeit. */
    public function reclassify(?string $costKindId, string $label, bool $apportionable): void
    {
        $this->costKindId = $costKindId;
        $this->costKindLabel = $label;
        $this->apportionable = $apportionable;
    }

    /** Die Veraenderung gegenueber dem Vorjahr. */
    public function change(): Money
    {
        return $this->planned()->minus($this->previous());
    }
}
