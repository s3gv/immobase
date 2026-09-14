<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\Application;

use App\Module\Dunning\Domain\Arrears;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\ClaimStep;
use App\Module\Dunning\Domain\CreditorIdentity;
use App\Module\Dunning\Domain\Debtor;
use App\Module\Dunning\Domain\Source;
use App\Shared\Money\Money;
use DateTimeImmutable;

/**
 * Eine Forderung entsteht.
 *
 * Zwei Wege, und der Unterschied ist nicht nur Herkunft:
 *
 * * **Aus einer ueberfaelligen Vorauszahlung.** Faelligkeit und Betrag stehen
 *   schon da, der Verzug beginnt am Tag danach (§ 286 Abs. 2 Nr. 1 BGB, weil
 *   der Wirtschaftsplan den Zahltag kalendermaessig bestimmt). Eine Zahlung
 *   wird hoechstens einmal zur Forderung — sonst mahnt jemand sie zweimal.
 * * **Von Hand.** Kaltmiete, eine Nachzahlung aus der Abrechnung, eine
 *   Vertragsstrafe. Alles, was die Anwendung nicht selbst verfolgt, und der
 *   Verzugsbeginn kommt mit — er ist der Mahnstart, der sonst fehlte.
 */
final readonly class StartClaim
{
    public function __construct(private ClaimRepository $claims)
    {
    }

    /**
     * Aus einer ueberfaelligen Zahlung — oder die, die es dafuer schon gibt.
     *
     * Wiederverwenden statt ablehnen: wer zweimal auf „Mahnen" klickt, will
     * beide Male denselben Vorgang und keinen Fehler.
     */
    public function fromTheOverdue(OverdueItem $item): Claim
    {
        $known = $this->claims->forAdvance($item->paymentId);

        if (null !== $known) {
            return $known;
        }

        $claim = new Claim(
            Debtor::of($item->debtorPartyId, $item->debtorIsACompany),
            Source::fromAnAdvance($item->creditor, $item->unitId, $item->paymentId),
            Arrears::after($item->dueOn),
        );
        $claim->describe($item->subject);
        new ClaimStep($claim, $claim->arrears()->beginsOn(), $item->open);
        $this->claims->save($claim);

        return $claim;
    }

    /**
     * Alle ueberfaelligen Zahlungen derselben Paarung zu Forderungen machen.
     *
     * Nicht nur die angeklickte: eine Frist, ein Schreiben. Wer nur eine
     * davon aufnaehme, schriebe morgen noch einmal an denselben Menschen —
     * und der zahlte zweimal Porto in Form von Aerger.
     *
     * Dieselbe Paarung heisst: derselbe Schuldner, derselbe Glaeubiger — und
     * der ist vollstaendig gemeint. Rueckstaende bei zwei Glaeubigern schuldet
     * derselbe Mensch zwei verschiedenen Leuten und bekommt zwei Schreiben.
     *
     * Null heisst: die angeklickte Zahlung ist nicht mehr ueberfaellig. Das
     * passiert, wenn jemand sie in der Zwischenzeit als erhalten
     * gekennzeichnet hat.
     *
     * @param list<OverdueItem> $items alle ueberfaelligen Zahlungen
     *
     * @return array{debtorPartyId: string, creditor: CreditorIdentity}|null
     */
    public function theGroupAround(array $items, string $paymentId): ?array
    {
        $anchor = null;

        foreach ($items as $item) {
            $anchor = $item->paymentId === $paymentId ? $item : $anchor;
        }

        if (null === $anchor) {
            return null;
        }

        foreach ($items as $item) {
            if (self::sameGroup($item, $anchor)) {
                $this->fromTheOverdue($item);
            }
        }

        return ['debtorPartyId' => $anchor->debtorPartyId, 'creditor' => $anchor->creditor];
    }

    /** Von Hand eingetragen — mit eigenem Verzugsbeginn. */
    public function entered(
        string $debtorPartyId,
        bool $commercial,
        CreditorIdentity $creditor,
        ?string $unitId,
        string $subject,
        Money $amount,
        DateTimeImmutable $dueOn,
        DateTimeImmutable $defaultFrom,
    ): Claim {
        $claim = new Claim(
            Debtor::of($debtorPartyId, $commercial),
            Source::entered($creditor, $unitId),
            Arrears::of($dueOn, $defaultFrom < $dueOn ? $dueOn : $defaultFrom),
        );
        $claim->describe($subject);
        new ClaimStep($claim, $claim->arrears()->beginsOn(), $amount);
        $this->claims->save($claim);

        return $claim;
    }

    /** Ein Vorgang, ueber den nie geschrieben wurde, darf verschwinden. */
    public function discard(Claim $claim): void
    {
        $this->claims->remove($claim);
    }

    private static function sameGroup(OverdueItem $item, OverdueItem $anchor): bool
    {
        return $item->debtorPartyId === $anchor->debtorPartyId
            && $item->creditor->equals($anchor->creditor);
    }
}
