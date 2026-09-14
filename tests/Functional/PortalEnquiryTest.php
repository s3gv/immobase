<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Portal\Domain\Attachment;
use App\Module\Portal\Domain\Enquiry;
use App\Module\Portal\Domain\EnquiryRepository;
use App\Module\Portal\Domain\FileVault;
use App\Module\Portal\Domain\Message;
use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Anfragen aus der Sicht dessen, der fragt.
 *
 * Zwei Zusicherungen stehen hier im Mittelpunkt, und beide sind die Sorte,
 * die still kaputtgeht:
 *
 * * **Eine fremde Anfrage gibt es fuer dieses Konto nicht.** Nicht
 *   „verboten", sondern nicht vorhanden — sonst verraet schon die Antwort,
 *   welche Kennungen es gibt.
 * * **Eine abgelaufene Datei wird nicht mehr herausgegeben**, auch wenn ihre
 *   Bytes noch in der Zeile stehen. Die Frist gilt ab der Sekunde und nicht
 *   ab dem naechsten Aufraeumlauf.
 */
final class PortalEnquiryTest extends WebTestCase
{
    use BuildsABillableProperty;
    use ForgetsRateLimits;
    use SignsIn;

    protected function tearDown(): void
    {
        self::removeEnquiries();
        self::removeTheProperty();
        self::removeTestUser();

        parent::tearDown();
    }

    /** Eine Anfrage mit zwei Anhaengen — und beide sind danach herunterzuladen. */
    public function testAnEnquiryCarriesItsFiles(): void
    {
        $client = self::asTheOwner();

        $form = $client->request('GET', '/portal/anfragen/neu');
        $client->request('POST', '/portal/anfragen/neu', [
            '_token' => self::tokenOn($form),
            'subject' => 'Heizung tropft',
            'body' => 'Im Keller steht Wasser. 🔧',
        ], ['files' => [self::aFile('beleg.pdf', 'ERSTE DATEI'), self::aFile('foto.txt', 'ZWEITE DATEI')]]);

        self::assertResponseRedirects();
        $conversation = $client->followRedirect();

        self::assertStringContainsString('Heizung tropft', $conversation->text());
        self::assertStringContainsString('Im Keller steht Wasser. 🔧', $conversation->text());
        self::assertStringContainsString('beleg.pdf', $conversation->text());
        self::assertStringContainsString('foto.txt', $conversation->text());

        $client->request('GET', self::firstAttachmentUrl($conversation));

        // Nichts wird im Browser dargestellt — also kann nichts im Browser
        // ausgefuehrt werden.
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
        self::assertSame('ERSTE DATEI', $client->getResponse()->getContent());
    }

    /** Ohne Betreff oder ohne Text entsteht keine Anfrage. */
    public function testAnIncompleteEnquiryIsRefused(): void
    {
        $client = self::asTheOwner();

        $form = $client->request('GET', '/portal/anfragen/neu');
        $client->request('POST', '/portal/anfragen/neu', [
            '_token' => self::tokenOn($form),
            'subject' => 'Ohne alles',
            'body' => '   ',
        ]);

        self::assertResponseRedirects();
        self::assertCount(0, self::enquiries()->forParty(self::anOwner()->id()));
    }

    /**
     * Die Anfrage eines Fremden gibt es nicht.
     *
     * Geprueft wird mit einer Kennung, die es wirklich gibt: eine erfundene
     * waere auch dann nicht zu finden, wenn die Pruefung fehlte.
     */
    public function testAStrangersEnquiryDoesNotExist(): void
    {
        $client = self::asTheOwner();
        $strangers = self::anEnquiryOf(Uuid::v4());

        $client->request('GET', '/portal/anfragen/'.$strangers->id());
        self::assertResponseStatusCodeSame(404);

        $client->request('POST', '/portal/anfragen/'.$strangers->id().'/antwort', ['body' => 'Hallo']);
        self::assertResponseStatusCodeSame(404);
    }

    /** Und sein Anhang ebenso wenig. */
    public function testAStrangersFileDoesNotExist(): void
    {
        $client = self::asTheOwner();
        $strangers = self::anEnquiryOf(Uuid::v4());

        $client->request('GET', '/portal/anhang/'.self::attachmentOf($strangers)->id());

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Eine abgelaufene Datei wird nicht mehr ausgeliefert — die Nachricht
     * bleibt stehen.
     *
     * Beides gehoert zusammen: „wie besprochen, siehe Anhang" ohne alles
     * waere eine Unterhaltung, die niemand mehr versteht.
     */
    public function testAnExpiredFileIsGoneButItsMessageRemains(): void
    {
        $client = self::asTheOwner();
        $mine = self::anEnquiryOf(self::anOwner()->id());
        $expired = self::attachmentOf($mine, new DateTimeImmutable('-1 hour'));

        $conversation = $client->request('GET', '/portal/anfragen/'.$mine->id());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Wie besprochen', $conversation->text());
        self::assertStringContainsString('beleg.pdf', $conversation->text());
        self::assertStringContainsString('Frist ist abgelaufen', $conversation->text());

        $client->request('GET', '/portal/anhang/'.$expired->id());
        self::assertResponseStatusCodeSame(404);
    }

    protected static function testEmail(): string
    {
        return 'portalanfragen@example.org';
    }

    private static function asTheOwner(): KernelBrowser
    {
        $client = self::createClient();
        self::buildTheProperty();
        self::signInForParty($client, self::anOwner()->id());

        return $client;
    }

    /** Eine Anfrage samt erster Nachricht, wie sie das Portal anlegt. */
    private static function anEnquiryOf(string $partyId): Enquiry
    {
        $enquiry = new Enquiry(self::enquiries()->nextNumber(), $partyId, 'Fremder Vorgang', new DateTimeImmutable());
        new Message($enquiry, null, 'Fremd, Frieda', 'Wie besprochen, siehe Anhang.', new DateTimeImmutable());
        self::enquiries()->save($enquiry);

        return $enquiry;
    }

    /** Ein Anhang an der ersten Nachricht — mit einer Frist, die der Test setzt. */
    private static function attachmentOf(Enquiry $enquiry, ?DateTimeImmutable $deleteAfter = null): Attachment
    {
        $vault = self::getContainer()->get(FileVault::class);
        self::assertInstanceOf(FileVault::class, $vault);
        self::assertTrue($vault->isReady(), 'Der Testlauf braucht einen Schlüssel in IMMOBASE_FILE_KEY.');

        $first = $enquiry->messages()[0] ?? null;
        self::assertInstanceOf(Message::class, $first, 'Die Anfrage hat keine Nachricht.');

        $id = Uuid::v4();
        $attachment = new Attachment(
            $first,
            $id,
            'beleg.pdf',
            'application/pdf',
            11,
            $vault->seal('ERSTE DATEI', $id),
            new DateTimeImmutable(),
            $deleteAfter ?? new DateTimeImmutable('+3 days'),
        );
        self::enquiries()->save($enquiry);

        return $attachment;
    }

    private static function aFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ib');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    private static function firstAttachmentUrl(Crawler $conversation): string
    {
        $link = $conversation->filter('a[href^="/portal/anhang/"]')->first();
        self::assertGreaterThan(0, $link->count(), 'Im Gespräch steht kein Anhang.');

        return (string) $link->attr('href');
    }

    private static function tokenOn(Crawler $crawler): string
    {
        $token = $crawler->filter('input[name="_token"]')->first();

        return 0 === $token->count() ? '' : (string) $token->attr('value');
    }

    private static function enquiries(): EnquiryRepository
    {
        $enquiries = self::getContainer()->get(EnquiryRepository::class);
        self::assertInstanceOf(EnquiryRepository::class, $enquiries);

        return $enquiries;
    }

    /**
     * Alles wegraeumen, was der Lauf angelegt hat.
     *
     * Ueber die Entities und nicht als eine Anweisung: die Nachrichten und
     * Anhaenge haengen an Kaskaden, und eine DQL-Loeschung ginge an ihnen
     * vorbei.
     */
    private static function removeEnquiries(): void
    {
        if (null === self::$kernel) {
            return;
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        if (!$entityManager instanceof EntityManagerInterface) {
            return;
        }

        foreach ($entityManager->getRepository(Enquiry::class)->findAll() as $enquiry) {
            $entityManager->remove($enquiry);
        }

        $entityManager->flush();
    }
}
