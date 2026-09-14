<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Module\Settings\Application\Settings;
use App\Module\Settings\Contract\SettingsPermissions;
use App\Module\Settings\Domain\Logo;
use App\Module\Settings\Domain\LogoRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Das Logo: hochladen, ansehen, entfernen.
 *
 * Geprueft wird der Bildinhalt und nicht die Endung — eine Datei heisst, wie
 * jemand sie nennt. Und die Masze sind fest, damit das Logo im Briefkopf
 * immer gleich hoch steht.
 */
final class SettingsLogoTest extends WebTestCase
{
    use ForgetsRateLimits;
    use SignsIn;
    use UsesASecondConnection;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        $this->closeSecondConnection();
        self::removeLogo();
        self::removeTestUser();

        foreach ($this->files as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    public function testAProperLogoIsAcceptedAndShown(): void
    {
        $client = self::editor();

        $this->upload($client, $this->png(Logo::WIDTH, Logo::HEIGHT));

        self::assertNotNull(self::logos()->current());

        $client->request('GET', '/einstellungen/logo');

        self::assertResponseIsSuccessful();
        self::assertSame(Logo::CONTENT_TYPE, $client->getResponse()->headers->get('Content-Type'));
    }

    /** Beim zweiten Aufruf liegt es nicht noch einmal auf der Leitung. */
    public function testTheSecondRequestIsAnsweredWithoutTheImage(): void
    {
        $client = self::editor();
        $this->upload($client, $this->png(Logo::WIDTH, Logo::HEIGHT));

        $client->request('GET', '/einstellungen/logo');
        $etag = $client->getResponse()->headers->get('ETag');
        self::assertNotNull($etag);

        $client->request('GET', '/einstellungen/logo', server: ['HTTP_IF_NONE_MATCH' => $etag]);

        self::assertResponseStatusCodeSame(304);
    }

    /** Ein JPEG ist kein PNG, auch wenn die Datei so heisst. */
    public function testAJpegIsRefused(): void
    {
        $client = self::editor();

        $this->upload($client, $this->jpeg(Logo::WIDTH, Logo::HEIGHT), 'logo.png');

        self::assertNull(self::logos()->current());
        self::assertSelectorTextContains('.ib-flash', 'PNG');
    }

    /** Andere Masze passen nicht in den Briefkopf. */
    public function testAWronglySizedPngIsRefused(): void
    {
        $client = self::editor();

        $this->upload($client, $this->png(300, 100));

        self::assertNull(self::logos()->current());
        self::assertSelectorTextContains('.ib-flash', '600');
    }

    /** Ein zweites Logo ersetzt das erste — es gibt hoechstens eines. */
    public function testASecondUploadReplacesTheFirst(): void
    {
        $client = self::editor();
        $this->upload($client, $this->png(Logo::WIDTH, Logo::HEIGHT));
        $first = self::logos()->current()?->id();

        $this->upload($client, $this->png(Logo::WIDTH, Logo::HEIGHT, 'blue'));

        self::assertSame($first, self::logos()->current()?->id(), 'Dieselbe Zeile');
    }

    public function testTheLogoCanBeRemoved(): void
    {
        $client = self::editor();
        $this->upload($client, $this->png(Logo::WIDTH, Logo::HEIGHT));

        $crawler = $client->request('GET', '/einstellungen?abschnitt=logo');
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');
        $client->request('POST', '/einstellungen/logo/entfernen', ['_token' => $token]);

        self::assertNull(self::logos()->current());
        self::assertNull(self::settings()->logo());
    }

    /** Und andere Module bekommen die Bytes, nicht eine Adresse. */
    public function testOtherModulesGetTheBytes(): void
    {
        $client = self::editor();
        $bytes = $this->png(Logo::WIDTH, Logo::HEIGHT);
        $this->upload($client, $bytes);

        $image = self::settings()->logo();

        self::assertNotNull($image);
        self::assertSame([Logo::WIDTH, Logo::HEIGHT, \IMAGETYPE_PNG], \array_slice((array) getimagesizefromstring($image->bytes), 0, 3));
        self::assertSame(Logo::CONTENT_TYPE, $image->contentType);
    }

    /**
     * Was hinter den Bilddaten haengt, kommt nicht mit.
     *
     * Ein PNG mit angehaengtem HTML besteht jede Pruefung des Kopfes. Es
     * wird neu gezeichnet, und dabei bleibt nur, was ein Decoder als Bild
     * gelesen hat.
     */
    public function testWhatHangsBehindTheImageIsDropped(): void
    {
        $client = self::editor();
        $this->upload($client, $this->png(Logo::WIDTH, Logo::HEIGHT).'<html><script>alert(1)</script></html>');

        $client->request('GET', '/einstellungen/logo');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<script>', (string) $client->getResponse()->getContent());
        self::assertSame('nosniff', $client->getResponse()->headers->get('X-Content-Type-Options'));
    }

    /**
     * Zwei erste Uploads im selben Augenblick — der zweite ersetzt.
     *
     * Beide sehen „noch keines" und legen beide an. Frueher konnten dabei
     * zwei Zeilen entstehen: danach zeigte die Anwendung die juengere, und
     * wer sie entfernte, bekam die aeltere zurueck. Jetzt laesst der
     * Schluessel nur eine durch, und der Verlierer schreibt seinen Inhalt
     * hinein — wer ein Logo hochlaedt, will es dort sehen.
     */
    public function testASimultaneousFirstUploadReplacesInsteadOfFailing(): void
    {
        self::editor();
        self::assertNull(self::logos()->current(), 'Der Ausgangspunkt ist: noch keines');

        $mine = new Logo($this->png(Logo::WIDTH, Logo::HEIGHT), new DateTimeImmutable('now'));

        // Die andere Anfrage war schneller.
        $other = $this->secondConnection();
        $other->executeStatement(
            'INSERT INTO settings_logo (id, image, updated_at) VALUES (?, ?, ?)',
            [Logo::ONLY, base64_encode($this->png(Logo::WIDTH, Logo::HEIGHT, 'blue')), '2026-09-11 10:00:00'],
        );

        self::logos()->save($mine);

        $rows = $other->fetchOne('SELECT COUNT(*) FROM settings_logo');

        self::assertIsNumeric($rows);
        self::assertSame(1, (int) $rows, 'Eine Zeile, nicht zwei');
        self::assertSame($mine->bytes(), self::logos()->current()?->bytes(), 'Und zwar meines');
    }

    /** Und eine zweite Zeile gibt es auch dann nicht, wenn jemand es versucht. */
    public function testTheDatabaseRefusesASecondRow(): void
    {
        $this->upload(self::editor(), $this->png(Logo::WIDTH, Logo::HEIGHT));
        self::assertNotNull(self::logos()->current());

        $this->expectException(DriverException::class);

        $this->secondConnection()->executeStatement(
            "INSERT INTO settings_logo (id, image, updated_at) VALUES ('zweites', 'x', NOW())",
        );
    }

    protected static function testEmail(): string
    {
        return 'logo@example.org';
    }

    private static function editor(): KernelBrowser
    {
        return self::signedInWith([SettingsPermissions::VIEW, SettingsPermissions::EDIT]);
    }

    private function upload(KernelBrowser $client, string $bytes, string $name = 'logo.png'): void
    {
        $crawler = $client->request('GET', '/einstellungen?abschnitt=logo');
        $token = (string) $crawler->filter('main input[name="_token"]')->first()->attr('value');

        $path = tempnam(sys_get_temp_dir(), 'logo');
        self::assertIsString($path);
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        $client->request(
            'POST',
            '/einstellungen/logo',
            ['_token' => $token],
            ['logo' => new UploadedFile($path, $name, Logo::CONTENT_TYPE, test: true)],
        );
        $client->followRedirect();
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function png(int $width, int $height, string $tint = 'grey'): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        $colour = 'blue' === $tint
            ? imagecolorallocate($image, 0, 0, 200)
            : imagecolorallocate($image, 120, 120, 120);
        self::assertNotFalse($colour);
        imagefilledrectangle($image, 0, 0, $width, $height, $colour);

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }

    private static function logos(): LogoRepository
    {
        $logos = self::getContainer()->get(LogoRepository::class);
        self::assertInstanceOf(LogoRepository::class, $logos);

        return $logos;
    }

    private static function settings(): Settings
    {
        $settings = self::getContainer()->get(Settings::class);
        self::assertInstanceOf(Settings::class, $settings);

        return $settings;
    }

    /**
     * Weggeraeumt wird ueber die Verbindung und nicht ueber den Manager.
     *
     * Ein gescheiterter Testlauf kann einen geschlossenen Entity-Manager
     * hinterlassen; die Zeile muss trotzdem weg, sonst faellt der naechste
     * Lauf ueber den Rest des vorigen.
     */
    private static function removeLogo(): void
    {
        if (null === self::$kernel) {
            return;
        }

        self::entityManager()->getConnection()->executeStatement('DELETE FROM settings_logo');
    }
}
