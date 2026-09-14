<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Money\Money;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Das Mahnschreiben.
 *
 * Es geht an **einen** Schuldner und kommt von **einem** Glaeubiger, und es
 * buendelt alle offenen Forderungen dieser Paarung: eine Frist, ein
 * Schreiben. Drei Briefe am selben Tag an denselben Eigentuemer waeren auch
 * rechtlich unklug.
 *
 * Ohne Ausstellungstag ist es Entwurf. Danach ist es **eingefroren** — die
 * Zeilen, die Zinsen und die Betraege stehen fest, auch wenn die Forderung
 * weiterlaeuft. Ein Schreiben, das sich hinter dem Ruecken des Empfaengers
 * aendert, waere kein Beleg.
 *
 * Der Zinsstichtag ist der Ausstellungstag. Solange es Entwurf ist, rechnet
 * die Vorschau bis heute; sonst zeigte ein Entwurf von vorletzter Woche
 * Zinsen, die auf dem Blatt dann anders dastuenden.
 */
#[ORM\Entity]
#[ORM\Table(name: 'dunning_notice')]
#[ORM\Index(name: 'dunning_notice_debtor', columns: ['debtor_party_id'])]
class Notice
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Embedded(class: NoticeReference::class, columnPrefix: false)]
    private NoticeReference $reference;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: DunningLevel::class)]
    private DunningLevel $level;

    #[ORM\Column(name: 'pay_by', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $payBy;

    #[ORM\Embedded(class: Charges::class, columnPrefix: false)]
    private Charges $charges;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    #[ORM\Embedded(class: Recipients::class, columnPrefix: false)]
    private Recipients $recipients;

    #[ORM\Column(name: 'issued_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $issuedOn = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** @var Collection<int, NoticeLine> */
    #[ORM\OneToMany(
        targetEntity: NoticeLine::class,
        mappedBy: 'notice',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $lines;

    public function __construct(NoticeReference $reference, DunningLevel $level, DateTimeImmutable $payBy)
    {
        $this->id = Uuid::v4();
        $this->reference = $reference;
        $this->level = $level;
        $this->payBy = $payBy;
        $this->charges = Charges::none();
        $this->recipients = Recipients::nobody();
        $this->createdAt = new DateTimeImmutable();
        $this->lines = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function debtorPartyId(): string
    {
        return $this->reference->partyId();
    }

    public function creditor(): Creditor
    {
        return $this->reference->creditor();
    }

    /** Vollstaendig: Rolle, Objekt und — beim Vermieter — die Eigentuemer. */
    public function creditorIdentity(): CreditorIdentity
    {
        return $this->reference->creditorIdentity();
    }

    public function level(): DunningLevel
    {
        return $this->level;
    }

    public function reference(): string
    {
        return $this->reference->toString();
    }

    public function payBy(): DateTimeImmutable
    {
        return $this->payBy;
    }

    public function payUntil(DateTimeImmutable $day): void
    {
        $this->payBy = $day;
    }

    public function charges(): Charges
    {
        return $this->charges;
    }

    /** Mahnkosten und die **Entscheidung** ueber die Pauschale — nicht ihr Betrag. */
    public function charge(Money $costs, bool $wantsTheFlatFee): void
    {
        $this->charges = Charges::of($this->level, $costs, $wantsTheFlatFee);
    }

    public function chargeTheFlatFee(Money $amount): void
    {
        $this->charges = $this->charges->amounting($amount);
    }

    public function note(): string
    {
        return $this->note;
    }

    /** @internal Von {@see NoticeLine} beim Anlegen aufgerufen. */
    public function add(NoticeLine $line): void
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    /**
     * Die Zeilen verwerfen, um sie neu zu rechnen.
     *
     * Nur solange das Schreiben Entwurf ist: was ausgestellt ist, liegt beim
     * Schuldner, und seine Zeilen sind der Beleg.
     *
     * @throws NoticeIsIssued
     */
    public function clearLines(): void
    {
        if (null !== $this->issuedOn) {
            throw NoticeIsIssued::already();
        }

        $this->lines->clear();
    }

    public function remark(string $note): void
    {
        $this->note = Trimmed::orNull($note) ?? '';
    }

    public function recipients(): Recipients
    {
        return $this->recipients;
    }

    public function issuedOn(): ?DateTimeImmutable
    {
        return $this->issuedOn;
    }

    public function isDraft(): bool
    {
        return null === $this->issuedOn;
    }

    /** @return list<NoticeLine> */
    public function lines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** Die Hauptforderungen zusammen — ohne Zinsen, ohne Nebenforderungen. */
    public function amount(): Money
    {
        return NoticeLine::amountOf($this->lines());
    }

    public function interest(): Money
    {
        return NoticeLine::interestOf($this->lines());
    }

    public function total(): Money
    {
        return $this->amount()->plus($this->interest())->plus($this->charges->total());
    }

    /**
     * Ausstellen: das Schreiben wird festgehalten und bekommt sein Datum.
     *
     * @throws NoticeIsIssued
     */
    public function issueOn(DateTimeImmutable $day, Recipients $recipients): void
    {
        if (null !== $this->issuedOn) {
            throw NoticeIsIssued::already();
        }

        $this->recipients = $recipients;
        $this->issuedOn = $day;
    }
}
