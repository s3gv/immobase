<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Application\MyData;
use App\Module\Portal\Application\MyEnquiries;
use App\Module\Portal\Application\ProposeAChange;
use App\Shared\Change\ChangeableField;
use App\Shared\Change\RecordKind;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Eine Aenderung vorschlagen — an den eigenen Daten, dem eigenen Objekt, der
 * eigenen Einheit.
 *
 * **Lesen ist ein Recht, aendern ist ein Vorschlag.** Hier wird nichts
 * geschrieben, was die Stammdaten betrifft; es entsteht eine Anfrage mit
 * einem Vorschlag daran, und entschieden wird drueben.
 *
 * Welche Felder dastehen, sagt das besitzende Modul. Gehoert der Datensatz
 * dem Angemeldeten nicht, gibt es keine Felder — und damit die Seite nicht.
 */
final class PortalChangeController extends AbstractController
{
    /**
     * Art und Kennung, einmal beschrieben.
     *
     * Beide Routen fuehren auf dieselbe Adresse; zweimal dieselben
     * Bedingungen hinzuschreiben waere zweimal die Gelegenheit, eine davon zu
     * aendern.
     */
    private const array WHERE = ['type' => 'stammdaten|objekt|einheit', 'id' => '[0-9a-fA-F-]{36}'];

    public function __construct(
        private readonly ProposeAChange $proposals,
        private readonly MyEnquiries $mine,
        private readonly MyData $data,
        private readonly PortalPage $page,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/portal/aendern/{type}/{id}', name: 'app_portal_change', requirements: self::WHERE, methods: ['GET'])]
    public function form(string $type, string $id): Response
    {
        $kind = self::kindOf($type);

        return $this->render('portal/aendern.html.twig', [
            ...$this->frame($kind),
            'fields' => $this->fieldsOf($kind, $id),
            'kind' => $kind,
            'recordId' => $id,
            'subject' => $this->translator->trans('portal.change.subject.'.$kind->value),
            'comment' => '',
        ]);
    }

    #[Route('/portal/aendern/{type}/{id}', name: 'app_portal_propose', requirements: self::WHERE, methods: ['POST'])]
    public function propose(string $type, string $id, Request $request): Response
    {
        $kind = self::kindOf($type);
        $fields = $this->fieldsOf($kind, $id);
        $this->guard($request);

        $enquiry = $this->proposals->propose(
            $kind,
            $id,
            $this->mine->partyId(),
            $this->whoAmI(),
            $this->orDefault($request, 'subject', 'portal.change.subject.'.$kind->value),
            $this->orDefault($request, 'comment', 'portal.change.without_comment'),
            self::submitted($request, $fields),
        );

        if (null === $enquiry) {
            $this->addFlash('info', 'portal.change.nothing_changed');

            return $this->redirectToRoute('app_portal_change', ['type' => $type, 'id' => $id]);
        }

        $this->addFlash('success', 'portal.change.proposed');

        return $this->redirectToRoute('app_portal_enquiry', ['id' => $enquiry->id()]);
    }

    /**
     * Eine Eingabe — oder der vorbelegte Satz, wenn sie leer blieb.
     *
     * Betreff und Kommentar sind beide freiwillig: „Änderung meiner
     * Stammdaten" ist ein brauchbarer Betreff, und wer nichts dazu zu sagen
     * hat, soll nichts dazu schreiben muessen.
     */
    private function orDefault(Request $request, string $field, string $key): string
    {
        $given = trim($request->request->getString($field));

        return '' === $given ? $this->translator->trans($key) : $given;
    }

    /**
     * Nur die Felder, die das besitzende Modul nennt.
     *
     * Das Formular ist nicht die Wahrheit darueber, was sich aendern laesst —
     * es ist nur seine Abbildung. Alles andere aus der Eingabe wird hier
     * verworfen, und zwar bevor es irgendwohin weitergereicht wird.
     *
     * @param list<ChangeableField> $fields
     *
     * @return array<string, string>
     */
    private static function submitted(Request $request, array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $values[$field->key] = $request->request->getString('field_'.$field->key, $field->value);
        }

        return $values;
    }

    /**
     * @return list<ChangeableField>
     */
    private function fieldsOf(RecordKind $kind, string $id): array
    {
        $fields = $this->proposals->fieldsOf($kind, $id);

        // Nicht meins und „daran gibt es nichts" sind hier dieselbe Antwort:
        // wer Kennungen durchprobiert, soll aus ihr nicht schliessen koennen,
        // welche es gibt.
        if ([] === $fields) {
            throw new NotFoundHttpException('Daran gibt es nichts vorzuschlagen.');
        }

        return $fields;
    }

    private static function kindOf(string $type): RecordKind
    {
        return RecordKind::tryFrom($type) ?? throw new NotFoundHttpException('Diese Art gibt es nicht.');
    }

    /**
     * @return array<string, mixed>
     */
    private function frame(RecordKind $kind): array
    {
        $party = $this->data->party();

        return $this->page->frame(
            self::stepOf($kind),
            null === $party ? '' : $party->displayName,
            $this->translator->trans('portal.change.heading'),
        );
    }

    /** Der Bereich, aus dem der Vorschlag kommt — dorthin fuehrt auch zurueck. */
    private static function stepOf(RecordKind $kind): string
    {
        return match ($kind) {
            RecordKind::Party => PortalFlow::DATA,
            RecordKind::Property => PortalFlow::PROPERTIES,
            RecordKind::Unit => PortalFlow::UNITS,
        };
    }

    private function whoAmI(): string
    {
        $party = $this->data->party();

        return null === $party ? '' : $party->displayName;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('portal_change', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
