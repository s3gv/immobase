<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Billing\Application\IssueRentInvoice;
use App\Module\Billing\Application\ReviseRentInvoice;
use App\Module\Billing\Application\StartRentInvoice;
use App\Module\Billing\Domain\BillingPermissions;
use App\Module\Billing\Domain\RentInvoice;
use App\Module\Tenancy\Domain\Taxation;
use App\Module\Tenancy\Domain\Tenancy;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Die Dauermietrechnung als Brief.
 *
 * Ein Empfaenger, ein Blatt — und darauf jede einzelne Pflichtangabe des
 * § 14 Abs. 4 UStG. Fehlt eine, zieht der Mieter keine Vorsteuer, und er
 * erfaehrt es erst von seinem Finanzamt. Darum steht hier jede von ihnen
 * einzeln.
 */
final class RentInvoicePdfTest extends WebTestCase
{
    use BuildsALetProperty;
    use ForgetsRateLimits;
    use ReadsPdfArchives;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheLetProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /**
     * Jede Pflichtangabe des § 14 Abs. 4 UStG steht darauf.
     *
     * Die Nummern sind die des Absatzes. Sie einzeln zu pruefen ist der
     * Zweck dieses Tests: eine Zusicherung ueber „das Blatt sieht gut aus"
     * faengt nicht, dass die Steuernummer fehlt.
     */
    public function testTheLetterCarriesEveryMandatoryItem(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $text = self::letterOf($client, self::anIssuedInvoice());

        // Nr. 1 — Name und Anschrift des leistenden Unternehmers …
        self::assertStringContainsString('Viktor Vermieter', $text);
        self::assertStringContainsString('Eigentümerallee 1', $text);
        // … und des Leistungsempfängers.
        self::assertStringContainsString('Ladenbetrieb GmbH', $text);
        self::assertStringContainsString('Geschäftsweg 7', $text);

        // Nr. 2 — Steuernummer oder USt-IdNr. des Ausstellers.
        self::assertStringContainsString('133/5711/0815', $text);

        // Nr. 3 — Ausstellungsdatum, benannt. Die Beschriftung steht mit
        // dabei: ein fehlender Uebersetzungsschluessel druckt stumm
        // „pdf.date" aufs Blatt, und niemand liest sein eigenes PDF.
        self::assertStringContainsString('Datum', $text);
        self::assertStringContainsString('02.01.2026', $text);

        // Nr. 4 — fortlaufende Rechnungsnummer, ebenso benannt.
        self::assertStringContainsString('Referenz', $text);
        self::assertStringContainsString('DM-', $text);

        // Nr. 5 — Art und Umfang der Leistung.
        self::assertStringContainsString('Ladenlokal EG', $text);
        self::assertStringContainsString('Ladenweg 5', $text);

        // Nr. 6 — Zeitpunkt der Leistung.
        self::assertStringContainsString('ab 01.01.2026', $text);

        // Nr. 7 — nach Steuersätzen aufgeschlüsseltes Entgelt.
        self::assertStringContainsString('Nettomiete', $text);
        self::assertStringContainsString('1.800,00', $text);
        self::assertStringContainsString('2.300,00', $text);

        // Nr. 8 — Steuersatz und Steuerbetrag.
        self::assertStringContainsString('19,00 %', $text);
        self::assertStringContainsString('437,00', $text);
        self::assertStringContainsString('2.737,00', $text);
    }

    /** Der Aussteller ist der Vermieter — der Briefkopf ist nur der Absender. */
    public function testTheIssuerIsTheLandlordAndNotTheManagement(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $text = self::letterOf($client, self::anIssuedInvoice());

        self::assertStringContainsString('Rechnungsaussteller', $text);
        self::assertStringContainsString('Steuernummer', $text);
    }

    /** Zahlungsempfaenger und Konto stehen darauf — sonst weiss niemand, wohin. */
    public function testTheLetterSaysWhereToPay(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $text = self::letterOf($client, self::anIssuedInvoice());

        self::assertStringContainsString('Mietkonto Ladenweg', $text);
        self::assertStringContainsString('DE02 1203 0000 0000 2020 51', $text);
    }

    /** Und die Zeile, auf der der Vermieter unterschreibt. */
    public function testTheLetterLeavesRoomForASignature(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $text = self::letterOf($client, self::anIssuedInvoice());

        self::assertStringContainsString('Unterschrift des Vermieters', $text);
        self::assertStringContainsString('Bestandteil des oben genannten Mietvertrags', $text);
    }

    /**
     * Ohne Option steht keine Steuer darauf — auch keine von null.
     *
     * § 14c UStG kennt keinen Unterschied zwischen „ausgewiesen" und
     * „ausgewiesen, aber null": wer Steuer ausweist, schuldet sie.
     */
    public function testAnExemptLettingShowsNoTaxAtAll(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheLetProperty();
        self::theLettingIsExempt();
        $text = self::letterOf($client, self::anIssuedInvoice(build: false));

        // Kein Steuerausweis heisst: das Wort steht nirgends auf dem Blatt.
        // „Umsatzsteuer 0,00 €" waere ein Ausweis ueber nichts — und damit
        // einer.
        self::assertStringNotContainsString('Umsatzsteuer', $text);
        self::assertStringNotContainsString('Nettobetrag', $text, 'Ohne Steuer gibt es kein Netto daneben');
        self::assertStringNotContainsString('§ 9 UStG', $text, 'Und keine Option, die niemand erklaert hat');
        self::assertStringContainsString('2.300,00', $text, 'Der Bruttobetrag ist der Nettobetrag');
    }

    /** Die Folgefassung traegt ihren eigenen Zeitraum — und die vorige ein Ende. */
    public function testTheSuccessorCarriesItsOwnPeriod(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheLetProperty();
        $first = self::anIssuedInvoice(build: false);
        self::alsoRaisesTheRent('2027-07-01');

        $revise = self::getContainer()->get(ReviseRentInvoice::class);
        self::assertInstanceOf(ReviseRentInvoice::class, $revise);
        $next = $revise->succeed($first, new DateTimeImmutable('2027-07-01'));
        self::issue()->issue($next, new DateTimeImmutable('2027-06-15'));

        self::assertStringContainsString('ab 01.07.2027', self::letterOf($client, $next));
        self::assertStringContainsString('vom 01.01.2026 bis 30.06.2027', self::letterOf($client, $first));
    }

    /**
     * Zweimal geholt — Byte fuer Byte dasselbe.
     *
     * Eine Rechnung ist ein Beleg. Zwei Ausdrucke derselben Rechnung, die
     * sich unterscheiden, waeren zwei Belege.
     */
    public function testTheSameInvoiceProducesTheSameBytes(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $invoice = self::anIssuedInvoice();

        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/pdf');
        $first = (string) $client->getResponse()->getContent();
        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/pdf');

        self::assertSame($first, (string) $client->getResponse()->getContent());
    }

    /** Aus einem Entwurf entsteht kein Blatt: er hat keine Nummer, die gilt. */
    public function testADraftHasNoLetter(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        self::buildTheLetProperty();
        $draft = self::aDraft();

        $client->request('GET', '/billing/dauermietrechnungen/'.$draft->id().'/pdf');

        self::assertResponseStatusCodeSame(404);
    }

    /** Und ohne Leserecht auch nicht. */
    public function testTheLetterNeedsTheViewPermission(): void
    {
        $client = self::signedInWith([]);
        self::buildTheLetProperty();
        $invoice = self::anIssuedInvoice(build: false);

        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    /** Der Dateiname ist die Rechnungsnummer — so liegt sie im Ordner richtig. */
    public function testTheFileIsNamedAfterTheInvoice(): void
    {
        $client = self::signedInWith([BillingPermissions::VIEW]);
        $invoice = self::anIssuedInvoice();

        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/pdf');

        self::assertSame('application/pdf', $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString(
            $invoice->reference().'.pdf',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    protected static function testEmail(): string
    {
        return 'mietrechnungbrief@example.org';
    }

    /** Das Blatt, gelesen wie der Empfaenger es liest. */
    private static function letterOf(KernelBrowser $client, RentInvoice $invoice): string
    {
        $client->request('GET', '/billing/dauermietrechnungen/'.$invoice->id().'/pdf');
        self::assertResponseIsSuccessful();

        return self::readable((string) $client->getResponse()->getContent());
    }

    private static function anIssuedInvoice(bool $build = true): RentInvoice
    {
        if ($build) {
            self::buildTheLetProperty();
        }

        $invoice = self::aDraft();
        self::issue()->issue($invoice, new DateTimeImmutable('2026-01-02'));

        return $invoice;
    }

    private static function aDraft(): RentInvoice
    {
        $start = self::getContainer()->get(StartRentInvoice::class);
        self::assertInstanceOf(StartRentInvoice::class, $start);
        $invoice = $start->forTenancy(
            self::letTenancyId(),
            new DateTimeImmutable('2026-01-01'),
            'Dauermietrechnung ab 2026',
        );
        self::assertInstanceOf(RentInvoice::class, $invoice);

        return $invoice;
    }

    private static function issue(): IssueRentInvoice
    {
        $issue = self::getContainer()->get(IssueRentInvoice::class);
        self::assertInstanceOf(IssueRentInvoice::class, $issue);

        return $issue;
    }

    private static function theLettingIsExempt(): void
    {
        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);
        $tenancy->taxAs(Taxation::exempt());
        self::tenancies()->save($tenancy);
    }
}
