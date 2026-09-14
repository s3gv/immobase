<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Tenancy\Contract\TenancyDirectory;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyPermissions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Was eine E-Rechnung ueber den Mieter wissen muss — im Miete-Schritt.
 *
 * Die Angaben kommen vom Mieter. Die Zusicherungen: sie werden gespeichert,
 * so wie der Mieter sie angegeben hat; was nicht stimmen kann, kommt zurueck,
 * bevor etwas gespeichert ist; und ein fremdes Modul sieht sie.
 */
final class TenancyEInvoiceTermsTest extends WebTestCase
{
    use BuildsALetProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeTheLetProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testTheTermsAreSavedAndShown(): void
    {
        $client = self::manager();
        self::buildTheLetProperty();

        self::postRent($client, [
            'buyerReference' => '991-01234-56',
            'buyerEAddress' => 'Laden-Rechnung@Example.org',
            'sepaMandate' => 'MANDAT-2026/001',
            'debtorIban' => 'DE89 3704 0044 0532 0130 00',
        ]);

        self::assertSelectorNotExists('.ib-field__error');

        $terms = self::theTenancy()->payment()->eInvoice();
        self::assertSame('991-01234-56', $terms->buyerReference());
        self::assertSame('laden-rechnung@example.org', $terms->buyerEAddress());
        self::assertSame('MANDAT-2026/001', $terms->sepaMandate());
        self::assertSame('DE89370400440532013000', $terms->debtorIban());

        $client->request('GET', '/miete/'.self::theTenancy()->number().'?abschnitt=miete');
        self::assertResponseIsSuccessful();
        // Die erste Tabelle ist die Mietstaffel; die Angaben stehen darunter.
        $shown = $client->getCrawler()->filter('.ib-table')->last()->text();
        self::assertStringContainsString('991-01234-56', $shown);
        self::assertStringContainsString('DE89 3704 0044 0532 0130 00', $shown);

        $directory = self::getContainer()->get(TenancyDirectory::class);
        self::assertInstanceOf(TenancyDirectory::class, $directory);
        $brief = $directory->brief(self::letTenancyId());
        self::assertNotNull($brief);
        self::assertSame('991-01234-56', $brief->buyerReference, 'Und die Abrechnung sieht sie');
        self::assertSame('direct_debit', $brief->paymentMethod);
    }

    /** Eine Adresse, die keine ist, kommt zurueck — und nichts wird gespeichert. */
    public function testAWrongAddressIsRefused(): void
    {
        $client = self::manager();
        self::buildTheLetProperty();

        self::postRent($client, ['buyerEAddress' => 'kein Postfach', 'buyerReference' => '991-01234-56']);

        self::assertSelectorTextContains('.ib-field__error', 'E-Mail-Adresse');
        self::assertSame('LADEN-4711', self::theTenancy()->payment()->eInvoice()->buyerReference(), 'Was vorher stand, bleibt stehen');
    }

    /** Eine Mandatsreferenz mit Zeichen, die SEPA nicht kennt, und eine falsche IBAN kommen zurueck. */
    public function testWhatSepaCannotCarryIsRefused(): void
    {
        $client = self::manager();
        self::buildTheLetProperty();

        self::postRent($client, ['sepaMandate' => 'Mandat #1 für Müller']);
        self::assertSelectorTextContains('.ib-field__error', 'Mandatsreferenz');

        self::postRent($client, ['debtorIban' => 'DE89370400440532013001']);
        self::assertSelectorTextContains('.ib-field__error', 'IBAN');

        self::assertSame('', self::theTenancy()->payment()->eInvoice()->sepaMandate());
        self::assertSame('', self::theTenancy()->payment()->eInvoice()->debtorIban());
    }

    protected static function testEmail(): string
    {
        return 'erechnung-mieter@example.org';
    }

    private static function manager(): KernelBrowser
    {
        return self::signedInWith([TenancyPermissions::VIEW, TenancyPermissions::EDIT, 'properties.view', 'parties.view']);
    }

    /**
     * @param array<string, string> $terms
     */
    private static function postRent(KernelBrowser $client, array $terms): void
    {
        $url = '/miete/'.self::theTenancy()->number().'/bearbeiten/miete';
        // Das Token des Schritt-Formulars: auf derselben Seite stehen die
        // Formulare der Mietstaffel mit eigenen Tokens.
        $token = $client->request('GET', $url)->filter('form[action$="'.$url.'"] input[name="_token"]')->first()->attr('value');

        $client->request('POST', $url, [
            'paymentMethod' => 'direct_debit',
            'paymentDue' => 'third_working_day',
            'vatCharged' => '1',
            'vatRate' => '19',
            ...$terms,
            '_token' => (string) $token,
        ]);
    }

    private static function theTenancy(): Tenancy
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->clear();

        $tenancy = self::tenancies()->byId(self::letTenancyId());
        self::assertInstanceOf(Tenancy::class, $tenancy);

        return $tenancy;
    }
}
