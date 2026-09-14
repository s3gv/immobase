<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Dunning\Application\ComposeNotice;
use App\Module\Dunning\Application\IssueNotice;
use App\Module\Dunning\Application\OverdueItem;
use App\Module\Dunning\Application\SettleClaim;
use App\Module\Dunning\Application\StartClaim;
use App\Module\Dunning\Application\SurveyClaims;
use App\Module\Dunning\Application\SurveyOverdue;
use App\Module\Dunning\Application\TheCreditor;
use App\Module\Dunning\Application\TheInterest;
use App\Module\Dunning\Contract\DunningOverview;
use App\Module\Dunning\Contract\DunningPressure;
use App\Module\Dunning\Domain\BaseRate;
use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\Creditor;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Dunning\Domain\CreditorsDoNotMatch;
use App\Module\Dunning\Domain\DunningLevel;
use App\Module\Dunning\Domain\LevelOutOfOrder;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeIsIncomplete;
use App\Module\Dunning\Domain\NoticeIsIssued;
use App\Module\Dunning\Domain\NoticeReference;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Module\Dunning\UserInterface\Controller\DebtorOfAUnit;
use App\Module\Finance\Domain\AdvanceKind;
use App\Module\Party\Domain\Addresses;
use App\Module\Party\Domain\AddressKind;
use App\Module\Party\Domain\ContactDetails;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyFilter;
use App\Module\Party\Domain\PartyKind;
use App\Module\Party\Domain\PartyRole;
use App\Module\Party\Domain\PartyRoles;
use App\Module\Party\Domain\PostalAddress;
use App\Module\Property\Domain\BankAccount;
use App\Module\Property\Domain\Holding;
use App\Module\Property\Domain\Mea;
use App\Module\Property\Domain\UnitOwner;
use App\Shared\Bank\Bic;
use App\Shared\Bank\Iban;
use App\Shared\Contact\Email;
use App\Shared\Money\Money;
use App\Shared\Ui\Page;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Das Mahnwesen.
 *
 * Die Zusicherung, an der alles haengt: **was gemahnt wird, ist wirklich
 * offen.** Eine Mahnung, die mehr fordert, als der Schuldner schuldet, ist
 * ein Rechtsproblem und keine Unschaerfe.
 *
 * Die zweite: **die Stufen folgen aufeinander.** Eine letzte Mahnung ohne
 * vorausgegangene erste ist keine letzte, und der Empfaenger liest jeden
 * Rueckschritt zu Recht als Nachgeben.
 */
final class DunningTest extends WebTestCase
{
    use BuildsABillableProperty;

    /**
     * Der Basiszinssatz nach § 247 BGB, wie ihn die Migration vorbelegt.
     *
     * Er wird vor jedem Test neu gesetzt: die Tests teilen sich eine
     * Datenbank, und einer von ihnen raeumt die Reihe absichtlich ab. Ohne
     * das Zuruecksetzen haenge das Ergebnis der uebrigen davon ab, in
     * welcher Reihenfolge sie laufen — und das ist der unangenehmste
     * Fehlschlag, den es gibt.
     */
    private const array BASE_RATES = [
        '2016-01-01' => -83, '2016-07-01' => -88, '2023-01-01' => 162, '2023-07-01' => 312,
        '2024-01-01' => 362, '2024-07-01' => 337, '2025-01-01' => 227, '2025-07-01' => 127,
        '2026-07-01' => 152,
    ];

    protected function setUp(): void
    {
        self::bootKernel();
        self::theBaseRates();
        self::buildTheProperty();
    }

    protected function tearDown(): void
    {
        self::removeTheProperty();

        parent::tearDown();
    }

    /**
     * Der Verzug beginnt am Tag nach der Faelligkeit.
     *
     * Nicht am Tag der Mahnung: das Hausgeld ist kalendermaessig faellig,
     * und dann tritt Verzug ohne Mahnung ein (§ 286 Abs. 2 Nr. 1 BGB). Die
     * Mahnung dokumentiert ihn, sie begruendet ihn nicht.
     */
    public function testDefaultBeginsTheDayAfterTheDueDate(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();

        self::assertSame('2025-12-01', $claim->arrears()->dueOn()->format('Y-m-d'));
        self::assertSame('2025-12-02', $claim->arrears()->beginsOn()->format('Y-m-d'));
    }

    /**
     * Am Faelligkeitstag ist noch nichts ueberfaellig.
     *
     * An ihm laeuft die Frist den ganzen Tag; wer abends zahlt, hat gezahlt.
     * Stuende die Zahlung schon in der Tafel, entstuende daraus eine
     * Forderung mit einem Verzug, den es noch nicht gibt — und Zinsen, die
     * niemand schuldet.
     */
    public function testNothingIsOverdueOnTheDayItFallsDue(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);

        self::assertSame([], self::overdue()->on(new DateTimeImmutable('2025-12-01')), 'Der Faelligkeitstag');
        self::assertCount(1, self::overdue()->on(new DateTimeImmutable('2025-12-02')), 'Der Tag danach');
    }

    /**
     * Eingetragene Forderungen gibt es beliebig viele.
     *
     * Sie haben keine Kennung, und ein eindeutiger Index ueber Herkunft und
     * Kennung liesse mit einem leeren Text genau **eine** davon zu — auf der
     * ganzen Installation. Null ist in einem eindeutigen Index jedes Mal ein
     * anderes Nichts.
     */
    public function testManyClaimsCanBeEnteredByHand(): void
    {
        self::anEnteredClaim(Creditor::Community, Money::fromCents(180000));
        self::anEnteredClaim(Creditor::Community, Money::fromCents(180000));
        self::anEnteredClaim(Creditor::Community, Money::fromCents(180000));

        self::assertCount(3, self::claims()->allOpen());
    }

    /** Dieselbe Zahlung wird nicht zweimal zur Forderung. */
    public function testAPaymentBecomesAClaimOnlyOnce(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $item = self::anOverdueItem();

        $once = self::start()->fromTheOverdue($item);
        $again = self::start()->fromTheOverdue($item);

        self::assertSame($once->id(), $again->id());
        self::assertCount(1, self::claims()->allOpen());
    }

    /** Eine Forderung mit Vorgang steht nicht mehr in der Tafel der Ueberfaelligen. */
    public function testATrackedPaymentLeavesTheOverdueList(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        self::assertCount(1, self::overdue()->on(self::today()));

        self::aClaim();

        self::assertSame([], self::overdue()->on(self::today()));
    }

    /**
     * Ein Schreiben buendelt nur Forderungen eines Glaeubigers.
     *
     * Hausgeld schuldet der Eigentuemer der Gemeinschaft, die
     * Nebenkostenvorauszahlung schuldet der Mieter seinem Vermieter. Beides
     * in einem Brief waere eine Forderung aus zwei Haenden.
     */
    public function testOneNoticeCarriesOnlyOneCreditor(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $community = self::aClaim();
        $owner = self::anEnteredClaim(Creditor::Owner, Money::fromCents(50000));

        $this->expectException(CreditorsDoNotMatch::class);
        self::compose()->cover(self::aDraftFor($community), [$community, $owner], self::today());
    }

    /** Die Zahlungserinnerung traegt nie Mahnkosten. */
    public function testThePaymentReminderNeverCarriesCosts(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $notice = self::aDraftFor(self::aClaim());

        $notice->charge(Money::fromCents(250), false);

        self::assertSame(DunningLevel::Reminder, $notice->level());
        self::assertSame(0, $notice->charges()->costs()->cents());
    }

    /** Die naechsten Stufen duerfen welche tragen. */
    public function testTheLaterLevelsMayCarryCosts(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        self::issued(self::aDraftFor($claim));
        $second = self::aDraftFor($claim);

        $second->charge(Money::fromCents(250), false);

        self::assertSame(DunningLevel::First, $second->level());
        self::assertSame(250, $second->charges()->costs()->cents());
    }

    /** Die Stufen folgen aufeinander; uebersprungen wird keine. */
    public function testTheLevelsFollowEachOther(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        $first = self::aDraftFor($claim);
        $second = self::aDraftFor($claim);

        self::assertSame($first->id(), $second->id(), 'Zwei offene Entwuerfe gibt es nicht');

        self::issued($first);
        self::assertSame(DunningLevel::First, self::nextLevelFor($claim));
    }

    /**
     * Ein ausgestelltes Schreiben aendert sich nicht mehr.
     *
     * Die Zinsen laufen weiter — das Blatt liegt beim Schuldner, und beim
     * naechsten Aufruf muessen dieselben Zahlen dastehen.
     */
    public function testAnIssuedNoticeDoesNotChange(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $notice = self::issued(self::aDraftFor(self::aClaim()));
        $before = $notice->total()->cents();

        try {
            self::compose()->refresh(self::reloaded($notice), self::today()->modify('+90 days'));
            self::fail('Ein ausgestelltes Schreiben laesst sich nicht neu rechnen.');
        } catch (NoticeIsIssued) {
            // Genau das ist die Zusicherung.
        }

        self::assertSame($before, self::reloaded($notice)->total()->cents());
    }

    /** Und der Zinsstichtag ist der Ausstellungstag, nicht der von heute. */
    public function testTheInterestStopsAtTheDayOfIssue(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        $notice = self::issued(self::aDraftFor($claim), self::today());

        $later = self::interest()->of(self::reloadedClaim($claim), self::today()->modify('+90 days'));

        self::assertGreaterThan($notice->interest()->cents(), $later->total()->cents());
        self::assertSame($notice->interest()->cents(), self::reloaded($notice)->interest()->cents());
    }

    /**
     * Fehlt der Basiszinssatz fuers laufende Halbjahr, haelt das die
     * Ausstellung auf.
     *
     * Ohne ihn rechnete die Anwendung mit einem veralteten Satz weiter. Das
     * faellt nicht auf — es faellt vor Gericht auf.
     */
    public function testAMissingBaseRateStopsTheIssue(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $notice = self::aDraftFor(self::aClaim());
        self::theRatesEndIn(2020);

        $this->expectException(NoticeIsIncomplete::class);
        self::issue()->issue($notice, self::today());
    }

    /**
     * Zwei Objekte sind zwei Glaeubiger — und darum zwei Schreiben.
     *
     * „Die Gemeinschaft" ist keine Antwort, sondern „die Gemeinschaft
     * Pruefweg 3". Wer bei zweien im Rueckstand ist, schuldet zwei
     * verschiedenen Leuten: ein gemeinsames Schreiben traege die Anschrift
     * und das Konto des einen und forderte das Geld des anderen mit ein.
     */
    public function testTwoPropertiesAreTwoCreditors(): void
    {
        $here = self::anEnteredClaim(Creditor::Community, Money::fromCents(50000));
        $elsewhere = self::anEnteredClaimElsewhere();

        self::assertCount(1, self::openFor($here), 'Jedes Objekt fuer sich');
        self::assertCount(1, self::openFor($elsewhere));

        $ours = self::aDraftFor($here);
        self::assertNotSame($ours->id(), self::aDraftFor($elsewhere)->id(), 'Zwei Entwuerfe');

        $this->expectException(CreditorsDoNotMatch::class);
        self::compose()->cover($ours, [$here, $elsewhere], self::today());
    }

    /**
     * Zwei Vermieter in einem Haus sind auch zwei Glaeubiger.
     *
     * Das Objekt allein reicht nicht: wer zwei Wohnungen im selben Haus von
     * zwei Eigentuemern mietet, schuldet zwei Leuten. Ein gemeinsames
     * Schreiben traege die Anschrift und das Konto des einen und forderte
     * das Geld des anderen mit ein — und der erste Glaeubiger bekaeme eine
     * Aufstellung, in der Betraege stehen, die ihm nicht zustehen.
     */
    public function testTwoLandlordsInOneHouseAreTwoCreditors(): void
    {
        self::aSecondOwnerForTheSecondUnit();

        $mine = self::anEnteredClaim(Creditor::Owner, Money::fromCents(50000));
        $hers = self::anEnteredClaim(Creditor::Owner, Money::fromCents(70000), 1);

        self::assertNotSame(
            $mine->source()->creditorIdentity()->key(),
            $hers->source()->creditorIdentity()->key(),
            'Dasselbe Objekt, verschiedene Eigentuemer',
        );
        self::assertCount(1, self::openFor($mine), 'Jeder Vermieter fuer sich');
        self::assertCount(1, self::openFor($hers));

        $ours = self::aDraftFor($mine);
        self::assertNotSame($ours->id(), self::aDraftFor($hers)->id(), 'Zwei Entwuerfe');

        $this->expectException(CreditorsDoNotMatch::class);
        self::compose()->cover($ours, [$mine, $hers], self::today());
    }

    /**
     * Der Schuldner ist der, der damals dort stand.
     *
     * Eine nachgetragene Maerzforderung schuldet der, dem die Wohnung im
     * Maerz gehoerte — nicht der, dem sie heute gehoert. Wer verkauft hat,
     * nimmt den Rueckstand mit; wer gekauft hat, erbt ihn nicht.
     */
    public function testTheDebtorIsWhoeverStoodThereThen(): void
    {
        $after = self::theSecondUnitChangedHandsOn('2026-04-01');
        $unitId = self::unitIds()[1] ?? '';

        $inMarch = self::debtorsOfAUnit()->of($unitId, Creditor::Community, new DateTimeImmutable('2026-03-03'));
        $inApril = self::debtorsOfAUnit()->of($unitId, Creditor::Community, new DateTimeImmutable('2026-04-03'));

        self::assertSame(self::anOwnerPartyId(), $inMarch['partyId'] ?? null, 'Der Verkaeufer');
        self::assertSame($after->id(), $inApril['partyId'] ?? null, 'Der Kaeufer');
    }

    /**
     * Und der Brief nennt den Vermieter, fuer den er zusammengestellt wurde.
     *
     * Buendelung und Briefkopf lesen dieselbe Angabe — sonst koennte auf dem
     * Blatt ein anderer stehen als der, dessen Forderungen darauf stehen.
     */
    public function testTheLetterNamesTheLandlordItWasBundledFor(): void
    {
        $second = self::aSecondOwnerForTheSecondUnit();

        $hers = self::anEnteredClaim(Creditor::Owner, Money::fromCents(70000), 1);
        $notice = self::issued(self::aDraftFor($hers));

        self::assertSame($second->displayName(), $notice->recipients()->creditorName());
    }

    /**
     * Und die Stufen zaehlen je Objekt.
     *
     * Sonst machte eine Zahlungserinnerung fuer das eine Objekt aus dem
     * naechsten Schreiben fuers andere eine erste Mahnung — eine Stufe, die
     * der Empfaenger dort nie bekommen hat.
     */
    public function testTheLevelsCountPerProperty(): void
    {
        $here = self::anEnteredClaim(Creditor::Community, Money::fromCents(50000));
        $elsewhere = self::anEnteredClaimElsewhere();

        self::issued(self::aDraftFor($here));

        self::assertSame(DunningLevel::First, self::nextLevelFor($here));
        self::assertSame(DunningLevel::Reminder, self::nextLevelFor($elsewhere), 'Das andere Objekt faengt an');
    }

    /**
     * Was inzwischen bezahlt ist, faellt aus dem Entwurf heraus.
     *
     * Der Abgleich mit den Finanzen erledigt die Forderung; bliebe ihre
     * Zeile ueber 0,00 € stehen, liesse sich ein bereits bezahlter Entwurf
     * als leeres Mahnschreiben ausstellen.
     */
    public function testAClaimPaidInTheMeantimeLeavesTheDraft(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $notice = self::aDraftFor(self::aClaim());
        self::assertCount(1, $notice->lines());

        self::theAdvanceArrived();
        self::compose()->refresh($notice, self::today());

        self::assertSame([], $notice->lines(), 'Keine Zeile ueber nichts');
        self::assertSame([], self::claims()->allOpen(), 'Die Forderung ist erledigt');

        $this->expectException(NoticeIsIncomplete::class);
        self::issue()->issue($notice, self::today());
    }

    /**
     * Die Pauschale bekommt kein Verbraucher — auch nicht ueber das Formular.
     *
     * § 288 Abs. 5 BGB gibt sie gegen einen Nicht-Verbraucher. Stuende der
     * Betrag im Formular, berechnete ein umgebogener POST vierzig Euro, die
     * niemand schuldet; die Zulaessigkeit entscheidet darum die Anwendung.
     */
    public function testTheFlatFeeIsRefusedAgainstAConsumer(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $notice = self::aDraftFor(self::aClaim());

        self::compose()->charge($notice, Money::zero(), true);

        self::assertTrue($notice->charges()->flatFeeWanted(), 'Gewollt war sie');
        self::assertSame(0, $notice->charges()->flatFee()->cents(), 'Zustehen tut sie nicht');
    }

    /**
     * Und sie zaehlt jede Forderung, die sie traegt — nicht eine.
     *
     * Es gibt sie einmal **je Forderung**. Bliebe der Vermerk an einer
     * haengen, stuenden die uebrigen beim naechsten Schreiben wieder zur
     * Wahl und waeren ein zweites Mal berechnet.
     */
    public function testTheFlatFeeCountsEveryClaimThatBearsIt(): void
    {
        $first = self::anEnteredClaim(Creditor::Community, Money::fromCents(50000));
        $second = self::anEnteredClaim(Creditor::Community, Money::fromCents(70000));

        foreach ([$first, $second] as $claim) {
            $claim->tradeAs(true);
            self::claims()->save($claim);
        }

        $notice = self::aDraftFor($first);
        self::compose()->charge($notice, Money::zero(), true);

        self::assertSame(8000, $notice->charges()->flatFee()->cents(), 'Zwei Forderungen, zwei Pauschalen');

        self::issued($notice);
        self::assertTrue(self::reloadedClaim($first)->debtor()->flatFeeClaimed());
        self::assertTrue(self::reloadedClaim($second)->debtor()->flatFeeClaimed(), 'Auch die zweite');
    }

    /**
     * Das ausgestellte Schreiben behaelt das Konto, das darauf stand.
     *
     * Wechselt das Objekt danach die Bank, zeigte ein nachtraeglich
     * gelesenes Konto eine Zahlungsanweisung, die so nie hinausging — und wer
     * auf das alte Konto ueberwies, haette nach diesem Beleg an die falsche
     * Stelle gezahlt.
     */
    public function testTheIssuedLetterKeepsTheAccountItNamed(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $notice = self::issued(self::aDraftFor(self::aClaim()));
        $named = $notice->recipients()->payeeIban();
        self::assertNotSame('', $named);

        self::theBankChanges('DE68210501700012345678');

        self::assertSame($named, self::reloaded($notice)->recipients()->payeeIban());
    }

    /** Was erledigt ist, bekommt kein Schreiben mehr. */
    public function testASettledClaimIsNotDunnedAgain(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        self::settle()->settle($claim, self::today());

        self::assertSame([], self::openFor($claim));
    }

    /**
     * Der offene Betrag wird beim Schreiben neu aus den Finanzen gelesen.
     *
     * Eine Mahnung, die mehr fordert als offen ist, ist ein Rechtsproblem.
     */
    public function testTheAmountIsRereadFromTheFinances(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        self::assertSame(30000, $claim->open()->cents());

        self::alsoPaidAPart(Money::fromCents(10000));
        $notice = self::aDraftFor($claim);
        self::compose()->refresh($notice, self::today());

        self::assertSame(20000, $notice->amount()->cents(), 'Nur der Rest');
    }

    /** Ist gar nichts mehr offen, endet der Vorgang von selbst. */
    public function testAFullyPaidClaimSettlesItself(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        self::theAdvanceIsSettled();

        self::compose()->refresh(self::aDraftFor($claim), self::today());

        self::assertFalse(self::reloadedClaim($claim)->arrears()->isOpen());
    }

    /**
     * Die Pauschale nach § 288 Abs. 5 BGB gibt es einmal je Forderung.
     *
     * Und nur gegen Nicht-Verbraucher. Sie wird an der Forderung vermerkt
     * und nicht am Schreiben — sonst stuende sie beim naechsten Schreiben
     * wieder da, und der Schuldner zahlte sie zweimal.
     */
    public function testTheFlatFeeIsClaimedOncePerClaim(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        $claim->tradeAs(true);
        self::claims()->save($claim);

        $first = self::aDraftFor($claim);
        self::compose()->charge($first, Money::zero(), true);
        self::issued($first);

        self::assertSame(4000, self::reloaded($first)->charges()->flatFee()->cents());
        self::assertTrue(self::reloadedClaim($claim)->debtor()->flatFeeClaimed(), 'Einmal angesetzt');
    }

    /** Bei einem Verbraucher steht sie gar nicht erst zur Wahl. */
    public function testAConsumerNeverOwesTheFlatFee(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();

        self::assertFalse($claim->debtor()->isCommercial(), 'Eine natuerliche Person, wie vorbelegt');
        self::assertFalse($claim->debtor()->flatFeeClaimed());
    }

    /** Ein gewerblicher Schuldner schuldet neun Punkte, ein Verbraucher fuenf. */
    public function testTheCommercialDebtorOwesNinePoints(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();
        $asConsumer = self::interest()->of($claim, self::today())->total()->cents();

        $claim->tradeAs(true);
        self::claims()->save($claim);
        $asCompany = self::interest()->of(self::reloadedClaim($claim), self::today())->total()->cents();

        self::assertGreaterThan($asConsumer, $asCompany);
    }

    /**
     * Das Abzeichen zaehlt Schreiben, nicht Forderungen.
     *
     * Zweimal geprueft, weil es zwei Quellen hat: die ueberfaelligen
     * Zahlungen ohne Vorgang und die Vorgaenge mit abgelaufener Frist. Eine
     * Zahl, die Forderungen zaehlte, verspraeche in beiden Faellen mehr
     * Arbeit, als es ist.
     */
    public function testTheBadgeCountsLettersAndNotClaims(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        self::alsoMissedAnAdvance(1, Money::fromCents(20000), 2025);

        self::assertSame(1, self::pressure()->letters, 'Zwei Zahlungen, ein Schuldner, ein Brief');
        self::assertSame(50000, self::pressure()->open->cents());

        foreach (self::overdue()->on(self::today()) as $item) {
            self::start()->fromTheOverdue($item);
        }

        self::assertCount(2, self::claims()->allOpen(), 'Zwei Vorgaenge');
        self::assertSame(1, self::pressure()->letters, 'Und weiterhin ein Brief');
    }

    /**
     * Zwei Objekte sind zwei Briefe — auch fuer das Abzeichen.
     *
     * Es zaehlt die Buendelung, und die geht je Objekt. Eine Eins verspraeche
     * einen Brief, wo zwei zu schreiben sind.
     */
    public function testTheBadgeCountsOneLetterPerProperty(): void
    {
        self::anEnteredClaim(Creditor::Community, Money::fromCents(50000));
        self::anEnteredClaimElsewhere();

        self::assertSame(2, self::pressure()->letters, 'Ein Brief je Objekt');
    }

    /** Nach der letzten Mahnung ist nichts mehr faellig — dann bleibt das Gericht. */
    public function testAfterTheFinalReminderNothingIsDue(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();

        foreach ([DunningLevel::Reminder, DunningLevel::First, DunningLevel::Final] as $level) {
            $notice = self::aDraftFor($claim);
            self::assertSame($level, $notice->level());
            // Die Frist liegt hinter uns — sonst laeuft sie noch, und dann
            // ist zu Recht nichts faellig.
            $notice->payUntil(self::today()->modify('-3 days'));
            self::issued($notice, self::today()->modify('-30 days'));
        }

        $state = self::survey()->of(self::reloadedClaim($claim), self::today());

        self::assertFalse($state->isDue);
        self::assertTrue($state->needsTheCourt);
    }

    /**
     * Nach der letzten Mahnung entsteht kein Entwurf mehr.
     *
     * Sonst gaebe es einen Knopf, der in eine Sackgasse fuehrt: der Entwurf
     * entstuende, und ausstellen liesse er sich nie.
     */
    public function testAfterTheFinalReminderNoDraftIsStarted(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();

        foreach ([DunningLevel::Reminder, DunningLevel::First, DunningLevel::Final] as $level) {
            self::assertSame($level, self::nextLevelFor($claim));
            self::issued(self::aDraftFor($claim));
        }

        self::assertNull(self::nextLevelFor($claim));

        $this->expectException(LevelOutOfOrder::class);
        self::aDraftFor($claim);
    }

    /**
     * Und eine Stufe, die nicht folgt, laesst sich nicht ausstellen.
     *
     * Der Entwurf entsteht hier von Hand: die Anwendung vergibt die Stufe
     * selbst und vergaebe diese nie. Geprueft wird die Sperre und nicht der
     * Weg dorthin — sie ist es, die haelt, wenn jemand das Formular umgeht.
     */
    public function testALevelThatDoesNotFollowIsRefused(): void
    {
        self::alsoMissedAnAdvance(0, Money::fromCents(30000), 2025);
        $claim = self::aClaim();

        $skipping = new Notice(
            new NoticeReference($claim->debtor()->partyId(), 99, $claim->source()->creditorIdentity(), 7),
            DunningLevel::Final,
            self::today()->modify('+14 days'),
        );
        self::notices()->save($skipping);
        self::compose()->cover($skipping, [$claim], self::today());

        $this->expectException(LevelOutOfOrder::class);
        self::issue()->issue($skipping, self::today());
    }

    private static function pressure(): DunningPressure
    {
        $overview = self::getContainer()->get(DunningOverview::class);
        self::assertInstanceOf(DunningOverview::class, $overview);

        return $overview->pressure();
    }

    private static function today(): DateTimeImmutable
    {
        return new DateTimeImmutable('today');
    }

    private static function aClaim(): Claim
    {
        return self::start()->fromTheOverdue(self::anOverdueItem());
    }

    private static function anOverdueItem(): OverdueItem
    {
        $item = self::overdue()->on(self::today())[0] ?? null;
        self::assertInstanceOf(OverdueItem::class, $item);

        return $item;
    }

    private static function anEnteredClaim(Creditor $creditor, Money $amount, int $unitAt = 0): Claim
    {
        $unitId = self::unitIds()[$unitAt] ?? '';
        $dueOn = new DateTimeImmutable('2026-03-03');

        return self::start()->entered(
            self::anOwnerPartyId(),
            false,
            self::theCreditor()->of($creditor, self::propertyId(), $unitId, $dueOn),
            $unitId,
            'Kaltmiete 03/2026',
            $amount,
            $dueOn,
            new DateTimeImmutable('2026-03-04'),
        );
    }

    private static function theCreditor(): TheCreditor
    {
        $creditor = self::getContainer()->get(TheCreditor::class);
        self::assertInstanceOf(TheCreditor::class, $creditor);

        return $creditor;
    }

    /**
     * Die zweite Einheit wechselt an diesem Tag den Eigentuemer.
     *
     * Zwei Abschnitte statt eines: davor der eine, danach der andere. Das
     * ist der Fall, an dem sich zeigt, ob die Zeitachse stimmt.
     */
    private static function theSecondUnitChangedHandsOn(string $day): Party
    {
        $buyer = self::anotherOwner();
        $property = self::properties()->byId(self::propertyId());
        self::assertNotNull($property);
        $changesOn = new DateTimeImmutable($day);

        foreach ($property->units() as $unit) {
            if ($unit->id() !== (self::unitIds()[1] ?? null)) {
                continue;
            }

            foreach ($unit->owners() as $owner) {
                $unit->removeOwner($owner);
            }

            $mea = Mea::of('200.00', 1000);
            new UnitOwner($unit, self::anOwnerPartyId(), $mea, Holding::of(null, $changesOn->modify('-1 day')));
            new UnitOwner($unit, $buyer->id(), $mea, Holding::of($changesOn, null));
        }

        self::properties()->save($property);

        return $buyer;
    }

    private static function debtorsOfAUnit(): DebtorOfAUnit
    {
        $debtors = self::getContainer()->get(DebtorOfAUnit::class);
        self::assertInstanceOf(DebtorOfAUnit::class, $debtors);

        return $debtors;
    }

    /**
     * Die zweite Einheit bekommt einen eigenen Eigentuemer.
     *
     * Der Regelfall in einer WEG: jede Wohnung gehoert jemand anderem. Die
     * Vorlage legt beide auf denselben, weil das fuer die Abrechnung reicht —
     * fuers Mahnwesen ist gerade der Unterschied die Frage.
     */
    private static function aSecondOwnerForTheSecondUnit(): Party
    {
        $second = self::anotherOwner();
        $property = self::properties()->byId(self::propertyId());
        self::assertNotNull($property);

        foreach ($property->units() as $unit) {
            if ($unit->id() === (self::unitIds()[1] ?? null)) {
                foreach ($unit->owners() as $owner) {
                    $unit->removeOwner($owner);
                }

                new UnitOwner($unit, $second->id(), Mea::of('200.00', 1000));
            }
        }

        self::properties()->save($property);

        return $second;
    }

    /**
     * Dieselbe Paarung, ein anderes Objekt.
     *
     * Das Objekt gibt es nicht — es muss auch keines geben: geprueft wird die
     * Buendelung, und die haengt allein an der Kennung.
     */
    private static function anEnteredClaimElsewhere(): Claim
    {
        return self::start()->entered(
            self::anOwnerPartyId(),
            false,
            CreditorIdentity::theCommunityOf('11111111-1111-4111-8111-111111111111'),
            null,
            'Hausgeld 03/2026, anderes Objekt',
            Money::fromCents(60000),
            new DateTimeImmutable('2026-03-03'),
            new DateTimeImmutable('2026-03-04'),
        );
    }

    /** Die Vorauszahlung kommt doch noch an. */
    private static function theAdvanceArrived(): void
    {
        $payments = self::paid()->forYear(self::unitIds(), 2025);
        self::assertNotEmpty($payments);

        foreach ($payments as $payment) {
            $payment->settle();
        }

        self::paid()->saveAll($payments);
    }

    /** Das Objekt wechselt die Bank. */
    private static function theBankChanges(string $iban): void
    {
        $property = self::properties()->byId(self::propertyId());
        self::assertNotNull($property);

        $property->accountsAs($property->accounting()->collectedVia(BankAccount::of(
            Iban::fromString($iban),
            Bic::fromString('BYLADEM1001'),
            'WEG Prüfweg 3',
            'Neues Hausgeldkonto',
        )));
        self::properties()->save($property);
    }

    private static function aDraftFor(Claim $claim): Notice
    {
        return self::compose()->draftFor(
            $claim->debtor()->partyId(),
            $claim->source()->creditorIdentity(),
            self::openFor($claim),
            self::today(),
        );
    }

    /** @return list<Claim> */
    private static function openFor(Claim $claim): array
    {
        return self::claims()->openFor($claim->debtor()->partyId(), $claim->source()->creditorIdentity());
    }

    private static function nextLevelFor(Claim $claim): ?DunningLevel
    {
        return self::compose()->nextLevelFor($claim->debtor()->partyId(), $claim->source()->creditorIdentity());
    }

    private static function issued(Notice $notice, ?DateTimeImmutable $on = null): Notice
    {
        $day = $on ?? self::today();
        self::assertSame([], self::issue()->gapsOf(
            $notice,
            self::compose()->claimsOf($notice),
            self::issue()->recipientsOf(self::compose()->claimsOf($notice)),
            $day,
        ), 'Das Schreiben ist vollstaendig');
        self::issue()->issue($notice, $day);

        return $notice;
    }

    private static function reloaded(Notice $notice): Notice
    {
        $found = self::notices()->byId($notice->id());
        self::assertInstanceOf(Notice::class, $found);

        return $found;
    }

    private static function reloadedClaim(Claim $claim): Claim
    {
        $found = self::claims()->byId($claim->id());
        self::assertInstanceOf(Claim::class, $found);

        return $found;
    }

    /** Ein zweiter Eigentuemer, damit es zwei Vermieter im Haus gibt. */
    private static function anotherOwner(): Party
    {
        foreach (self::parties()->matching(PartyFilter::none(), Page::of(1, 500)) as $known) {
            if (99007 === $known->reference()) {
                return $known;
            }
        }

        $owner = new Party(
            99007,
            PartyKind::Person,
            'Zweitbesitz',
            'Zoltan',
            PartyRoles::of([PartyRole::Owner]),
            Addresses::of([PostalAddress::of(AddressKind::Street, 'Nebenweg 4', '40233', 'Düsseldorf')]),
            ContactDetails::of([Email::fromString('zoltan@example.org')]),
        );
        self::parties()->save($owner);

        return $owner;
    }

    private static function anOwnerPartyId(): string
    {
        return self::anOwner()->id();
    }

    /** Eine Teilzahlung auf die offene Vorauszahlung. */
    private static function alsoPaidAPart(Money $part): void
    {
        foreach (self::paid()->forYear(self::unitIds(), 2025) as $payment) {
            if (!$payment->isSettled() && AdvanceKind::HouseMoney === $payment->kind()) {
                $payment->missed($part);
                self::paid()->saveAll([$payment]);

                return;
            }
        }
    }

    private static function theAdvanceIsSettled(): void
    {
        foreach (self::paid()->forYear(self::unitIds(), 2025) as $payment) {
            if (!$payment->isSettled()) {
                $payment->settle();
                self::paid()->saveAll([$payment]);
            }
        }
    }

    /** Die Reihe der Bundesbank, so wie die Migration sie vorbelegt. */
    private static function theBaseRates(): void
    {
        $rates = self::getContainer()->get(BaseRateRepository::class);
        self::assertInstanceOf(BaseRateRepository::class, $rates);

        foreach ($rates->all()->all() as $rate) {
            $rates->remove($rate);
        }

        foreach (self::BASE_RATES as $day => $bps) {
            $rates->save(new BaseRate(new DateTimeImmutable($day), $bps));
        }
    }

    /** Die Reihe endet vor dem laufenden Halbjahr. */
    private static function theRatesEndIn(int $year): void
    {
        $rates = self::getContainer()->get(BaseRateRepository::class);
        self::assertInstanceOf(BaseRateRepository::class, $rates);

        foreach ($rates->all()->all() as $rate) {
            if ((int) $rate->validFrom()->format('Y') > $year) {
                $rates->remove($rate);
            }
        }

        $rates->save(new BaseRate(new DateTimeImmutable($year.'-01-01'), 100));
    }

    private static function overdue(): SurveyOverdue
    {
        $survey = self::getContainer()->get(SurveyOverdue::class);
        self::assertInstanceOf(SurveyOverdue::class, $survey);

        return $survey;
    }

    private static function start(): StartClaim
    {
        $start = self::getContainer()->get(StartClaim::class);
        self::assertInstanceOf(StartClaim::class, $start);

        return $start;
    }

    private static function interest(): TheInterest
    {
        $interest = self::getContainer()->get(TheInterest::class);
        self::assertInstanceOf(TheInterest::class, $interest);

        return $interest;
    }

    private static function compose(): ComposeNotice
    {
        $compose = self::getContainer()->get(ComposeNotice::class);
        self::assertInstanceOf(ComposeNotice::class, $compose);

        return $compose;
    }

    private static function issue(): IssueNotice
    {
        $issue = self::getContainer()->get(IssueNotice::class);
        self::assertInstanceOf(IssueNotice::class, $issue);

        return $issue;
    }

    private static function settle(): SettleClaim
    {
        $settle = self::getContainer()->get(SettleClaim::class);
        self::assertInstanceOf(SettleClaim::class, $settle);

        return $settle;
    }

    private static function survey(): SurveyClaims
    {
        $survey = self::getContainer()->get(SurveyClaims::class);
        self::assertInstanceOf(SurveyClaims::class, $survey);

        return $survey;
    }

    private static function claims(): ClaimRepository
    {
        $claims = self::getContainer()->get(ClaimRepository::class);
        self::assertInstanceOf(ClaimRepository::class, $claims);

        return $claims;
    }

    private static function notices(): NoticeRepository
    {
        $notices = self::getContainer()->get(NoticeRepository::class);
        self::assertInstanceOf(NoticeRepository::class, $notices);

        return $notices;
    }
}
