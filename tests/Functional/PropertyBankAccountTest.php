<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Property\Domain\ManagementMode;
use App\Module\Property\Domain\Property;
use App\Module\Property\Domain\PropertyFilter;
use App\Module\Property\Domain\PropertyPermissions;
use App\Module\Property\Domain\PropertyRepository;
use App\Module\Property\UserInterface\Controller\PropertyFlow;
use App\Shared\Ui\Page;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Das Konto, ueber das ein Objekt laeuft.
 *
 * Ein eigener Schritt hinter dem Wirtschaftsjahr — die beiden haben
 * miteinander nichts zu tun ausser dem Anlass, aus dem man sie braucht.
 */
final class PropertyBankAccountTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;

    private const string NAME = 'Kontoobjekt Bankweg';

    /** Echte Pruefziffer, sonst waere der Test seine eigene Zusicherung los. */
    private const string IBAN = 'DE02120300000000202051';

    protected function tearDown(): void
    {
        self::removeProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    public function testTheStepStandsBehindTheFiscalYear(): void
    {
        $client = self::manager();
        $number = self::start($client);

        $client->request('GET', '/objekte/'.$number.'/bearbeiten/abrechnung');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ib-flow__steps', 'Bankkonto');

        $steps = PropertyFlow::keys(self::found());
        $at = array_search(PropertyFlow::ACCOUNTING, $steps, true);

        self::assertIsInt($at);
        self::assertSame(PropertyFlow::BANK, $steps[$at + 1] ?? null, 'Direkt hinter dem Wirtschaftsjahr');
    }

    public function testAnAccountIsSavedAndShown(): void
    {
        $client = self::manager();
        $number = self::start($client);

        self::post($client, $number, [
            'iban' => 'DE02 1203 0000 0000 2020 51',
            'bic' => 'bylavodi',
            'holder' => '  WEG Bankweg 7  ',
            'label' => 'Hausgeldkonto',
        ]);

        $account = self::found()->accounting()->account();

        self::assertSame(self::IBAN, $account->iban(), 'Ohne Leerzeichen gespeichert');
        self::assertSame('BYLAVODI', $account->bic(), 'In Großbuchstaben');
        self::assertSame('WEG Bankweg 7', $account->holder());
        self::assertTrue($account->isKnown());

        $client->request('GET', '/objekte/'.$number.'?abschnitt=bankkonto');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ib-table', 'DE02 1203 0000 0000 2020 51');
        self::assertSelectorTextContains('.ib-table', 'WEG Bankweg 7');
        self::assertSelectorExists('a[href$="/bearbeiten/bankkonto"]', 'Von hier aus wird es geändert');
    }

    /** Ein Zahlendreher kommt zurueck, und zwar bevor etwas gespeichert ist. */
    public function testAWrongIbanIsRefusedAndNothingIsSaved(): void
    {
        $client = self::manager();
        $number = self::start($client);

        self::post($client, $number, [
            'iban' => 'DE02120300000000202015',
            'bic' => 'BYLADEM1001',
            'holder' => 'WEG Bankweg 7',
        ]);

        self::assertSelectorExists('.ib-field__error');

        $account = self::found()->accounting()->account();

        self::assertSame('', $account->iban());
        self::assertSame('', $account->bic(), 'Auch die gute BIC bleibt draußen');
        self::assertSame('', $account->holder());
    }

    public function testAWrongBicIsRefused(): void
    {
        $client = self::manager();
        $number = self::start($client);

        self::post($client, $number, ['iban' => self::IBAN, 'bic' => 'XX']);

        self::assertSelectorExists('.ib-field__error');
        self::assertSame('', self::found()->accounting()->account()->iban());
    }

    /** Die Glaeubiger-ID steht beim Konto — und eine falsche kommt zurueck, bevor etwas gespeichert ist. */
    public function testTheCreditorIdIsCheckedAndShown(): void
    {
        $client = self::manager();
        $number = self::start($client);

        self::post($client, $number, ['iban' => self::IBAN, 'creditorId' => 'DE98ZZZ09999999998']);

        self::assertSelectorTextContains('.ib-field__error', 'Gläubiger-ID');
        self::assertSame('', self::found()->accounting()->account()->iban(), 'Die gute IBAN bleibt ebenfalls draußen');

        self::post($client, $number, ['iban' => self::IBAN, 'creditorId' => 'de98 zzz0 9999 9999 99']);

        self::assertSelectorNotExists('.ib-field__error');
        self::assertSame('DE98ZZZ09999999999', self::found()->accounting()->account()->creditorId());

        $client->request('GET', '/objekte/'.$number.'?abschnitt=bankkonto');
        self::assertSelectorTextContains('.ib-table', 'DE98ZZZ09999999999');
    }

    /** Ein Objekt entsteht lange bevor sein Konto feststeht. */
    public function testAnEmptyFormIsAllowed(): void
    {
        $client = self::manager();
        $number = self::start($client);

        self::post($client, $number, ['iban' => '', 'bic' => '', 'holder' => '', 'label' => '']);

        self::assertSelectorNotExists('.ib-field__error');
        self::assertFalse(self::found()->accounting()->account()->isKnown());

        $client->request('GET', '/objekte/'.$number.'?abschnitt=bankkonto');
        self::assertSelectorTextContains('.ib-field__hint', 'noch kein Konto');
    }

    /** Das Wirtschaftsjahr bleibt stehen, wenn das Konto geschrieben wird. */
    public function testTheFiscalYearIsNotTouched(): void
    {
        $client = self::manager();
        $number = self::start($client);

        self::post($client, $number, ['iban' => self::IBAN], step: 'abrechnung', fields: [
            'fiscalYearDay' => '1', 'fiscalYearMonth' => '7',
        ]);
        self::post($client, $number, ['iban' => self::IBAN, 'holder' => 'WEG Bankweg 7']);

        $accounting = self::found()->accounting();

        self::assertSame(7, $accounting->fiscalYear()->month());
        self::assertSame(1, $accounting->fiscalYear()->day());
        self::assertSame(self::IBAN, $accounting->account()->iban());
    }

    /** Und umgekehrt: das Wirtschaftsjahr zu aendern laesst das Konto stehen. */
    public function testTheAccountIsNotTouchedByTheFiscalYear(): void
    {
        $client = self::manager();
        $number = self::start($client);

        self::post($client, $number, ['iban' => self::IBAN, 'holder' => 'WEG Bankweg 7']);
        self::post($client, $number, [], step: 'abrechnung', fields: [
            'fiscalYearDay' => '1', 'fiscalYearMonth' => '4',
        ]);

        $accounting = self::found()->accounting();

        self::assertSame(4, $accounting->fiscalYear()->month());
        self::assertSame(self::IBAN, $accounting->account()->iban());
        self::assertSame('WEG Bankweg 7', $accounting->account()->holder());
    }

    protected static function testEmail(): string
    {
        return 'konto@example.org';
    }

    /**
     * @param array<string, string> $account
     * @param array<string, string> $fields
     */
    private static function post(
        KernelBrowser $client,
        int $number,
        array $account,
        string $step = 'bankkonto',
        array $fields = [],
    ): void {
        $url = '/objekte/'.$number.'/bearbeiten/'.$step;
        $client->request('POST', $url, [
            ...('bankkonto' === $step ? $account : $fields),
            '_token' => self::tokenFrom($client, $url),
        ]);
    }

    private static function start(KernelBrowser $client): int
    {
        $url = '/objekte/neu';
        $client->request('POST', $url, [
            '_token' => self::tokenFrom($client, $url),
            'name' => self::NAME,
            'modes' => [ManagementMode::Weg->value],
        ]);

        return self::found()->number();
    }

    private static function tokenFrom(KernelBrowser $client, string $url): string
    {
        $field = $client->request('GET', $url)->filter('main input[name="_token"]');
        self::assertGreaterThan(0, $field->count(), 'Kein Formular auf '.$url);

        return (string) $field->first()->attr('value');
    }

    private static function manager(): KernelBrowser
    {
        return self::signedInWith([PropertyPermissions::VIEW, PropertyPermissions::EDIT]);
    }

    private static function found(): Property
    {
        self::entityManager()->clear();

        $properties = self::getContainer()->get(PropertyRepository::class);
        self::assertInstanceOf(PropertyRepository::class, $properties);

        foreach ($properties->matching(PropertyFilter::none(), Page::of(1, 100)) as $property) {
            if (self::NAME === $property->name()) {
                return $property;
            }
        }

        self::fail('Das Objekt wurde nicht angelegt.');
    }

    private static function removeProperty(): void
    {
        if (null === self::$kernel) {
            return;
        }

        self::entityManager()->getConnection()->executeStatement(
            'DELETE FROM property WHERE name = ?',
            [self::NAME],
        );
    }

    private static function entityManager(): EntityManagerInterface
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
