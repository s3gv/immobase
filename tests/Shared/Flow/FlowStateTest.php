<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Flow;

use App\Shared\Flow\FlowDefinition;
use App\Shared\Flow\FlowState;
use App\Shared\Flow\FlowStep;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowStateTest extends TestCase
{
    public function testStartsAtTheFirstStep(): void
    {
        self::assertSame('subject', FlowState::start($this->definition())->currentStepKey());
    }

    public function testRemembersValuesOfAStep(): void
    {
        $state = FlowState::start($this->definition());
        $state->remember('subject', ['title' => 'Betriebskosten 2026']);

        self::assertSame(['title' => 'Betriebskosten 2026'], $state->valuesFor('subject'));
    }

    public function testReturnsAnEmptyArrayForAnUntouchedStep(): void
    {
        self::assertSame([], FlowState::start($this->definition())->valuesFor('costs'));
    }

    public function testAdvancesToTheNextStep(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);
        $state->advance($definition);

        self::assertSame('costs', $state->currentStepKey());
    }

    public function testGoingBackKeepsTheValues(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);
        $state->remember('subject', ['title' => 'Betriebskosten 2026']);
        $state->advance($definition);
        $state->remember('costs', ['total' => 125000]);
        $state->goBack($definition);

        self::assertSame('subject', $state->currentStepKey());
        self::assertSame(['title' => 'Betriebskosten 2026'], $state->valuesFor('subject'));
        self::assertSame(['total' => 125000], $state->valuesFor('costs'), 'Auch der spätere Schritt bleibt erhalten');
    }

    public function testGoingBackFromTheFirstStepChangesNothing(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);
        $state->goBack($definition);

        self::assertSame('subject', $state->currentStepKey());
    }

    public function testAdvancingBeyondTheLastStepChangesNothing(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);
        $state->advance($definition);
        $state->advance($definition);
        $state->advance($definition);

        self::assertSame('review', $state->currentStepKey());
    }

    public function testJumpsToAnAlreadyVisitedStep(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);
        $state->advance($definition);
        $state->jumpTo($definition, 'subject');

        self::assertSame('subject', $state->currentStepKey());
    }

    public function testRefusesToJumpToAnUnvisitedStep(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);

        $state->jumpTo($definition, 'review');

        self::assertSame('subject', $state->currentStepKey(), 'Ein noch nicht erreichter Schritt wird nicht angesprungen');
    }

    /**
     * Der Schrittname kommt aus der Adresszeile. Eine Ausnahme daraus zu
     * machen hiesse, dass jeder Aufrufer sie abfangen muss — und wer es
     * vergisst, liefert einen Serverfehler statt einer Seite. Genau das ist
     * schon einmal passiert.
     *
     * @param string $stepKey
     */
    #[DataProvider('unusableStepNames')]
    public function testIgnoresAStepNameItCannotUse(string $stepKey): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);

        $state->jumpTo($definition, $stepKey);

        self::assertSame('subject', $state->currentStepKey());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusableStepNames(): iterable
    {
        yield 'unbekannt' => ['nope'];
        yield 'noch nicht erreicht' => ['review'];
        yield 'leer' => [''];
        yield 'Pfadangabe' => ['../../etc/passwd'];
        yield 'sehr lang' => [str_repeat('a', 2000)];
    }

    public function testCollectsAllValues(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);
        $state->remember('subject', ['title' => 'X']);
        $state->advance($definition);
        $state->remember('costs', ['total' => 5]);

        self::assertSame(['subject' => ['title' => 'X'], 'costs' => ['total' => 5]], $state->allValues());
    }

    public function testSurvivesSerialisation(): void
    {
        $definition = $this->definition();
        $state = FlowState::start($definition);
        $state->remember('subject', ['title' => 'X']);
        $state->advance($definition);

        $restored = FlowState::fromArray($state->toArray());

        self::assertSame('costs', $restored->currentStepKey());
        self::assertSame(['title' => 'X'], $restored->valuesFor('subject'));
    }

    /**
     * Beim Bearbeiten sind alle Schritte erreichbar — die Schrittliste wird
     * damit zur Abschnittsnavigation.
     */
    public function testAResumedFlowMayJumpAnywhere(): void
    {
        $definition = $this->definition();
        $state = FlowState::resume($definition, ['subject' => ['value' => 'X']]);

        self::assertSame('subject', $state->currentStepKey(), 'Beginnt trotzdem vorn');
        self::assertSame(['value' => 'X'], $state->valuesFor('subject'));

        $state->jumpTo($definition, 'review');

        self::assertSame('review', $state->currentStepKey());
    }

    public function testAResumedFlowStillRefusesAnUnknownStep(): void
    {
        $definition = $this->definition();
        $state = FlowState::resume($definition, []);
        $state->jumpTo($definition, 'gibtesnicht');

        self::assertSame('subject', $state->currentStepKey());
    }

    private function definition(): FlowDefinition
    {
        return new FlowDefinition('demo', [
            new FlowStep('subject', 'l', 'e'),
            new FlowStep('costs', 'l', 'e'),
            new FlowStep('review', 'l', 'e'),
        ]);
    }
}
