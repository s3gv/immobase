<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Party\UserInterface\Controller;

use App\Module\Party\Application\PartyDraft;
use App\Module\Party\Application\PartyValues;
use App\Module\Party\Application\SaveParty;
use App\Module\Party\Domain\Party;
use App\Module\Party\Domain\PartyPermissions;
use App\Shared\Flow\FlowDefinition;
use App\Shared\Flow\FlowSessionStore;
use App\Shared\Flow\FlowState;
use App\Shared\Flow\ListInstruction;
use App\Shared\Flow\ReturnPath;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Einen Stammdatensatz anlegen — Schritt fuer Schritt.
 *
 * Der erste echte Ablauf auf dem Multi-Step-Baustein. Jeder Schritt fragt
 * eine Sache, erklaert sie in Alltagssprache und laesst sich verlassen, ohne
 * das Eingegebene zu verlieren.
 */
#[IsGranted(PartyPermissions::EDIT)]
final class PartyFlowController extends AbstractController
{
    public function __construct(
        private readonly FlowSessionStore $store,
        private readonly RequireParty $party,
        private readonly PartyStepInput $input,
        private readonly SaveParty $save,
        private readonly PartyFlowPage $page,
    ) {
    }

    #[Route('/stammdaten/neu', name: 'app_party_new', methods: ['GET', 'POST'])]
    public function create(Request $request): Response
    {
        return $this->run($request, PartyFlow::definition('new'), null);
    }

    /**
     * Bearbeiten laeuft durch denselben Ablauf — siehe FlowState::resume().
     */
    #[Route('/stammdaten/{reference}/bearbeiten', name: 'app_party_edit', requirements: ['reference' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(int $reference, Request $request): Response
    {
        return $this->run($request, PartyFlow::definition((string) $reference), ($this->party)($reference));
    }

    private function run(Request $request, FlowDefinition $definition, ?Party $party): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handle($request, $definition, $this->stateFor($definition, $party), $party);
        }

        $state = $this->enter($request, $definition, $party);
        $this->store->save($definition, $state);

        return $this->show($definition, $state, $party);
    }

    /**
     * Der Zustand beim Aufruf ueber die Adresse.
     *
     * Ohne Schritt darin kommt jemand von aussen. Dann faengt der Ablauf von
     * vorn an: wer "Hinzufuegen" drueckt, will einen neuen Datensatz und
     * keine halben Angaben von vorgestern. Beim Bearbeiten ist der Anfang der
     * gespeicherte Stand.
     */
    private function enter(Request $request, FlowDefinition $definition, ?Party $party): FlowState
    {
        $requestedStep = $request->query->getString('step');

        if ('' !== $requestedStep) {
            $state = $this->stateFor($definition, $party);
            $state->jumpTo($definition, $requestedStep);

            return $state;
        }

        $this->store->clear($definition);
        $state = $this->freshState($definition, $party);
        ReturnPath::remember($request, $state);

        return $state;
    }

    /**
     * Der Zwischenstand — oder der Anfang, wenn keiner da ist. Sitzungen
     * laufen ab, und Adressen mit Schritt darin landen in Lesezeichen.
     */
    private function stateFor(FlowDefinition $definition, ?Party $party): FlowState
    {
        return $this->store->find($definition) ?? $this->freshState($definition, $party);
    }

    /** Ein Ablauf am Anfang: leer beim Anlegen, gefuellt beim Bearbeiten. */
    private function freshState(FlowDefinition $definition, ?Party $party): FlowState
    {
        return null === $party
            ? FlowState::start($definition)
            : FlowState::resume($definition, PartyValues::of($party));
    }

    private function handle(Request $request, FlowDefinition $definition, FlowState $state, ?Party $party): Response
    {
        if (!$this->isCsrfTokenValid('flow_'.$definition->id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $step = $state->currentStepKey();

        if ($this->navigate($request, $definition, $state, $step)) {
            return $this->redirectTo($party, $state);
        }

        $errors = $this->input->errors($step, $state->allValues());

        if ([] !== $errors) {
            $this->store->save($definition, $state);

            return $this->show($definition, $state, $party, $errors);
        }

        return $this->goOn($definition, $state, $step, $party);
    }

    /**
     * Nimmt entgegen, was nur die Stelle im Ablauf oder eine Liste aendert.
     * Geprueft wird dabei nichts: wer einen Schritt halb ausgefuellt
     * verlaesst, soll das duerfen. Geprueft wird beim Weitergehen und am Ende.
     */
    private function navigate(Request $request, FlowDefinition $definition, FlowState $state, string $step): bool
    {
        $collected = $this->input->collect($step, $request);
        $instruction = ListInstruction::from($request);

        $state->remember($step, null === $instruction
            ? $collected
            : $this->input->applyTo($collected, $instruction));

        $goto = $request->request->getString('goto');

        if ('' !== $goto) {
            $state->jumpTo($definition, $goto);
        } elseif ('back' === $request->request->getString('direction')) {
            $state->goBack($definition);
        } elseif (null === $instruction) {
            return false;
        }

        $this->store->save($definition, $state);

        return true;
    }

    private function goOn(FlowDefinition $definition, FlowState $state, string $step, ?Party $party): Response
    {
        if (!$definition->isLast($step)) {
            $state->advance($definition);
            $this->store->save($definition, $state);

            return $this->redirectTo($party, $state);
        }

        $problem = $this->input->firstProblem($state->allValues());

        if (null !== $problem) {
            $state->jumpTo($definition, $problem['step']);
            $this->store->save($definition, $state);

            return $this->show($definition, $state, $party, $problem['errors']);
        }

        return $this->persist($definition, $state, $party);
    }

    private function persist(FlowDefinition $definition, FlowState $state, ?Party $party): Response
    {
        $saved = ($this->save)(new PartyDraft($state->allValues()), $party);
        $this->store->clear($definition);
        $this->addFlash('success', 'party.saved');

        // Wer von woanders hergeschickt wurde, kommt dorthin zurueck.
        $back = ReturnPath::with($state, $saved->id());

        return null === $back
            ? $this->redirectToRoute('app_party_show', ['reference' => $saved->reference()])
            : $this->redirect($back);
    }

    /**
     * Zurueck in den Ablauf, mit dem Schritt in der Adresse. Ohne ihn liesse
     * sich nicht unterscheiden, ob jemand weitergeht oder gerade von aussen
     * betritt — und beim Betreten faengt er von vorn an.
     */
    private function redirectTo(?Party $party, FlowState $state): Response
    {
        $parameters = ['step' => $state->currentStepKey()];

        if (null !== $party) {
            $parameters['reference'] = $party->reference();

            return $this->redirectToRoute('app_party_edit', $parameters);
        }

        return $this->redirectToRoute('app_party_new', $parameters);
    }

    /**
     * @param array<string, string> $errors
     */
    private function show(FlowDefinition $definition, FlowState $state, ?Party $party, array $errors = []): Response
    {
        $step = $definition->step($state->currentStepKey());

        return $this->render(
            'party/steps/'.$step->key.'.html.twig',
            $this->page->parameters($definition, $state, $party, $errors),
        );
    }
}
