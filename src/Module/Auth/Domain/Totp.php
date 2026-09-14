<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

/**
 * Einmalkennwoerter nach RFC 6238 (TOTP), sechsstellig, 30-Sekunden-Fenster.
 *
 * Selbst geschrieben und nicht aus einem Bundle: der Kern sind zwanzig Zeilen
 * um hash_hmac herum, und er laesst sich gegen die Testvektoren aus Anhang B
 * des RFC pruefen. Ein Bundle braechte dafuer viel fremde Flaeche in den
 * sicherheitskritischsten Pfad der Anwendung und ein eigenes Konzept, das wir
 * mittragen muessten.
 *
 * SHA-1 ist hier kein Versehen: Authenticator-Apps sprechen es, und die
 * Sicherheit des Verfahrens haengt am Geheimnis, nicht an der Hashfunktion.
 */
final readonly class Totp
{
    public const int PERIOD = 30;
    private const int DIGITS = 6;
    private const string ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private function __construct()
    {
    }

    /** Ein neues Geheimnis, 160 Bit, in der Base32-Form, die Apps erwarten. */
    public static function newSecret(): string
    {
        return self::toBase32(random_bytes(20));
    }

    public static function step(int $timestamp): int
    {
        return intdiv($timestamp, self::PERIOD);
    }

    public static function codeForStep(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::fromBase32($secret), true);

        // Dynamic Truncation nach RFC 4226: das letzte Halbbyte sagt, wo im
        // Hash die vier Bytes stehen, aus denen der Code entsteht.
        $offset = \ord($hash[19]) & 0x0F;
        /** @var array{1: int}|false $number */
        $number = unpack('N', substr($hash, $offset, 4));
        $value = (false === $number ? 0 : $number[1]) & 0x7FFFFFFF;

        return str_pad((string) ($value % 10 ** self::DIGITS), self::DIGITS, '0', \STR_PAD_LEFT);
    }

    /**
     * Passt der Code — und in welchem Zeitfenster?
     *
     * Geprueft werden das aktuelle Fenster und je eines davor und danach:
     * Uhren laufen auseinander, und niemand tippt in Nullzeit. Mehr nicht —
     * jedes zusaetzliche Fenster verlaengert die Zeit, in der ein
     * abgefangener Code noch gilt.
     *
     * Zurueck kommt das Fenster und nicht nur true: der Aufrufer muss es
     * merken koennen, damit derselbe Code kein zweites Mal durchgeht.
     */
    public static function matchingStep(string $secret, string $code, int $timestamp): ?int
    {
        $current = self::step($timestamp);

        foreach ([$current, $current - 1, $current + 1] as $candidate) {
            if (hash_equals(self::codeForStep($secret, $candidate), $code)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Die Adresse, die als QR-Code in der App landet. */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return \sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }

    private static function toBase32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private static function fromBase32(string $secret): string
    {
        $bits = '';

        foreach (str_split(strtoupper($secret)) as $character) {
            $index = strpos(self::ALPHABET, $character);

            if (false !== $index) {
                $bits .= str_pad(decbin($index), 5, '0', \STR_PAD_LEFT);
            }
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $chunk) {
            if (8 === \strlen($chunk)) {
                $bytes .= \chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
