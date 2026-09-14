<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Domain;

use DateTimeImmutable;

interface AdvancePaymentRepository
{
    public function byId(string $id): ?AdvancePayment;

    /**
     * @param list<AdvancePayment> $payments
     */
    public function saveAll(array $payments): void;

    /**
     * Was nicht mehr faellig ist, verschwindet.
     *
     * Eintraege, auf denen eine Abrechnung steht, gehoeren hier nicht herein
     * — sie werden vorher aussortiert.
     *
     * @param list<AdvancePayment> $payments
     */
    public function removeAll(array $payments): void;

    /**
     * @param list<string> $unitIds
     *
     * @return list<AdvancePayment> zeitlich sortiert
     */
    public function forYear(array $unitIds, int $fiscalYear): array;

    /**
     * Alles, was bis zu diesem Tag faellig war — ueber Jahresgrenzen hinweg.
     *
     * Der Vermoegensbericht fragt nach Rueckstaenden und nicht nach einem
     * Jahr: was 2025 offen blieb, schuldet die Einheit am 31.12.2026 immer
     * noch.
     *
     * @param list<string> $unitIds
     *
     * @return list<AdvancePayment> zeitlich sortiert
     */
    public function dueUntil(array $unitIds, DateTimeImmutable $day): array;

    /**
     * Alles, was **vor** diesem Tag faellig war und nicht als gezahlt gilt.
     *
     * Vor und nicht bis: am Faelligkeitstag laeuft die Frist noch, und wer
     * am Abend zahlt, hat gezahlt. Verzug beginnt am Tag danach (§ 286
     * Abs. 2 Nr. 1, § 187 Abs. 1 BGB) — eine Mahnung am Faelligkeitstag
     * waere verfrueht und die Zinsen darauf unbegruendet.
     *
     * Ueber alle Einheiten: das Mahnwesen fragt nicht nach einem Objekt.
     * Gefiltert wird in der Abfrage und nicht danach — wer alles laedt, um
     * neunundneunzig Prozent wegzuwerfen, laedt bei zweihundert Wohnungen
     * zwoelftausend Zeilen fuer zwoelf.
     *
     * @return list<AdvancePayment> die aelteste Faelligkeit zuerst
     */
    public function unsettledBefore(DateTimeImmutable $day): array;
}
