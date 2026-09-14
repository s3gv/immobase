<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\UserInterface;

use App\Shared\Flow\FlowDefinition;
use App\Shared\Flow\FlowState;
use App\Shared\Flow\FlowStep;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

/**
 * Zeichnet den Multi-Step-Baustein.
 *
 * Er wartet auf das erste Fachmodul und hat bis dahin keinen Aufrufer. Die
 * Vorlage greift auf FlowDefinition und FlowState zu; eine umbenannte Methode
 * dort faende sonst erst dieses Fachmodul, und dann sieht es aus wie ein
 * Fehler im neuen Feature statt wie eine alte Umbenennung.
 */
final class FlowTemplateTest extends KernelTestCase
{
    public function testDrawsTheStepsAndTheCurrentStep(): void
    {
        $html = self::render('costs');

        self::assertStringContainsString('ib-flow__steps', $html);
        self::assertStringContainsString('aria-current="step"', $html);
        self::assertStringContainsString('Schritt 2 von 3', $html, 'Die Position wird übersetzt eingesetzt.');
    }

    /**
     * Ohne formnovalidate blockiert die Browservalidierung des aktuellen
     * Schritts das Zurueckgehen, und der Nutzer sitzt fest.
     */
    public function testTheBackButtonSkipsBrowserValidation(): void
    {
        self::assertStringContainsString('formnovalidate', self::render('costs'));
    }

    public function testTheFirstStepHasNoWayBack(): void
    {
        self::assertStringNotContainsString('value="back"', self::render('subject'));
    }

    /**
     * Bereits erreichte Schritte sind anwaehlbar, spaetere nicht — sonst
     * liesse sich ueber die Schrittliste ein Schritt oeffnen, dessen
     * Voraussetzungen noch gar nicht erhoben sind.
     */
    public function testOnlyVisitedStepsCanBeChosen(): void
    {
        $html = self::render('costs');

        self::assertStringContainsString('value="subject"', $html);
        self::assertStringNotContainsString('value="review"', $html);
    }

    /**
     * Die Schrittliste schickt das Formular mit, statt zu verlinken.
     *
     * Ein Link verwuerfe, was im aktuellen Schritt gerade getippt wurde — und
     * der Ablauf sagt ausdruecklich zu, dass Eingaben erhalten bleiben.
     */
    public function testChoosingAStepSubmitsTheFormInsteadOfLeavingIt(): void
    {
        $html = self::render('costs');

        self::assertStringContainsString('name="goto"', $html);
        self::assertStringNotContainsString('?step=', $html, 'Kein Link in der Schrittliste');
    }

    public function testTheLastStepClosesTheFlow(): void
    {
        $html = self::render('review');

        self::assertStringContainsString('Abschließen', $html);
        self::assertStringNotContainsString('>Weiter<', $html);
    }

    private static function render(string $currentStep): string
    {
        self::bootKernel();

        $request = Request::create('https://example.org/ablauf');
        $request->setSession(new Session(new MockArraySessionStorage()));

        $requests = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $requests);
        $requests->push($request);

        $definition = new FlowDefinition('probe', [
            new FlowStep('subject', 'flow.steps', 'flow.steps'),
            new FlowStep('costs', 'flow.steps', 'flow.steps'),
            new FlowStep('review', 'flow.steps', 'flow.steps'),
        ]);

        // Vor der Schleife nachschlagen: ein vertippter Schrittname wirft
        // hier, statt die Schleife ewig drehen zu lassen. advance() steht am
        // letzten Schritt still, und eine Bedingung, die dann nie eintritt,
        // haengt die ganze Testsuite.
        $step = $definition->step($currentStep);
        $state = FlowState::start($definition);

        while ($state->currentStepKey() !== $step->key && !$definition->isLast($state->currentStepKey())) {
            $state->advance($definition);
        }

        self::assertSame($step->key, $state->currentStepKey(), 'Der Schritt wurde nicht erreicht.');

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('flow/flow.html.twig', [
            'definition' => $definition,
            'state' => $state,
            'step' => $step,
            'action' => '/ablauf',
            'heading' => 'flow.steps',
            'trail' => [],
        ]);
    }
}
