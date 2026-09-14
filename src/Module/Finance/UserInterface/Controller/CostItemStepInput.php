<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\SaveCostItem;
use App\Module\Finance\Contract\DecidedMeasures;
use App\Module\Finance\Contract\Interval;
use App\Module\Finance\Domain\ChosenMeasure;
use App\Module\Finance\Domain\CostItem;
use App\Module\Finance\Domain\CostKindRepository;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\DueDate;
use App\Module\Finance\Domain\KeyBelongsToAnotherProperty;
use App\Module\Finance\Domain\QuantitiesAreRecorded;
use App\Module\Finance\Domain\UnknownProperty;
use App\Shared\Http\FormInput;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Was ein Schritt der Kostenposition aus dem Formular macht.
 *
 * Je Schritt eine Methode. Gemeinsam ist ihnen nur die Form der Antwort: eine
 * Liste von Fehlern je Feld, leer heisst gespeichert.
 */
final readonly class CostItemStepInput
{
    public function __construct(
        private SaveCostItem $save,
        private CostKindRepository $kinds,
        private DistributionKeyRepository $keys,
        private DecidedMeasures $measures,
    ) {
    }

    /** @return array<string, string> */
    public function apply(string $step, Request $request, CostItem $item): array
    {
        $steps = [
            CostItemFlow::DUE => $this->due(...),
            // Die Jahreswerte haben eigene Adressen — hier gibt es nichts
            // abzuschicken ausser „weiter".
            CostItemFlow::AMOUNTS => static fn (): array => [],
            CostItemFlow::REST => $this->rest(...),
            CostItemFlow::ASSIGNMENT => $this->assignment(...),
        ];

        return ($steps[$step] ?? $this->assignment(...))($request, $item);
    }

    /**
     * Der erste Schritt ohne Position: er legt eine an.
     *
     * @return array{errors: array<string, string>, item: CostItem|null}
     */
    public function create(Request $request): array
    {
        $chosen = $this->chosen($request);

        if (null === $chosen) {
            return ['errors' => ['assignment' => 'finance.error.assignment_incomplete'], 'item' => null];
        }

        try {
            $item = $this->save->forProperty($chosen->propertyId, $chosen->kind, $chosen->key);
        } catch (UnknownProperty) {
            return ['errors' => ['assignment' => 'finance.error.property_unknown'], 'item' => null];
        } catch (KeyBelongsToAnotherProperty) {
            return ['errors' => ['assignment' => 'finance.error.key_foreign'], 'item' => null];
        }

        return ['errors' => [], 'item' => $item];
    }

    /** @return array<string, string> */
    private function assignment(Request $request, CostItem $item): array
    {
        $chosen = $this->chosen($request);

        if (null === $chosen) {
            return ['assignment' => 'finance.error.assignment_incomplete'];
        }

        try {
            $this->save->belongsTo($item, $chosen->propertyId, $chosen->kind, $chosen->key);
        } catch (UnknownProperty) {
            return ['assignment' => 'finance.error.property_unknown'];
        } catch (KeyBelongsToAnotherProperty) {
            return ['assignment' => 'finance.error.key_foreign'];
        } catch (QuantitiesAreRecorded) {
            return ['assignment' => 'finance.error.quantities_recorded'];
        }

        return [];
    }

    /** @return array<string, string> */
    private function due(Request $request, CostItem $item): array
    {
        try {
            $this->save->dueOn($item, DueDate::of(
                FormInput::intOrNull($request, 'dueDay') ?? 0,
                FormInput::intOrNull($request, 'dueMonth'),
                Interval::tryFrom($request->request->getString('interval')) ?? Interval::Monthly,
            ));
        } catch (InvalidArgumentException) {
            return ['due' => 'finance.error.due_invalid'];
        }

        return [];
    }

    /** @return array<string, string> */
    private function rest(Request $request, CostItem $item): array
    {
        $this->save->noteThat(
            $item,
            self::apportionableOrNull($request),
            'on_the_day' !== $request->request->getString('split'),
            $request->request->getString('note'),
            $this->measureOf($item, $request->request->getString('measure')),
        );

        return [];
    }

    /**
     * Die gewaehlte Massnahme — mit ihrem Namen aus der Liste.
     *
     * Der Name wird nicht mitgeschickt, sondern nachgeschlagen: was im
     * Formular steht, ist eine Nummer, und was daneben steht, soll dieselbe
     * Massnahme benennen und nicht das, was der Absender behauptet.
     */
    private function measureOf(CostItem $item, string $reference): ChosenMeasure
    {
        foreach ($this->measures->forProperty($item->propertyId()) as $measure) {
            if ($measure->reference === $reference) {
                return ChosenMeasure::of($measure->reference, $measure->label);
            }
        }

        return ChosenMeasure::none();
    }

    /**
     * Leer heisst: wie die Kostenart. Gesetzt heisst: hier gilt etwas anderes.
     */
    private static function apportionableOrNull(Request $request): ?bool
    {
        return match ($request->request->getString('apportionable')) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    /** Objekt, Kostenart und Verteilerschluessel — oder keines davon. */
    private function chosen(Request $request): ?ChosenAssignment
    {
        $propertyId = $request->request->getString('propertyId');
        $kind = $this->kinds->byId($request->request->getString('kindId'));
        $key = $this->keys->byId($request->request->getString('keyId'));

        return '' === $propertyId || null === $kind || null === $key
            ? null
            : new ChosenAssignment($propertyId, $kind, $key);
    }
}
