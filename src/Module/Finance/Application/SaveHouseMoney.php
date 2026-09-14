<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\Application;

use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\HouseMoney;
use App\Module\Finance\Domain\HouseMoneyRepository;
use App\Module\Finance\Domain\StepAlreadyStartsThatDay;
use App\Module\Finance\Domain\UnknownUnit;
use App\Module\Property\Contract\UnitDirectory;
use App\Shared\Money\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Die Hausgeldstaffel einer Einheit pflegen.
 *
 * Eine Regel haelt sie lesbar: zu einem Tag hoechstens eine Stufe. Ohne sie
 * waere nicht entscheidbar, welche gilt.
 */
final readonly class SaveHouseMoney
{
    public function __construct(
        private HouseMoneyRepository $steps,
        private UnitDirectory $units,
    ) {
    }

    /**
     * @throws UnknownUnit
     * @throws StepAlreadyStartsThatDay
     * @throws InvalidArgumentException
     */
    public function add(
        string $unitId,
        DateTimeImmutable $startsOn,
        Money $amount,
        Interval $interval,
        string $note = '',
    ): HouseMoney {
        if ([] === $this->units->byIds([$unitId])) {
            throw UnknownUnit::of($unitId);
        }

        $this->refuseTwice($unitId, $startsOn, null);

        $step = new HouseMoney($unitId, $startsOn, $amount, $interval);
        $step->noteThat($note);
        $this->saved($step);

        return $step;
    }

    /**
     * @throws StepAlreadyStartsThatDay
     * @throws InvalidArgumentException
     */
    public function change(
        HouseMoney $step,
        DateTimeImmutable $startsOn,
        Money $amount,
        Interval $interval,
        string $note = '',
    ): void {
        $this->refuseTwice($step->unitId(), $startsOn, $step);

        $step->moveTo($startsOn);
        $step->charge($amount, $interval);
        $step->noteThat($note);

        // Wer hier vorbeikommt, hat den Betrag getippt. Kam die Stufe aus
        // einem Wirtschaftsplan, bleibt das lesbar — sie traegt jetzt beides.
        $step->changedByHand();
        $this->saved($step);
    }

    public function drop(HouseMoney $step): void
    {
        $this->steps->remove($step);
    }

    /**
     * Speichern — und die Absage der Datenbank in die des Fachs uebersetzen.
     *
     * Zwischen der Pruefung oben und dem Speichern liegt eine Luecke. Zwei
     * gleichzeitig abgeschickte Formulare lesen darin dieselbe Staffel, und
     * der zweite laeuft in den eindeutigen Index. Die Anwendung gibt die
     * lesbare Absage, die Datenbank bleibt die letzte Linie — aber ihre
     * Meldung ist keine Antwort, die jemand versteht.
     *
     * @throws StepAlreadyStartsThatDay
     */
    private function saved(HouseMoney $step): void
    {
        try {
            $this->steps->save($step);
        } catch (UniqueConstraintViolationException) {
            throw new StepAlreadyStartsThatDay();
        }
    }

    /**
     * @throws StepAlreadyStartsThatDay
     */
    private function refuseTwice(string $unitId, DateTimeImmutable $startsOn, ?HouseMoney $itself): void
    {
        $schedule = $this->steps->forUnits([$unitId])[$unitId] ?? null;
        $day = $startsOn->format('Y-m-d');

        foreach ($schedule?->steps() ?? [] as $step) {
            // Ueber die Zeichenkette und nicht ueber ===: zwei
            // DateTimeImmutable mit demselben Tag sind zwei Objekte.
            if ($step->id() !== $itself?->id() && $step->startsOn()->format('Y-m-d') === $day) {
                throw new StepAlreadyStartsThatDay();
            }
        }
    }
}
