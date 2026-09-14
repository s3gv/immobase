<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\ComposeNotice;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\CreditorsDoNotMatch;
use App\Module\Dunning\Domain\Notice;
use App\Module\Dunning\Domain\NoticeRepository;
use App\Shared\Money\Money;
use App\Shared\Money\MoneyInput;
use App\Shared\Money\UnreadableAmount;
use App\Shared\Time\DateInput;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ein Schritt des Mahnschreibens, gelesen.
 *
 * Zwei Schritte nehmen etwas an: welche Forderungen mitkommen, und wie ernst
 * das Schreiben wird. Der dritte prueft nur.
 */
final readonly class NoticeStepInput
{
    public function __construct(
        private ComposeNotice $compose,
        private ClaimRepository $claims,
        private NoticeRepository $notices,
    ) {
    }

    /**
     * @return array<string, string> Fehler je Schritt, leer heisst angenommen
     */
    public function apply(string $step, Request $request, Notice $notice): array
    {
        return match ($step) {
            NoticeFlow::CLAIMS => $this->chooseClaims($request, $notice),
            NoticeFlow::NOTICE => $this->describe($request, $notice),
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    private function chooseClaims(Request $request, Notice $notice): array
    {
        $claims = [];

        foreach ($request->request->all('claims') as $id) {
            $claim = \is_string($id) && '' !== $id ? $this->claims->byId($id) : null;

            if (null !== $claim) {
                $claims[] = $claim;
            }
        }

        if ([] === $claims) {
            return ['claims' => 'dunning.missing.claims'];
        }

        try {
            $this->compose->cover($notice, $claims, new DateTimeImmutable('today'));
        } catch (CreditorsDoNotMatch $problem) {
            return ['claims' => $problem->getMessage()];
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function describe(Request $request, Notice $notice): array
    {
        // Das Formular schickt die Entscheidung, nicht den Betrag: wie hoch
        // die Pauschale ausfaellt und ob sie ueberhaupt zulaessig ist,
        // entscheidet die Anwendung.
        try {
            $this->compose->charge(
                $notice,
                MoneyInput::orNull($request->request->getString('costs')) ?? Money::zero(),
                $request->request->getBoolean('flatFee'),
            );
        } catch (UnreadableAmount) {
            return ['notice' => 'finance.error.amount_invalid'];
        }

        $payBy = DateInput::orNull($request, 'payBy');

        if (null !== $payBy) {
            $notice->payUntil($payBy);
        }

        $notice->remark($request->request->getString('note'));
        $this->notices->save($notice);

        return [];
    }
}
