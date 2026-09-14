<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Das Logo der Hausverwaltung — eine Zeile.
 *
 * **Feste Masze, 600 × 200.** Nicht „hoechstens": dann steht das Logo im
 * Briefkopf immer gleich hoch, ohne dass wir skalieren und dabei unscharf
 * werden. Wer ein quadratisches Logo hat, legt es mittig auf die Flaeche —
 * das entscheidet er, nicht wir.
 *
 * **Geprueft wird der Bildinhalt, nicht die Endung.** Eine Datei heisst, wie
 * jemand sie nennt.
 *
 * **Neu gezeichnet, nicht abgelegt, wie es kam.** Was hinter den Bilddaten
 * steht — ein Stueck HTML, ein Skript, ein zweites Dateiformat —, gehoert
 * nicht zum Bild und faellt beim Neuzeichnen weg. Ausgeliefert wird so nur,
 * was ein PNG-Decoder auch als Bild gelesen hat.
 *
 * **Abgelegt als base64 in einer Textspalte.** Doctrine gibt ein BLOB als
 * Datenstrom zurueck, und ein Datenstrom laesst sich einmal lesen — das ist
 * eine Falle, die niemand erwartet, wenn er zweimal auf dasselbe Feld
 * zugreift. Ein Drittel mehr Platz fuer eine Datei je Installation ist der
 * ruhigere Handel, und der Dump bleibt lesbar.
 */
#[ORM\Entity]
#[ORM\Table(name: 'settings_logo')]
class Logo
{
    public const int WIDTH = 600;

    public const int HEIGHT = 200;

    /** Ein Briefkopflogo, das groesser ist, ist keines. */
    public const int MOST_BYTES = 1024 * 1024;

    public const string CONTENT_TYPE = 'image/png';

    /**
     * Der Schluessel der einen Zeile.
     *
     * Kein Zufallswert: eine zufaellige Kennung erlaubt zwei Zeilen, und zwei
     * Logos sind kein Logo. Zwei gleichzeitige erste Uploads sehen beide
     * „noch keines" — die Eindeutigkeit ist hier der Primaerschluessel, und
     * die Ablage macht daraus ein Ersetzen.
     */
    public const string ONLY = 'logo';

    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 8)]
    private string $id = self::ONLY;

    #[ORM\Column(type: Types::TEXT)]
    private string $image;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * @throws LogoRejected
     */
    public function __construct(string $bytes, DateTimeImmutable $on)
    {
        $this->replaceWith($bytes, $on);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function bytes(): string
    {
        $decoded = base64_decode($this->image, true);

        return false === $decoded ? '' : $decoded;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @throws LogoRejected
     */
    public function replaceWith(string $bytes, DateTimeImmutable $on): void
    {
        self::refuseWhatIsNotALogo($bytes);

        $this->image = base64_encode(self::redrawn($bytes));
        $this->updatedAt = $on;
    }

    /**
     * @throws LogoRejected
     */
    private static function redrawn(string $bytes): string
    {
        // Kaputte Bilddaten meldet GD als Warnung und nicht als Rueckgabewert
        // allein; hier ist beides dasselbe: kein Logo.
        set_error_handler(static fn (): bool => true);

        try {
            $image = imagecreatefromstring($bytes);

            if (false === $image) {
                throw LogoRejected::unreadable();
            }

            imagesavealpha($image, true);
            ob_start();
            $written = imagepng($image);
            $png = (string) ob_get_clean();
        } finally {
            restore_error_handler();
        }

        return $written && '' !== $png ? $png : throw LogoRejected::unreadable();
    }

    /**
     * @throws LogoRejected
     */
    private static function refuseWhatIsNotALogo(string $bytes): void
    {
        if ('' === $bytes) {
            throw LogoRejected::unreadable();
        }

        if (\strlen($bytes) > self::MOST_BYTES) {
            throw LogoRejected::tooBig();
        }

        $size = getimagesizefromstring($bytes);

        if (false === $size) {
            throw LogoRejected::unreadable();
        }

        if (\IMAGETYPE_PNG !== $size[2]) {
            throw LogoRejected::notAPng();
        }

        if (self::WIDTH !== $size[0] || self::HEIGHT !== $size[1]) {
            throw LogoRejected::wrongSize($size[0], $size[1]);
        }
    }
}
