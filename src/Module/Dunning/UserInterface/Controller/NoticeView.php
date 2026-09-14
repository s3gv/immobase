<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\ComposeNotice;
use App\Module\Dunning\Application\DunningSettings;
use App\Module\Dunning\Application\IssueNotice;
use App\Module\Dunning\Application\TheFlatFee;
use App\Module\Dunning\Application\TheInterest;
use App\Module\Dunning\Domain\BaseRateRepository;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\ClaimRepository;
use App\Module\Dunning\Domain\Notice;
use DateTimeImmutable;

/**
 * Was von einem Mahnschreiben auf dem Bildschirm steht.
 *
 * Bei einem Entwurf wird gerechnet, bei einem ausgestellten gelesen — und
 * beide Male kommt dieselbe Gestalt heraus. Die Vorschau zeigt damit, was
 * hinausgehen wird, und das zugestellte Schreiben, was hinausging.
 */
final readonly class NoticeView
{
    public function __construct(
        private ComposeNotice $compose,
        private IssueNotice $issue,
        private TheInterest $interest,
        private ClaimRepository $claims,
        private BaseRateRepository $rates,
        private DunningSettings $settings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Notice $notice): array
    {
        $on = $notice->issuedOn() ?? new DateTimeImmutable('today');
        $claims = $this->compose->claimsOf($notice);
        $recipients = $notice->isDraft() ? $this->issue->recipientsOf($claims) : $notice->recipients();

        return [
            'notice' => $notice,
            'claims' => $claims,
            'recipients' => $recipients,
            'schedules' => $this->schedulesOf($claims, $on),
            'iban' => $recipients->payeeIban(),
            'missing' => $notice->isDraft() ? $this->issue->gapsOf($notice, $claims, $recipients, $on) : [],
            'latestRate' => $this->rates->all()->latest(),
            'flatFee' => $this->settings->flatFee(),
            'mayClaimTheFlatFee' => TheFlatFee::mayBeCharged($claims),
            'on' => $on,
        ];
    }

    /**
     * Die offenen Forderungen, die noch dazugehoert haetten.
     *
     * Fuer den ersten Schritt: was der Schuldner sonst noch schuldet und
     * nicht im Schreiben steht. Eine Frist, ein Schreiben — wer eine
     * Forderung vergisst, schreibt zweimal.
     *
     * @return list<Claim>
     */
    public function alsoOpen(Notice $notice): array
    {
        $covered = array_flip(array_map(
            static fn (Claim $claim): string => $claim->id(),
            $this->compose->claimsOf($notice),
        ));

        return array_values(array_filter(
            $this->claims->openFor($notice->debtorPartyId(), $notice->creditorIdentity()),
            static fn (Claim $claim): bool => !isset($covered[$claim->id()]),
        ));
    }

    /**
     * @param list<Claim> $claims
     *
     * @return array<string, \App\Module\Dunning\Domain\InterestSchedule>
     */
    private function schedulesOf(array $claims, DateTimeImmutable $on): array
    {
        $found = [];

        foreach ($claims as $claim) {
            $found[$claim->id()] = $this->interest->of($claim, $on);
        }

        return $found;
    }
}
