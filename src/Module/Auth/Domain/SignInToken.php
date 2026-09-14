<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein einmaliger Schluessel per E-Mail: Einladung, Zuruecksetzen, Code.
 *
 * Gespeichert wird nur der Hash. Der Klartext entsteht einmal, geht in die
 * E-Mail und ist danach weg — wer die Datenbank liest, haelt damit kein
 * fremdes Konto in der Hand.
 *
 * Gehasht wird mit Serverschluessel, siehe TokenHasher: der Code fuer den
 * zweiten Faktor ist sechsstellig, und ein blosser SHA-256 waere aus einem
 * Datenbankabzug in Minuten zurueckgerechnet.
 */
#[ORM\Entity]
#[ORM\Table(name: 'auth_token')]
#[ORM\Index(name: 'auth_token_lookup', columns: ['hash'])]
class SignInToken
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $userId;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: TokenPurpose::class)]
    private TokenPurpose $purpose;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $hash;

    /**
     * Wohin die Aenderung fuehren soll — heute nur die neue E-Mail-Adresse.
     *
     * Sie steht am Schluessel und nicht am Konto: bis jemand den Link in der
     * neuen Adresse anklickt, ist nichts entschieden, und ein halb geaenderter
     * Datensatz waere eine Falle.
     */
    #[ORM\Column(type: Types::STRING, length: 320, nullable: true)]
    private ?string $payload;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    private function __construct(
        string $userId,
        TokenPurpose $purpose,
        string $hash,
        DateTimeImmutable $expiresAt,
        ?string $payload,
    ) {
        $this->id = bin2hex(random_bytes(16));
        $this->userId = $userId;
        $this->purpose = $purpose;
        $this->hash = $hash;
        $this->expiresAt = $expiresAt;
        $this->payload = $payload;
    }

    /**
     * Stellt einen Schluessel aus und gibt ihn im Klartext zurueck.
     *
     * Der Klartext wird bewusst nur hier herausgereicht und nirgends
     * gespeichert. Wer ihn nicht sofort verschickt, hat ihn verloren — und
     * genau so soll es sein.
     */
    public static function issue(
        string $userId,
        TokenPurpose $purpose,
        DateTimeImmutable $now,
        TokenHasher $hasher,
        ?string $payload = null,
    ): IssuedToken {
        $plain = self::secret($purpose);

        return new IssuedToken(
            new self($userId, $purpose, $hasher->hash($plain), $now->add($purpose->lifetime()), $payload),
            $plain,
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function purpose(): TokenPurpose
    {
        return $this->purpose;
    }

    public function payload(): ?string
    {
        return $this->payload;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isUsable(DateTimeImmutable $now): bool
    {
        return null === $this->usedAt && $this->expiresAt > $now;
    }

    /**
     * Nur fuer den Fall, dass die Datenbank die Entwertung bestaetigt hat —
     * siehe TokenRepository::consume(). Wer hier ohne diesen Beleg schreibt,
     * baut das Wettrennen wieder ein, das die bedingte Abfrage verhindert.
     */
    public function markUsed(DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }

    /**
     * Ein Code zum Abtippen ist sechsstellig, ein Link 32 Byte lang.
     *
     * Der kurze Code lebt nur zehn Minuten und ist gegen Durchprobieren durch
     * die Ratenbegrenzung geschuetzt; ein Link liegt tagelang im Postfach und
     * braucht deshalb echte Laenge.
     */
    private static function secret(TokenPurpose $purpose): string
    {
        if (TokenPurpose::SecondFactor === $purpose) {
            return \sprintf('%06d', random_int(0, 999999));
        }

        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
