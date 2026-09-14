<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Dunning\Domain\CreditorsDoNotMatch;
use App\Module\Dunning\Domain\DunningLevel;
use App\Module\Dunning\Domain\LevelOutOfOrder;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeLine;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Das Schreiben zusammenstellen — und aktuell halten.
 *
 * Ein Entwurf traegt seine Zeilen von Anfang an, aber sie werden bei jedem
 * Aufruf neu gerechnet: die Zinsen wachsen taeglich, und der offene Betrag
 * wird **neu aus den Finanzen gelesen**. Eine Mahnung, die mehr fordert als
 * offen ist, ist ein Rechtsproblem und keine Unschaerfe.
 *
 * Beim Ausstellen hoert das auf — dann steht, was steht.
 */
final readonly class ComposeNotice
{
    public function __construct(
        private NoticeRepository $notices,
        private ClaimRepository $claims,
        private DunningSettings $settings,
        private TheReference $reference,
        private RereadTheArrears $reread,
        private TheFlatFee $flatFee,
        private TheInterest $interest,
    ) {
    }

    /**
     * Einen Entwurf anlegen — oder den zurueckgeben, den es schon gibt.
     *
     * Zwei offene Entwuerfe an denselben Schuldner waeren zwei Briefe mit
     * zwei Fristen ueber dieselbe Sache.
     *
     * @param list<Claim> $claims
     *
     * @throws CreditorsDoNotMatch
     * @throws LevelOutOfOrder
     */
    public function draftFor(
        string $debtorPartyId,
        CreditorIdentity $creditor,
        array $claims,
        DateTimeImmutable $on,
    ): Notice {
        $open = $this->notices->openDraftFor($debtorPartyId, $creditor);

        if (null !== $open) {
            $this->refresh($open, $on);

            return $open;
        }

        $level = $this->nextLevelFor($debtorPartyId, $creditor)
            ?? throw LevelOutOfOrder::itDoesNotFollow();

        $notice = new Notice(
            $this->reference->forA($debtorPartyId, $creditor),
            $level,
            $on->modify('+'.$this->settings->daysFor($level).' days'),
        );
        $this->notices->save($notice);
        $this->cover($notice, $claims, $on);

        return $notice;
    }

    /** Ein Entwurf ist nie hinausgegangen — er verschwindet mitsamt seinen Zeilen. */
    public function discard(Notice $notice): void
    {
        $this->notices->remove($notice);
    }

    /**
     * Welche Stufe als Naechstes kommt — die erste ist die Zahlungserinnerung.
     *
     * **Null heisst: keine mehr.** Nach der letzten Mahnung folgt kein
     * Schreiben, sondern das gerichtliche Mahnverfahren. Ohne diese Antwort
     * entstuende ein Entwurf, den niemand je ausstellen koennte — und das
     * waere ein Knopf, der in eine Sackgasse fuehrt.
     */
    public function nextLevelFor(string $debtorPartyId, CreditorIdentity $creditor): ?DunningLevel
    {
        $last = $this->notices->lastIssuedFor($debtorPartyId, $creditor);

        return null === $last ? DunningLevel::Reminder : $last->level()->next();
    }

    /**
     * Die Forderungen eines Entwurfs — aus seinen Zeilen abgeleitet.
     *
     * @return list<Claim>
     */
    public function claimsOf(Notice $notice): array
    {
        $found = [];

        foreach ($notice->lines() as $line) {
            $claim = $this->claims->byId($line->claimId());

            if (null !== $claim) {
                $found[] = $claim;
            }
        }

        return $found;
    }

    /**
     * Die Zeilen neu rechnen — Betrag aus den Finanzen, Zinsen bis heute.
     *
     * @throws CreditorsDoNotMatch
     */
    public function refresh(Notice $notice, DateTimeImmutable $on): void
    {
        $this->cover($notice, $this->claimsOf($notice), $on);
    }

    /**
     * Die Nebenforderungen setzen.
     *
     * Die Mahnkosten sagt der Bearbeiter, die Pauschale rechnet die
     * Anwendung: er entscheidet nur, ob sie ueberhaupt angesetzt wird.
     */
    public function charge(Notice $notice, Money $costs, bool $wantsTheFlatFee): void
    {
        $notice->charge($costs, $wantsTheFlatFee);
        $notice->chargeTheFlatFee($this->flatFee->on($notice, $this->claimsOf($notice)));
        $this->notices->save($notice);
    }

    /**
     * Das Schreiben deckt genau diese Forderungen ab.
     *
     * @param list<Claim> $claims
     *
     * @throws CreditorsDoNotMatch
     */
    public function cover(Notice $notice, array $claims, DateTimeImmutable $on): void
    {
        self::refuseAForeignCreditor($notice, $claims);
        $notice->clearLines();
        $covered = $this->lined($notice, $claims, $on);
        $notice->chargeTheFlatFee($this->flatFee->on($notice, $covered));
        $this->notices->save($notice);
    }

    /**
     * Je Forderung eine Zeile — und zurueck kommt, was uebrig blieb.
     *
     * **Was inzwischen bezahlt ist, faellt heraus.** Der Abgleich mit den
     * Finanzen kann eine Forderung erledigen; eine Zeile ueber 0,00 € waere
     * danach eine Mahnung ueber nichts, und bliebe sie stehen, liesse sich
     * ein bereits bezahlter Entwurf als leeres Schreiben ausstellen.
     *
     * @param list<Claim> $claims
     *
     * @return list<Claim> die, die eine Zeile bekamen
     */
    private function lined(Notice $notice, array $claims, DateTimeImmutable $on): array
    {
        $covered = [];

        foreach ($claims as $claim) {
            $this->reread->of($claim, $on);

            if (!$claim->arrears()->isOpen() || $claim->open()->isZero()) {
                continue;
            }

            $covered[] = $claim;

            new NoticeLine(
                $notice,
                $claim->id(),
                $claim->subject(),
                $claim->open(),
                $claim->arrears()->dueOn(),
                $claim->arrears()->beginsOn(),
                $this->interest->of($claim, $on)->total(),
            );
        }

        return $covered;
    }

    /**
     * Ein Schreiben, ein Glaeubiger — und der ist vollstaendig gemeint.
     *
     * @param list<Claim> $claims
     *
     * @throws CreditorsDoNotMatch
     */
    private static function refuseAForeignCreditor(Notice $notice, array $claims): void
    {
        foreach ($claims as $claim) {
            if (!$claim->source()->creditorIdentity()->equals($notice->creditorIdentity())) {
                throw CreditorsDoNotMatch::inOneNotice();
            }
        }
    }
}
