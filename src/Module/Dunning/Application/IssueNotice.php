<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\LevelOutOfOrder;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeIsIncomplete;
use App\Module\Dunning\Domain\NoticeIsIssued;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Module\Dunning\Domain\Recipients;
use App\Module\Property\Contract\PropertyDirectory;
use DateTimeImmutable;

/**
 * Das Schreiben geht hinaus — und wird dabei eingefroren.
 *
 * Danach liegt es beim Schuldner, die Frist laeuft, und die Zinsen laufen
 * weiter. Rechnete das Blatt beim naechsten Aufruf neu, zeigte es andere
 * Zahlen als das Papier in der Hand des Empfaengers.
 *
 * **Die Stufe muss folgen.** Eine letzte Mahnung ohne vorausgegangene erste
 * ist keine letzte, und eine zweite Zahlungserinnerung nach einer Mahnung
 * waere ein Rueckschritt, den der Empfaenger zu Recht als Nachgeben liest.
 *
 * **Die Pauschale gibt es einmal je Forderung.** Sie wird an der Forderung
 * vermerkt und nicht am Schreiben — sonst stuende sie beim naechsten
 * Schreiben wieder da.
 */
final readonly class IssueNotice
{
    public function __construct(
        private NoticeRepository $notices,
        private ClaimRepository $claims,
        private BaseRateRepository $rates,
        private ComposeNotice $compose,
        private Addressed $addressed,
        private PropertyDirectory $properties,
    ) {
    }

    /**
     * @throws NoticeIsIssued
     * @throws NoticeIsIncomplete
     * @throws LevelOutOfOrder
     */
    public function issue(Notice $notice, DateTimeImmutable $on): void
    {
        if (!$notice->isDraft()) {
            throw NoticeIsIssued::already();
        }

        $this->refuseAStepOutOfOrder($notice);

        // Der Zinsstichtag ist der Ausstellungstag: ein Entwurf von
        // vorletzter Woche zeigte sonst Zinsen, die auf dem Blatt dann
        // anders dastuenden.
        $this->compose->refresh($notice, $on);
        $claims = $this->compose->claimsOf($notice);
        $recipients = $this->recipientsOf($claims);

        if ([] !== $this->gapsOf($notice, $claims, $recipients, $on)) {
            throw NoticeIsIncomplete::somethingIsMissing();
        }

        $this->notices->atomically(function () use ($notice, $claims, $on, $recipients): void {
            $notice->issueOn($on, $recipients);
            $this->notices->save($notice);
            $this->markTheFlatFee($notice, $claims);
        });
    }

    /**
     * @param list<Claim> $claims
     *
     * @return list<string>
     */
    public function gapsOf(Notice $notice, array $claims, Recipients $recipients, DateTimeImmutable $on): array
    {
        return NoticeGaps::of($notice, $this->rates->all(), $recipients, $on);
    }

    /**
     * Der Stand, der beim Ausstellen eingefroren wird.
     *
     * Bei einem Entwurf ist er der Vorschlag von heute, beim ausgestellten
     * Schreiben liest man ihn dort ab, wo er festgehalten wurde.
     *
     * @param list<Claim> $claims
     */
    public function recipientsOf(array $claims): Recipients
    {
        $first = $claims[0] ?? null;

        if (null === $first) {
            return Recipients::nobody();
        }

        $creditor = $this->addressed->creditorOf($first);
        $debtor = $this->addressed->debtorOf($first);

        return Recipients::of(
            $creditor['name'],
            $creditor['address'],
            $debtor['name'],
            $debtor['address'],
            $this->ibanFor($claims),
        );
    }

    /**
     * Die Stufe muss auf die zuletzt ausgestellte folgen.
     *
     * Je Schuldner und Glaeubiger: eine letzte Mahnung ohne vorausgegangene
     * erste ist keine letzte.
     *
     * @throws LevelOutOfOrder
     */
    private function refuseAStepOutOfOrder(Notice $notice): void
    {
        $level = $this->compose->nextLevelFor($notice->debtorPartyId(), $notice->creditorIdentity());

        if (null === $level || $notice->level() !== $level) {
            throw LevelOutOfOrder::itDoesNotFollow();
        }
    }

    /**
     * Das Konto, auf das gezahlt werden soll.
     *
     * Das des Objekts — die Gemeinschaft sammelt dort ein, und der Vermieter
     * laesst dort einziehen. Ein Mahnschreiben ohne Konto ist keines.
     *
     * Nur fuer den Entwurf: ein ausgestelltes Schreiben traegt das Konto, das
     * darauf stand ({@see Recipients::payeeIban()}).
     *
     * @param list<Claim> $claims
     */
    private function ibanFor(array $claims): string
    {
        $first = $claims[0] ?? null;

        if (null === $first) {
            return '';
        }

        $propertyId = $first->source()->propertyId();

        return $this->properties->byIds([$propertyId])[$propertyId]->payeeIban ?? '';
    }

    /**
     * Die Pauschale an jeder Forderung vermerken, die sie traegt.
     *
     * **An jeder, nicht an einer.** Der Betrag im Schreiben ist die Summe
     * ueber alle — bliebe der Vermerk an einer haengen, stuenden die uebrigen
     * beim naechsten Schreiben wieder zur Wahl und waeren ein zweites Mal
     * berechnet.
     *
     * @param list<Claim> $claims
     */
    private function markTheFlatFee(Notice $notice, array $claims): void
    {
        if ($notice->charges()->flatFee()->isZero()) {
            return;
        }

        foreach ($claims as $claim) {
            if ($claim->debtor()->isCommercial() && !$claim->debtor()->flatFeeClaimed()) {
                $claim->claimTheFlatFee();
                $this->claims->save($claim);
            }
        }
    }
}
