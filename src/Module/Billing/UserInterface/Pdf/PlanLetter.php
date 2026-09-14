<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Billing\UserInterface\Pdf;

use App\Module\Billing\Domain\Plan;
use App\Module\Billing\Domain\PlannedDocument;
use App\Module\Billing\Domain\PlanReference;
use App\Module\Billing\Domain\Resolution;
use App\Shared\Pdf\Amounts;
use App\Shared\Pdf\LetterHead;
use App\Shared\Pdf\Sheet;
use DateTimeImmutable;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Ein Einzelwirtschaftsplan als Brief — vor der Versammlung und danach.
 *
 * Was daraufsteht, verlangt § 28 Abs. 1 WEG und die Rechtsprechung dazu: die
 * voraussichtlichen Ausgaben nach Grund und Hoehe nachpruefbar, die Zufuehrung
 * zur Erhaltungsruecklage eigens ausgewiesen, und der Vorschuss, ueber den
 * beschlossen wird. „Nachpruefbar" heisst hier zweierlei — der Vorjahreswert
 * neben dem Planwert, und der Verteilerschluessel mit seiner Bezugsgroesse.
 *
 * **Zweimal dasselbe Blatt, einmal anders ueberschrieben.** Ein Plan muss vor
 * der Versammlung herausgehen, sonst koennte niemand darueber beschliessen;
 * danach geht er noch einmal heraus, dann als das, was gilt. Die Vorlage sagt
 * das auch: sie traegt „Beschlussvorlage" im Betreff und den Satz, dass ueber
 * diese Vorschuesse noch zu beschliessen ist.
 *
 * Gerechnet wird beide Male dasselbe — {@see PlannedDocument} ist die Form, in
 * der ein Vorschlag und ein eingefrorenes Dokument gleich aussehen. Zwei Wege
 * zum Blatt liefen frueher oder spaeter auseinander.
 *
 * Dieselbe Maschinerie wie bei der Abrechnung: FPDF, DIN 5008 Form B. Kopf und
 * Anschriftfeld setzt {@see LetterHead}.
 */
final readonly class PlanLetter
{
    public function __construct(
        private LetterHead $letterhead,
        private TranslatorInterface $translator,
        private Amounts $amounts,
        private PlanTable $table,
    ) {
    }

    /**
     * @param DateTimeImmutable $on         das Briefdatum — der Beschlusstag, bei
     *                                      einer Vorlage der Tag der Herausgabe
     * @param bool              $isProposal ob das Blatt noch zur Beschlussfassung geht
     */
    public function of(Plan $plan, PlannedDocument $document, DateTimeImmutable $on, bool $isProposal): string
    {
        $sheet = new Sheet($on);
        $sheet->AddPage();

        $this->letterhead->head($sheet);
        $this->letterhead->address($sheet, $document->recipientLabel, $document->recipientAddress);
        $this->letterhead->info($sheet, PlanReference::of($plan, $document->unitNumber)->toString(), $on->format('d.m.Y'));

        $at = $this->subject($sheet, $plan, $document, $isProposal);
        $at = $this->table->of($sheet, $document, $at);
        $at = $this->advance($sheet, $plan, $document, $at);
        $this->decision($sheet, $plan, $at, $isProposal);

        return $sheet->bytes();
    }

    private function subject(Sheet $sheet, Plan $plan, PlannedDocument $document, bool $isProposal): float
    {
        $at = 103.0;
        $sheet->put(Sheet::LEFT, $at, $this->translator->trans(
            $isProposal ? 'billing.plan.pdf.subject_proposal' : 'billing.plan.pdf.subject',
            ['%year%' => $plan->period()->year()],
        ), 12.0, 'B');
        $sheet->put(Sheet::LEFT, $at + 7.0, \sprintf(
            '%s · %s – %s',
            $document->unitLabel,
            $plan->period()->from()->format('d.m.Y'),
            $plan->period()->to()->format('d.m.Y'),
        ), 9.0);

        return $at + 16.0;
    }

    /**
     * Der Vorschuss — die Zahl, ueber die beschlossen wird.
     *
     * Jahresanteil und Rate stehen beide da. Sie gehen um wenige Cent
     * auseinander, weil ein Dauerauftrag jeden Monat derselbe Betrag ist und
     * ein Jahresanteil selten durch zwoelf geht; die Jahresabrechnung gleicht
     * das aus. Nur eine der beiden zu drucken hiesse, die Frage zu verstecken.
     */
    private function advance(Sheet $sheet, Plan $plan, PlannedDocument $document, float $at): float
    {
        $terms = $plan->terms();
        $at = $sheet->heading($at, $this->translator->trans('billing.plan.pdf.advance'));

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans($terms->interval()->labelKey()), 9.0);
        $sheet->putRight(210.0 - Sheet::RIGHT, $at, $this->amounts->money($document->advance()), 11.0, 'B');
        $at += 6.0;

        $sheet->put(Sheet::LEFT, $at, $this->translator->trans('billing.plan.pdf.first_due', [
            '%day%' => $terms->firstDueOn()->format('d.m.Y'),
        ]), 8.0);

        return $at + 10.0;
    }

    /** Der Beschluss — oder der Hinweis, dass er noch aussteht. */
    private function decision(Sheet $sheet, Plan $plan, float $at, bool $isProposal): void
    {
        $text = $isProposal
            ? $this->translator->trans('billing.plan.pdf.to_be_decided')
            : $this->decided($plan->resolution());

        if ('' !== $text) {
            $sheet->putLines(Sheet::LEFT, $at, 160.0, $text, 8.0);
        }
    }

    /**
     * Der Beschlusssatz, aus seinen Teilen.
     *
     * Zusammengesetzt und nicht als ein Satz mit Platzhaltern: Ergebnis und
     * Beschlussnummer sind freiwillig, und „am 14.11.2026.  ." waere ein Satz,
     * dem man die leeren Felder ansieht.
     */
    private function decided(Resolution $resolution): string
    {
        if (!$resolution->isRecorded()) {
            return '';
        }

        $parts = [$this->translator->trans('billing.plan.pdf.decided', [
            '%day%' => $resolution->decidedOn()?->format('d.m.Y') ?? '',
        ])];

        if ('' !== $resolution->outcome()) {
            $parts[] = $resolution->outcome();
        }

        if ('' !== $resolution->number()) {
            $parts[] = $this->translator->trans('billing.plan.pdf.decision_number', [
                '%number%' => $resolution->number(),
            ]);
        }

        return implode(' · ', $parts);
    }
}
