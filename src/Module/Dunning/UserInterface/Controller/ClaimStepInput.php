<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\StartClaim;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\Creditor;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ein Schritt der eingetragenen Forderung, gelesen.
 *
 * Zwei Schritte nehmen einen Posten an — der erste legt die Forderung dabei
 * an, der zweite haengt weitere desselben Schuldners daran. Der dritte prueft
 * nur.
 */
final readonly class ClaimStepInput
{
    public function __construct(
        private StartClaim $start,
        private ClaimRepository $claims,
        private DebtorOfAUnit $debtors,
    ) {
    }

    /**
     * Der erste Schritt: Einheit, Glaeubiger und der erste Posten.
     *
     * @return array{errors: array<string, string>, claim: Claim|null}
     */
    public function create(Request $request): array
    {
        $role = Creditor::tryFrom($request->request->getString('creditor')) ?? Creditor::Owner;
        $entry = self::entryFrom($request);

        if (null === $entry) {
            return ['errors' => ['rows' => 'dunning.error.needs_an_amount'], 'claim' => null];
        }

        // Der Glaeubiger wird zum Tag der Faelligkeit ermittelt und nicht zu
        // heute: wer die Wohnung inzwischen verkauft hat, war damals der
        // Vermieter.
        $found = $this->debtors->of($request->request->getString('unitId'), $role, $entry['dueOn']);

        if (null === $found) {
            return ['errors' => ['unit' => 'dunning.error.needs_a_debtor'], 'claim' => null];
        }

        return ['errors' => [], 'claim' => $this->start->entered(
            $found['partyId'],
            self::commercial($request, $found['company']),
            $found['creditor'],
            $found['unitId'],
            $entry['subject'],
            $entry['amount'],
            $entry['dueOn'],
            $entry['defaultFrom'],
        )];
    }

    /**
     * Ein weiterer Schritt, angewandt.
     *
     * Nur der zweite nimmt etwas an. Der dritte zeigt bloss, was
     * zusammengekommen ist.
     *
     * @return array{errors: array<string, string>, claim: Claim|null}
     */
    public function apply(string $step, Request $request, Claim $first): array
    {
        return ClaimFlow::MORE === $step ? $this->add($request, $first) : ['errors' => [], 'claim' => null];
    }

    /**
     * Die offenen Forderungen derselben Paarung — was der Ablauf zusammentrug.
     *
     * @return list<Claim>
     */
    public function gathered(Claim $first): array
    {
        return $this->claims->openFor($first->debtor()->partyId(), $first->source()->creditorIdentity());
    }

    /**
     * Vorbelegt aus der Art der Partei, uebersteuerbar im Formular.
     *
     * Eine Firma ist nie Verbraucher; ein Mensch kann trotzdem Unternehmer
     * sein. Darum ist das Haekchen die Antwort und die Art nur der Vorschlag.
     */
    private static function commercial(Request $request, bool $isACompany): bool
    {
        return $request->request->has('commercial')
            ? $request->request->getBoolean('commercial')
            : $isACompany;
    }

    /**
     * Ein weiterer Posten — dieselbe Einheit, dieselbe Rolle.
     *
     * Beides steht schon fest und wird nicht noch einmal gefragt. **Wer
     * dahintersteht, wird aber neu bestimmt**, und zwar zum Tag dieses
     * Postens: wechselt die Einheit zum 1. April den Eigentuemer, gehoert die
     * Aprilmiete dem neuen und nicht dem, dem die Maerzmiete gehoerte. Beide
     * in ein Schreiben zu nehmen, hiesse dem einen das Geld des anderen
     * mitzufordern.
     *
     * Faellt dabei eine andere Paarung heraus, entsteht daraus von selbst ein
     * eigener Vorgang: gebuendelt wird nach Schuldner und Glaeubiger.
     *
     * @return array{errors: array<string, string>, claim: Claim|null}
     */
    private function add(Request $request, Claim $first): array
    {
        $entry = self::entryFrom($request);

        if (null === $entry) {
            return ['errors' => [], 'claim' => null];
        }

        $found = $this->debtors->of(
            $first->source()->unitId() ?? '',
            $first->source()->creditor(),
            $entry['dueOn'],
        );

        if (null === $found) {
            return ['errors' => ['unit' => 'dunning.error.needs_a_debtor'], 'claim' => null];
        }

        return ['errors' => [], 'claim' => $this->start->entered(
            $found['partyId'],
            self::tradingLike($first, $found),
            $found['creditor'],
            $found['unitId'],
            $entry['subject'],
            $entry['amount'],
            $entry['dueOn'],
            $entry['defaultFrom'],
        )];
    }

    /**
     * Das Haekchen des ersten Postens gilt weiter — aber nur fuer denselben.
     *
     * „Gewerblicher Schuldner" ist eine Angabe ueber einen Menschen und nicht
     * ueber die Einheit. Steht zu diesem Tag ein anderer dort, faengt die
     * Angabe wieder bei der Art seiner Partei an.
     *
     * @param array{partyId: string, company: bool, ...} $found
     */
    private static function tradingLike(Claim $first, array $found): bool
    {
        return $found['partyId'] === $first->debtor()->partyId()
            ? $first->debtor()->isCommercial()
            : $found['company'];
    }

    /**
     * Ein Posten aus dem Formular — null, wenn kein Betrag dasteht.
     *
     * Ohne Betrag ist es keine Forderung, sondern ein leeres Feld. Der zweite
     * Schritt lebt davon: wer nichts eintraegt und „Weiter" drueckt, hat eben
     * nichts nachzutragen.
     *
     * @return array{subject: string, amount: Money, dueOn: DateTimeImmutable, defaultFrom: DateTimeImmutable}|null
     */
    private static function entryFrom(Request $request): ?array
    {
        $amount = self::money($request->request->getString('amount'));

        if (null === $amount || $amount->isZero() || $amount->isNegative()) {
            return null;
        }

        $dueOn = self::date($request, 'dueOn') ?? new DateTimeImmutable('today');
        $from = self::date($request, 'defaultFrom') ?? $dueOn->modify('+1 day');

        return [
            'subject' => $request->request->getString('subject'),
            'amount' => $amount,
            'dueOn' => $dueOn,
            'defaultFrom' => $from < $dueOn ? $dueOn : $from,
        ];
    }

    /**
     * Was nicht wie ein Datum aussieht, gilt als nicht angegeben.
     *
     * Das Feld ist ein Datumsfeld, und der Browser laesst nichts anderes zu;
     * wer es doch schafft, bekommt die Vorgabe und keinen Abbruch.
     */
    private static function date(Request $request, string $field): ?DateTimeImmutable
    {
        try {
            return DateInput::orNull($request, $field);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function money(string $input): ?Money
    {
        try {
            return MoneyInput::orNull($input);
        } catch (UnreadableAmount) {
            return null;
        }
    }
}
