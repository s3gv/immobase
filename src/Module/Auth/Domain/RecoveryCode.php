<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein Wiederherstellungscode: der Weg zurueck, wenn das Geraet weg ist.
 *
 * Ohne solche Codes haengt jedes Konto an einem Telefon. Bei einer
 * Installation mit einem einzigen Administrator ist das genau der Fall, in
 * dem niemand mehr hereinkommt — es gibt keinen Support, der es richtet.
 *
 * Gespeichert wird nur der Hash, mit Serverschluessel wie jedes andere
 * Geheimnis auch: rund 50 Bit je Code waeren aus einem Datenbankabzug sonst
 * durchprobierbar.
 *
 * Eigene Tabelle und nicht auth_token: diese Codes laufen nicht ab, kommen zu
 * zehnt und gehoeren zum Konto, nicht zu einem einzelnen Vorgang.
 */
#[ORM\Entity]
#[ORM\Table(name: 'auth_recovery_code')]
#[ORM\Index(name: 'auth_recovery_code_owner', columns: ['user_id'])]
class RecoveryCode
{
    public const int PER_ACCOUNT = 10;
    /** Ohne 0/1/I/O — sie werden abgeschrieben, und zwar oft von Papier. */
    private const string ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const int GROUPS = 2;
    private const int GROUP_LENGTH = 5;

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $userId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $hash;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    private function __construct(string $userId, string $hash)
    {
        $this->id = bin2hex(random_bytes(16));
        $this->userId = $userId;
        $this->hash = $hash;
    }

    /**
     * Zehn frische Codes samt Klartext.
     *
     * Der Klartext existiert genau einmal — er wird angezeigt und danach
     * vergessen. Wer ihn verliert, erzeugt neue.
     *
     * @return array{list<self>, list<string>}
     */
    public static function issue(string $userId, TokenHasher $hasher): array
    {
        $codes = [];
        $plain = [];

        for ($i = 0; $i < self::PER_ACCOUNT; ++$i) {
            $text = self::text();
            $plain[] = $text;
            $codes[] = new self($userId, $hasher->hash(self::normalise($text)));
        }

        return [$codes, $plain];
    }

    /**
     * Abgeschrieben wird schlampig: Bindestriche und Kleinschreibung sollen
     * nicht ueber die Anmeldung entscheiden.
     */
    public static function normalise(string $text): string
    {
        return strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $text) ?? '');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function isUnused(): bool
    {
        return null === $this->usedAt;
    }

    /** Nur nach bestaetigter Entwertung — siehe RecoveryCodeRepository::consume(). */
    public function markUsed(DateTimeImmutable $now): void
    {
        $this->usedAt = $now;
    }

    private static function text(): string
    {
        $groups = [];

        for ($group = 0; $group < self::GROUPS; ++$group) {
            $text = '';

            for ($position = 0; $position < self::GROUP_LENGTH; ++$position) {
                $text .= self::ALPHABET[random_int(0, \strlen(self::ALPHABET) - 1)];
            }

            $groups[] = $text;
        }

        return implode('-', $groups);
    }
}
