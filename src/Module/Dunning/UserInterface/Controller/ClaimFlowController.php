<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Dunning\UserInterface\Controller;

use App\Module\Dunning\Application\Addressed;
use App\Module\Dunning\Domain\Claim;
use App\Module\Dunning\Domain\Creditor;
use App\Module\Dunning\Domain\DunningPermissions;
use App\Module\Property\Contract\UnitDirectory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Der Ablauf: eine Forderung von Hand erfassen.
 *
 * Selbsttaetig gemahnt wird nur, was die Anwendung auch weiss — die
 * Vorauszahlungen. Der eigentliche Fall hier ist der Verwalter, der dem
 * Vermieter hilft: **Kaltmiete**. Schuldner ist der Mieter, Glaeubiger der
 * Eigentuemer, und auf dem Brief steht der Vermieter, nicht die Verwaltung.
 *
 * Derselbe Rahmen wie ueberall, und aus demselben Grund: der Regelfall sind
 * drei Monatsmieten, und die entstehen nacheinander. Jeder Schritt speichert
 * sofort — wer nach dem zweiten Posten aufhoert, hat zwei Forderungen und
 * keinen verlorenen Entwurf.
 */
#[IsGranted(DunningPermissions::EDIT)]
final class ClaimFlowController extends AbstractController
{
    public function __construct(
        private readonly RequireClaim $claim,
        private readonly ClaimStepInput $input,
        private readonly ClaimFlowPage $page,
        private readonly UnitDirectory $units,
        private readonly Addressed $addressed,
    ) {
    }

    /** Der Anfang — hier gibt es die Forderung noch nicht. */
    #[Route('/finanzen/mahnwesen/erfassen', name: 'app_dunning_record', methods: ['GET', 'POST'])]
    public function record(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->show(null, ClaimFlow::CLAIM, $request);
        }

        $this->guard($request);
        $read = $this->input->create($request);

        if (null === $read['claim']) {
            return $this->show(null, ClaimFlow::CLAIM, $request, $read['errors']);
        }

        $this->addFlash('success', 'dunning.created');

        return $this->toStep($read['claim'], ClaimFlow::MORE);
    }

    // Die Schritte als Aufzaehlung und nicht als `[a-z]+`: sonst schluckt
    // diese Route jedes Wort hinter der Kennung.
    #[Route(
        '/finanzen/mahnwesen/erfassen/{id}/{step}',
        name: 'app_dunning_record_edit',
        requirements: ['id' => '[0-9a-fA-F-]{36}', 'step' => 'forderung|weitere|pruefen'],
        methods: ['GET', 'POST'],
    )]
    public function edit(string $id, string $step, Request $request): Response
    {
        $claim = ($this->claim)($id);
        $current = ClaimFlow::known($step);

        // Der erste Schritt legt an, und angelegt ist sie. Wer die Adresse
        // trotzdem aufruft, landet dort, wo es weitergeht.
        if (ClaimFlow::CLAIM === $current) {
            return $this->toStep($claim, ClaimFlow::MORE);
        }

        if (!$request->isMethod('POST')) {
            return $this->show($claim, $current, $request);
        }

        $this->guard($request);
        $read = $this->input->apply($current, $request, $claim);

        return [] === $read['errors']
            ? $this->added($claim, $read['claim'], $current, $request)
            : $this->show($claim, $current, $request, $read['errors']);
    }

    /** Der Posten ist angelegt — sagen, welcher Art, und weiterschicken. */
    private function added(Claim $first, ?Claim $added, string $step, Request $request): Response
    {
        if (null !== $added) {
            $this->addFlash(...self::wordFor($first, $added));
        }

        return $this->onwards($first, $step, $request);
    }

    /**
     * Was zum nachgetragenen Posten zu sagen ist.
     *
     * Standen zu seinem Tag andere Beteiligte an der Einheit, ist er ein
     * eigener Vorgang und taucht in der Liste unter dem Ablauf nicht auf —
     * er bekommt sein eigenes Schreiben. Das muss dastehen, sonst sucht
     * jemand vergeblich nach dem, was er eben eingetragen hat.
     *
     * @return array{string, string} Art und Schluessel der Meldung
     */
    private static function wordFor(Claim $first, Claim $added): array
    {
        $same = $added->debtor()->partyId() === $first->debtor()->partyId()
            && $added->source()->creditorIdentity()->equals($first->source()->creditorIdentity());

        return $same ? ['success', 'dunning.created'] : ['warning', 'dunning.other_parties'];
    }

    /**
     * Wohin der Knopf fuehrt.
     *
     * „Noch einen Posten" bleibt stehen, weil der Regelfall drei sind und
     * das Formular danach wieder leer ist.
     */
    private function onwards(Claim $claim, string $step, Request $request): Response
    {
        $direction = $request->request->getString('direction');

        if ('back' === $direction) {
            return $this->toStep($claim, ClaimFlow::previous($step) ?? ClaimFlow::MORE);
        }

        $next = 'add' === $direction ? ClaimFlow::MORE : ClaimFlow::next($step);

        return null === $next
            ? $this->redirectToRoute('app_dunning_show', ['id' => $claim->id()])
            : $this->toStep($claim, $next);
    }

    private function toStep(Claim $claim, string $step): Response
    {
        return $this->redirectToRoute('app_dunning_record_edit', ['id' => $claim->id(), 'step' => $step]);
    }

    /**
     * @param array<string, string> $errors
     */
    private function show(?Claim $claim, string $step, Request $request, array $errors = []): Response
    {
        return $this->render('dunning/claim/'.$step.'.html.twig', [
            ...$this->page->frame($claim, $step),
            'claim' => $claim,
            'units' => $this->units->all(),
            'creditors' => Creditor::cases(),
            'gathered' => null === $claim ? [] : $this->input->gathered($claim),
            'debtor' => null === $claim ? null : $this->addressed->debtorOf($claim),
            'creditor' => null === $claim ? null : $this->addressed->creditorOf($claim),
            'errors' => $errors,
            'submitted' => [] === $errors ? [] : $request->request->all(),
        ]);
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('dunning', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
