<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\UserInterface\Controller;

use App\Module\Tenancy\Application\AssignTenants;
use App\Module\Tenancy\Application\SaveTenancy;
use App\Module\Tenancy\Domain\Deposit;
use App\Module\Tenancy\Domain\DepositKind;
use App\Module\Tenancy\Domain\Payment;
use App\Module\Tenancy\Domain\PaymentDue;
use App\Module\Tenancy\Domain\PaymentMethod;
use App\Module\Tenancy\Domain\Taxation;
use App\Module\Tenancy\Domain\TaxOnlyForCommercial;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\Term;
use App\Module\Tenancy\Domain\UnitAlreadyLet;
use App\Module\Tenancy\Domain\UnknownTenant;
use App\Module\Tenancy\Domain\UnknownUnit;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Number\WholeNumber;
use App\Shared\Text\Trimmed;
use App\Shared\Time\DateInput;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Schritt entgegennimmt — und was daran nicht stimmt.
 *
 * Je Schritt eine Methode. Gemeinsam ist ihnen nur die Form der Antwort: eine
 * Liste von Fehlern je Feld, leer heisst gespeichert.
 */
final readonly class TenancyStepInput
{
    public function __construct(
        private SaveTenancy $save,
        private AssignTenants $tenants,
    ) {
    }

    /**
     * @return array<string, string> Feldname auf Uebersetzungsschluessel
     */
    public function apply(string $step, Request $request, Tenancy $tenancy): array
    {
        return match ($step) {
            TenancyFlow::TENANTS => $this->assign($request, $tenancy),
            TenancyFlow::TERM => $this->term($request, $tenancy),
            TenancyFlow::RENT => $this->payment($request, $tenancy),
            TenancyFlow::DEPOSIT => $this->deposit($request, $tenancy),
            TenancyFlow::NOTE => $this->note($request, $tenancy),
            default => $this->basics($request, $tenancy),
        };
    }

    /**
     * Die Kennungen der Mieter aus dem Formular.
     *
     * Oeffentlich, weil der Controller sie nach einem Fehler ein zweites Mal
     * braucht: dann steht im Formular wieder das Gewaehlte.
     *
     * @return list<string>
     */
    public static function tenantsFrom(Request $request): array
    {
        return array_values(array_unique(array_filter(
            $request->request->all('tenants'),
            \is_string(...),
        )));
    }

    /**
     * @return array<string, string>
     */
    private function basics(Request $request, Tenancy $tenancy): array
    {
        $unitId = Trimmed::orNull($request->request->getString('unitId'));

        if (null === $unitId) {
            return ['unitId' => 'tenancy.error.unit_required'];
        }

        try {
            $this->save->moveTo($tenancy, $unitId);
        } catch (UnknownUnit) {
            return ['unitId' => 'tenancy.error.unit_unknown'];
        } catch (UnitAlreadyLet) {
            return ['unitId' => 'tenancy.error.unit_let'];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function note(Request $request, Tenancy $tenancy): array
    {
        $this->save->noteThat($tenancy, $request->request->getString('note'));

        return [];
    }

    /**
     * Nur die Mieter — die Personenzahl hat eigene Adressen, weil sie sich
     * mit der Zeit aendert und jeder Stand erhalten bleiben muss.
     *
     * @return array<string, string>
     */
    private function assign(Request $request, Tenancy $tenancy): array
    {
        try {
            $this->tenants->to($tenancy, self::tenantsFrom($request));
        } catch (UnknownTenant) {
            return ['tenants' => 'tenancy.error.tenant_unknown'];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function term(Request $request, Tenancy $tenancy): array
    {
        try {
            $dates = [
                DateInput::orNull($request, 'startsOn'),
                DateInput::orNull($request, 'endsOn'),
                DateInput::orNull($request, 'handedOverOn'),
            ];
        } catch (InvalidArgumentException) {
            return ['term' => 'tenancy.error.date_invalid'];
        }

        try {
            $term = Term::of(
                ...$dates,
                noticePeriodMonths: WholeNumber::orNull($request->request->getString('noticePeriodMonths')),
            );
        } catch (InvalidArgumentException) {
            return ['term' => 'tenancy.error.term_invalid'];
        }

        $this->save->runFor($tenancy, $term);

        return [];
    }

    /**
     * Nur Zahlungsweise und Faelligkeit — die Staffel selbst hat eigene
     * Adressen, weil eine Stufe einzeln dazukommt und einzeln verschwindet.
     *
     * @return array<string, string>
     */
    private function payment(Request $request, Tenancy $tenancy): array
    {
        $eInvoice = EInvoiceTermsInput::read($request);

        if ([] !== $eInvoice['errors']) {
            return $eInvoice['errors'];
        }

        $this->save->paidBy($tenancy, Payment::of(
            PaymentMethod::tryFrom($request->request->getString('paymentMethod')) ?? PaymentMethod::Transfer,
            PaymentDue::tryFrom($request->request->getString('paymentDue')) ?? PaymentDue::ThirdWorkingDay,
            $eInvoice['terms'],
        ));

        return $this->taxation($request, $tenancy);
    }

    /**
     * Die Umsatzsteuervereinbarung.
     *
     * Der Satz kommt als „19" oder „19,0" herein und wird zu Basispunkten —
     * dieselbe Lesehilfe wie beim Darlehen, und aus demselben Grund: ein
     * Prozentsatz als Dezimalzahl ist genau die Zahl, die man nicht rechnen
     * kann.
     *
     * @return array<string, string>
     */
    private function taxation(Request $request, Tenancy $tenancy): array
    {
        try {
            $charged = '' !== $request->request->getString('vatCharged');
            $rateBps = MoneyInput::orNull($request->request->getString('vatRate'))?->cents() ?? 0;
            $this->save->taxAs($tenancy, $charged ? Taxation::at($rateBps) : Taxation::exempt());
        } catch (UnreadableAmount) {
            return ['rent' => 'tenancy.error.vat_rate_invalid'];
        } catch (TaxOnlyForCommercial $problem) {
            return ['rent' => $problem->getMessage()];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function deposit(Request $request, Tenancy $tenancy): array
    {
        $raw = Trimmed::orNull($request->request->getString('depositAmount'));

        try {
            $receivedOn = DateInput::orNull($request, 'depositReceivedOn');
        } catch (InvalidArgumentException) {
            return ['depositReceivedOn' => 'tenancy.error.date_invalid'];
        }

        try {
            // Betrag und Vorzeichen gehoeren zusammen ans Betragsfeld: eine
            // negative Kaution ist dort falsch und nicht beim Datum.
            $deposit = Deposit::of(
                null === $raw ? null : MoneyInput::parse($raw),
                DepositKind::tryFrom($request->request->getString('depositKind')),
                $receivedOn,
            );
        } catch (InvalidArgumentException) {
            return ['depositAmount' => 'tenancy.error.deposit_amount'];
        }

        $this->save->secureWith($tenancy, $deposit);

        return [];
    }
}
