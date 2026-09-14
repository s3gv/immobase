<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Shared\Flow;

use App\Shared\Flow\FlowDefinition;
use App\Shared\Flow\FlowStep;
use App\Shared\Flow\UnknownFlowStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FlowDefinitionTest extends TestCase
{
    public function testKnowsItsFirstStep(): void
    {
        self::assertSame('subject', $this->threeSteps()->firstStep()->key);
    }

    public function testFindsAStepByKey(): void
    {
        $step = $this->threeSteps()->step('costs');

        self::assertSame('costs', $step->key);
        self::assertSame('flow.demo.costs.label', $step->labelKey);
        self::assertSame('flow.demo.costs.explanation', $step->explanationKey);
    }

    public function testThrowsForAnUnknownStep(): void
    {
        $this->expectException(UnknownFlowStep::class);
        $this->expectExceptionMessageIsOrContains('nope');

        $this->threeSteps()->step('nope');
    }

    public function testKnowsTheNextStep(): void
    {
        $next = $this->threeSteps()->next('subject');

        self::assertNotNull($next);
        self::assertSame('costs', $next->key);
    }

    public function testHasNoStepAfterTheLast(): void
    {
        self::assertNull($this->threeSteps()->next('review'));
    }

    public function testKnowsThePreviousStep(): void
    {
        $previous = $this->threeSteps()->previous('review');

        self::assertNotNull($previous);
        self::assertSame('costs', $previous->key);
    }

    public function testHasNoStepBeforeTheFirst(): void
    {
        self::assertNull($this->threeSteps()->previous('subject'));
    }

    public function testReportsThePositionOfAStep(): void
    {
        self::assertSame(1, $this->threeSteps()->positionOf('subject'));
        self::assertSame(3, $this->threeSteps()->positionOf('review'));
    }

    public function testReportsTheNumberOfSteps(): void
    {
        self::assertSame(3, $this->threeSteps()->stepCount());
    }

    public function testRecognisesTheLastStep(): void
    {
        self::assertFalse($this->threeSteps()->isLast('costs'));
        self::assertTrue($this->threeSteps()->isLast('review'));
    }

    public function testRejectsAFlowWithoutSteps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FlowDefinition('empty', []);
    }

    public function testRejectsDuplicateStepKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('doppelt');

        new FlowDefinition('dupes', [
            new FlowStep('a', 'l', 'e'),
            new FlowStep('a', 'l', 'e'),
        ]);
    }

    private function threeSteps(): FlowDefinition
    {
        return new FlowDefinition('demo', [
            new FlowStep('subject', 'flow.demo.subject.label', 'flow.demo.subject.explanation'),
            new FlowStep('costs', 'flow.demo.costs.label', 'flow.demo.costs.explanation'),
            new FlowStep('review', 'flow.demo.review.label', 'flow.demo.review.explanation'),
        ]);
    }
}
