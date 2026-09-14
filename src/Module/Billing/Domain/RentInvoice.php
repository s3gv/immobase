<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Domain;

use App\Shared\Identity\Uuid;
use App\Shared\Text\Trimmed;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Eine Dauermietrechnung — das Schreiben zu einem Mietvertrag.
 *
 * **Sie gehoert einem Vertrag und nicht einem Objekt.** Damit ist sie das
 * einzige Schreiben in diesem Modul, das kein Lauf ueber viele Empfaenger
 * ist: sie entsteht, weil sich in *einem* Vertrag ein Bestandteil aendert,
 * und die Staffelstufen zweier Mietverhaeltnisse fallen auf verschiedene
 * Tage. Ein Objektlauf erzeugte Rechnungen fuer Vertraege, an denen sich
 * nichts geaendert hat — und zwei Rechnungen ueber denselben Zeitraum mit
 * verschiedenen Nummern sind ein umsatzsteuerliches Problem.
 *
 * **Zwei Gruende, ein zweites Schreiben auszustellen, und zwei Woerter
 * dafuer.** Eine *Folgefassung* entsteht, weil die Miete steigt: die alte
 * Rechnung war richtig und gilt bis zum Vortag. Eine *Berichtigung*
 * entsteht, weil etwas falsch war: sie ersetzt die Fassung, der Zeitraum
 * bleibt. Umsatzsteuerlich wirkt die eine ab ihrem Tag und die andere
 * zurueck; ein Wort fuer beides verwischte das an der Stelle mit dem
 * groessten Schaden.
 */
#[ORM\Entity]
#[ORM\Table(name: 'billing_rent_invoice')]
#[ORM\Index(name: 'billing_rent_invoice_tenancy', columns: ['tenancy_id'])]
class RentInvoice
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Nummer der Folgefassung, Iteration der Berichtigung, Verweis auf die berichtigte. */
    #[ORM\Embedded(class: Edition::class, columnPrefix: false)]
    private Edition $edition;

    #[ORM\Column(name: 'tenancy_id', type: Types::GUID)]
    private string $tenancyId;

    #[ORM\Column(name: 'tenancy_number', type: Types::INTEGER)]
    private int $tenancyNumber;

    /** Fuer den Objektfilter der Liste — das Mietverhaeltnis haengt an einer Einheit. */
    #[ORM\Column(name: 'property_id', type: Types::GUID)]
    private string $propertyId;

    #[ORM\Embedded(class: Validity::class, columnPrefix: false)]
    private Validity $validity;

    #[ORM\Embedded(class: Release::class, columnPrefix: false)]
    private Release $release;

    #[ORM\Embedded(class: InvoiceContents::class, columnPrefix: false)]
    private InvoiceContents $contents;

    #[ORM\Column(type: Types::STRING, length: 200)]
    private string $label = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        int $number,
        string $tenancyId,
        int $tenancyNumber,
        string $propertyId,
        DateTimeImmutable $from,
    ) {
        $this->id = Uuid::v4();
        $this->edition = Edition::first($number);
        $this->tenancyId = $tenancyId;
        $this->tenancyNumber = $tenancyNumber;
        $this->propertyId = $propertyId;
        $this->validity = Validity::openFrom($from);
        $this->release = Release::pending();
        $this->contents = InvoiceContents::nothing();
        $this->createdAt = new DateTimeImmutable();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function edition(): Edition
    {
        return $this->edition;
    }

    public function tenancyId(): string
    {
        return $this->tenancyId;
    }

    public function tenancyNumber(): int
    {
        return $this->tenancyNumber;
    }

    public function propertyId(): string
    {
        return $this->propertyId;
    }

    public function validity(): Validity
    {
        return $this->validity;
    }

    /** Ab wann sie gilt — aenderbar, solange sie Entwurf ist. */
    public function appliesFrom(DateTimeImmutable $from): void
    {
        $this->validity = $this->validity->startingOn($from);
    }

    /** Geschlossen von der Folgefassung oder vom Ende des Mietverhaeltnisses. */
    public function endsOn(DateTimeImmutable $until): void
    {
        $this->validity = $this->validity->endingOn($until);
    }

    public function release(): Release
    {
        return $this->release;
    }

    public function contents(): InvoiceContents
    {
        return $this->contents;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function describe(string $label): void
    {
        $this->label = Trimmed::orNull($label) ?? '';
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function reference(): string
    {
        return RentInvoiceReference::of($this);
    }

    /** Ausstellen: das Schreiben wird festgehalten und traegt ab jetzt sein Datum. */
    public function issueOn(DateTimeImmutable $day, ProposedInvoice $proposal): void
    {
        $this->contents = InvoiceContents::of($proposal);
        $this->release = $this->release->on($day);
    }

    /** Die berichtigte Fassung — dieselbe Geltung, eine Iteration weiter. */
    public function corrects(self $before): void
    {
        $this->edition = $this->edition->correcting($before->edition, $before->id);
        $this->validity = $before->validity;
    }
}
