<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\Application;

use App\Module\Billing\Domain\Statement;
use App\Module\Billing\Domain\StatementDocument;
use App\Module\Billing\Domain\StatementLine;
use App\Shared\EInvoice\CiiWriter;
use App\Shared\EInvoice\EInvoice;
use App\Shared\EInvoice\EInvoiceLine;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyFormatter;
use DateTimeImmutable;
use LogicException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Das freigegebene Abrechnungsschreiben eines Gewerbemieters als E-Rechnung.
 *
 * Eine Endrechnung ueber die Betriebskosten: je Kostenart eine Position mit
 * dem Nettoanteil, darauf die Steuer, abzueglich der vereinnahmten
 * Vorauszahlungen brutto (BT-113). Die darin enthaltene Steuer steht als
 * Hinweis dabei, weil § 14 Abs. 5 UStG sie ausgewiesen haben will und die
 * XRechnung dafuer kein eigenes Feld hat.
 *
 * Ein Guthaben ist ein negativer Zahlbetrag — so, wie es auf dem Blatt steht.
 */
final readonly class StatementAsEInvoice
{
    public function __construct(
        private TranslatorInterface $translator,
        private EInvoiceTerms $terms,
    ) {
    }

    /**
     * @throws LogicException fuer ein Schreiben ohne Umsatzsteuer oder ohne eingefrorene Angaben
     */
    public function of(Statement $statement, StatementDocument $document): EInvoice
    {
        $letting = $document->letting();
        [$data, $seller] = [$letting->eInvoice(), $letting->seller()];

        return new EInvoice(
            number: $document->reference()->toString(),
            issuedOn: self::issuedOn($document),
            seller: EInvoiceParties::seller($seller->name(), $data, $seller->taxNumber()),
            buyer: EInvoiceParties::buyer($document->recipient()->label(), $data),
            contactName: $data->contact()['name'],
            contactPhone: $data->contact()['phone'],
            contactEmail: $data->contact()['email'],
            buyerReference: $data->buyerReference(),
            contractReference: (string) $letting->tenancyNumber(),
            periodFrom: $document->period()->from(),
            periodTo: $document->period()->to(),
            lines: array_map(static fn (StatementLine $line): EInvoiceLine => new EInvoiceLine($line->costKind(), $line->net()), $document->lines()),
            rateBps: $letting->taxation()->rateBps(),
            payment: $this->terms->forStatement($data, $seller->payeeIban(), $seller->payeeName(), $document->balance(), $document->recipient()->label()),
            notes: $this->notes($statement, $document),
            precedingNumber: $statement->isCorrection() ? $document->reference()->previous()?->toString() : null,
            prepaid: self::alreadyCovered($document),
            payeeName: self::payeeOf($document),
        );
    }

    /**
     * Was vom Ergebnis nicht mehr gezahlt werden muss (BT-113).
     *
     * Die Vorauszahlungen — und bei einer Korrektur, was das vorige Schreiben
     * schon abgerechnet hat. So ist der Zahlbetrag der Differenz, die auch
     * auf dem Blatt steht, und nicht das volle neue Ergebnis. War das vorige
     * ein Guthaben, ist dieser Teil negativ.
     */
    private static function alreadyCovered(StatementDocument $document): Money
    {
        $outcome = $document->outcome();

        return $outcome->advances()->plus($outcome->settled() ?? Money::zero());
    }

    /**
     * Ein Zahlungsempfaenger neben dem Verkaeufer (BG-10) — nur, wenn gezahlt wird.
     *
     * Bei einem Guthaben fliesst Geld zum Mieter; ein Empfaenger auf
     * Vermieterseite stuende dann da, als solle er doch zahlen.
     */
    private static function payeeOf(StatementDocument $document): string
    {
        $seller = $document->letting()->seller();
        $pays = !$document->balance()->isNegative() && !$document->balance()->isZero();

        return $pays && $seller->payeeName() !== $seller->name() ? $seller->payeeName() : '';
    }

    private static function issuedOn(StatementDocument $document): DateTimeImmutable
    {
        $letting = $document->letting();
        $day = $document->releasedOn();

        if (null === $day || !$letting->isTaxed() || !$letting->eInvoice()->isCaptured()) {
            throw new LogicException('Für dieses Schreiben gibt es keine E-Rechnung.');
        }

        return $day;
    }

    /**
     * @return list<string>
     */
    private function notes(Statement $statement, StatementDocument $document): array
    {
        $outcome = $document->outcome();
        $notes = [$this->german('billing.einvoice.note.statement', [
            '%from%' => $document->period()->from()->format('d.m.Y'),
            '%to%' => $document->period()->to()->format('d.m.Y'),
            '%unit%' => $document->unitLabel(),
            '%advances%' => MoneyFormatter::format($outcome->advances(), 'de'),
            '%tax%' => MoneyFormatter::format($outcome->advancesTax(), 'de'),
            '%rate%' => CiiWriter::percent($document->letting()->taxation()->rateBps()),
        ])];

        $settled = $outcome->settled();

        if ($statement->isCorrection() && null !== $settled) {
            $notes[] = $this->german('billing.einvoice.note.correction', [
                '%settled%' => MoneyFormatter::format($settled, 'de'),
                '%balance%' => MoneyFormatter::format($document->balance(), 'de'),
            ]);
        }

        return $notes;
    }

    /**
     * @param array<string, string> $parameters
     */
    private function german(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, null, 'de');
    }
}
