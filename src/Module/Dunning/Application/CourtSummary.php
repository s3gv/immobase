<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\InterestSchedule;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Die Zusammenfassung fuer das gerichtliche Mahnverfahren.
 *
 * Kein Brief an den Schuldner, sondern das Blatt fuer den Anwalt oder die
 * Akte. Es traegt genau die Felder, nach denen das Mahnbescheidsformular
 * fragt: Glaeubiger und Schuldner mit Anschrift, je Hauptforderung Betrag,
 * Faelligkeit, Verzugsbeginn und Rechtsgrund, die vollstaendige Zinsstaffel,
 * die Nebenforderungen einzeln — und die Mahnhistorie mit Datum je Stufe.
 *
 * **Den Mahnbescheid stellt ein Gericht aus.** Wir liefern, was man mitgibt.
 */
final readonly class CourtSummary
{
    public function __construct(
        private ClaimRepository $claims,
        private NoticeRepository $notices,
        private TheInterest $interest,
        private Addressed $addressed,
    ) {
    }

    /**
     * Alles zu einem Schuldner bei einem Glaeubiger in einem Objekt.
     *
     * @return array{
     *     creditor: array{name: string, address: string},
     *     debtor: array{name: string, address: string},
     *     claims: list<array{claim: Claim, interest: InterestSchedule}>,
     *     notices: list<Notice>,
     *     principal: Money,
     *     interest: Money,
     *     extras: Money,
     * }
     */
    public function of(Claim $anchor, DateTimeImmutable $on): array
    {
        $claims = $this->bundledAround($anchor);
        $rows = $this->rowsOf($claims, $on);
        $principal = Money::zero();
        $interest = Money::zero();

        foreach ($rows as $row) {
            $principal = $principal->plus($row['claim']->open());
            $interest = $interest->plus($row['interest']->total());
        }

        $notices = array_values(array_filter(
            $this->notices->forClaims(array_map(static fn (Claim $claim): string => $claim->id(), $claims)),
            static fn (Notice $notice): bool => !$notice->isDraft(),
        ));

        return [
            'creditor' => $this->addressed->creditorOf($anchor),
            'debtor' => $this->addressed->debtorOf($anchor),
            'claims' => $rows,
            'notices' => $notices,
            'principal' => $principal,
            'interest' => $interest,
            'extras' => self::extrasOf($notices),
        ];
    }

    /**
     * Dieselbe Buendelung wie beim Schreiben.
     *
     * Derselbe Schuldner, derselbe Glaeubiger — und der ist vollstaendig
     * gemeint. Ein Antrag, der die Rueckstaende zweier Glaeubiger
     * zusammenzaehlt, beantragt einen Mahnbescheid fuer einen Glaeubiger, den
     * es nicht gibt.
     *
     * @return list<Claim>
     */
    private function bundledAround(Claim $anchor): array
    {
        return $this->claims->openFor($anchor->debtor()->partyId(), $anchor->source()->creditorIdentity());
    }

    /**
     * Je Forderung ihre Zinsstaffel.
     *
     * @param list<Claim> $claims
     *
     * @return list<array{claim: Claim, interest: InterestSchedule}>
     */
    private function rowsOf(array $claims, DateTimeImmutable $on): array
    {
        return array_map(
            fn (Claim $claim): array => ['claim' => $claim, 'interest' => $this->interest->of($claim, $on)],
            $claims,
        );
    }

    /**
     * Mahnkosten und Pauschalen aller ausgestellten Schreiben.
     *
     * Sie stehen im Mahnbescheid als Nebenforderungen, und zwar einzeln: wer
     * sie in die Hauptforderung rechnete, verzinste sie mit — und das darf
     * man nicht.
     *
     * @param list<Notice> $notices
     */
    private static function extrasOf(array $notices): Money
    {
        $sum = Money::zero();

        foreach ($notices as $notice) {
            $sum = $sum->plus($notice->charges()->total());
        }

        return $sum;
    }
}
