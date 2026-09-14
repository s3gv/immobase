<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Mietverhaeltnis: wer wohnt in dieser Einheit, seit wann, zu welchem Preis.
 *
 * Weder readonly noch final, wie jede Entity: Doctrine braucht veraenderbare
 * Objekte und erzeugt Proxy-Klassen.
 *
 * Es entsteht mit einer Einheit und ist von da an *Entwurf*. Jeder weitere
 * Schritt speichert sofort; der letzte setzt es aktiv. Was die drei Zustaende
 * bedeuten, steht bei {@see TenancyStatus}.
 *
 * `unitId` ist eine blosse Kennung: die Einheiten liegen im Objektmodul, und
 * eine Doctrine-Assoziation dorthin waere die Kopplung, die die Modulgrenze
 * verhindern soll. Der Fremdschluessel in der Datenbank steht trotzdem.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tenancy')]
#[ORM\UniqueConstraint(name: 'tenancy_number', columns: ['number'])]
#[ORM\Index(name: 'tenancy_unit_idx', columns: ['unit_id'])]
class Tenancy
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Die sichtbare Nummer, ab 30001 — eigener Zahlenraum je Datenart. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $number;

    #[ORM\Column(name: 'unit_id', type: Types::GUID)]
    private string $unitId;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: TenancyStatus::class)]
    private TenancyStatus $status = TenancyStatus::Draft;

    #[ORM\Embedded(class: Term::class, columnPrefix: false)]
    private Term $term;

    #[ORM\Embedded(class: Payment::class, columnPrefix: false)]
    private Payment $payment;

    #[ORM\Embedded(class: Taxation::class, columnPrefix: false)]
    private Taxation $taxation;

    #[ORM\Embedded(class: Deposit::class, columnPrefix: false)]
    private Deposit $deposit;

    #[ORM\Column(type: Types::TEXT)]
    private string $note = '';

    /** @var Collection<int, Tenant> */
    #[ORM\OneToMany(targetEntity: Tenant::class, mappedBy: 'tenancy', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $tenants;

    /** @var Collection<int, HouseholdStep> */
    #[ORM\OneToMany(targetEntity: HouseholdStep::class, mappedBy: 'tenancy', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startsOn' => 'ASC'])]
    private Collection $households;

    /** @var Collection<int, RentStep> */
    #[ORM\OneToMany(targetEntity: RentStep::class, mappedBy: 'tenancy', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['startsOn' => 'ASC'])]
    private Collection $rents;

    public function __construct(int $number, string $unitId)
    {
        $this->id = Uuid::v4();
        $this->number = $number;
        $this->unitId = $unitId;
        $this->term = Term::unknown();
        $this->deposit = Deposit::none();
        $this->payment = Payment::usual();
        $this->taxation = Taxation::exempt();
        $this->tenants = new ArrayCollection();
        $this->households = new ArrayCollection();
        $this->rents = new ArrayCollection();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function unitId(): string
    {
        return $this->unitId;
    }

    public function moveTo(string $unitId): void
    {
        $this->unitId = $unitId;
    }

    public function status(): TenancyStatus
    {
        return $this->status;
    }

    /**
     * Das Mietende bleibt beim Aktivieren stehen: bei einem Zeitmietvertrag
     * stand es schon vorher da, und welches der beiden gemeint war, weiss
     * hier niemand.
     *
     * @throws TenancyNeedsATenant
     */
    public function activate(): void
    {
        $this->status = TenancyStatus::activeWith($this->tenants->count());
    }

    /**
     * Beenden — mit dem Tag, an dem es zu Ende war.
     *
     * @throws TenancyNeedsAnEnd
     */
    public function endOn(?DateTimeImmutable $endsOn): void
    {
        $this->term = $this->term->endingOn($endsOn);
        $this->status = TenancyStatus::Ended;
    }

    public function term(): Term
    {
        return $this->term;
    }

    public function runFor(Term $term): void
    {
        $this->term = $term;
    }

    public function household(): HouseholdSchedule
    {
        return HouseholdSchedule::of(array_values($this->households->toArray()));
    }

    public function taxation(): Taxation
    {
        return $this->taxation;
    }

    public function taxAs(Taxation $taxation): void
    {
        $this->taxation = $taxation;
    }

    public function payment(): Payment
    {
        return $this->payment;
    }

    public function paidBy(Payment $payment): void
    {
        $this->payment = $payment;
    }

    public function deposit(): Deposit
    {
        return $this->deposit;
    }

    public function secureWith(Deposit $deposit): void
    {
        $this->deposit = $deposit;
    }

    public function note(): string
    {
        return $this->note;
    }

    public function noteThat(string $note): void
    {
        $this->note = Trimmed::orNull($note) ?? '';
    }

    /** @return list<Tenant> */
    public function tenants(): array
    {
        return array_values($this->tenants->toArray());
    }

    public function schedule(): RentSchedule
    {
        return RentSchedule::of(array_values($this->rents->toArray()));
    }

    /** Was zu diesem Mietverhaeltnis gehoert, haengt sich hier an. */
    public function add(HouseholdStep|RentStep|Tenant $part): void
    {
        $this->partsLike($part)->add($part);
    }

    public function remove(HouseholdStep|RentStep|Tenant $part): void
    {
        $this->partsLike($part)->removeElement($part);
    }

    /**
     * @return Collection<int, HouseholdStep>|Collection<int, RentStep>|Collection<int, Tenant>
     */
    private function partsLike(HouseholdStep|RentStep|Tenant $part): Collection
    {
        return match (true) {
            $part instanceof RentStep => $this->rents,
            $part instanceof HouseholdStep => $this->households,
            $part instanceof Tenant => $this->tenants,
        };
    }
}
