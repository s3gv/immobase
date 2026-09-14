<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Finance\UserInterface\Controller;

use App\Module\Finance\Application\MaintainDistributionKeys;
use App\Module\Finance\Domain\DistributionKey;
use App\Module\Finance\Domain\DistributionKeyIsASystemKey;
use App\Module\Finance\Domain\DistributionKeyIsInUse;
use App\Module\Finance\Domain\DistributionKeyKind;
use App\Module\Finance\Domain\DistributionKeyRepository;
use App\Module\Finance\Domain\FinancePermissions;
use App\Module\Finance\Domain\RecordedInTheMeantime;
use App\Module\Finance\Domain\UnitBelongsElsewhere;
use App\Module\Finance\Domain\UsedByAPlan;
use App\Module\Property\Contract\PropertyBrief;
use App\Module\Property\Contract\PropertyDirectory;
use App\Module\Property\Contract\UnitDirectory;
use App\Shared\Http\FormInput;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Die Verteilerschluessel: was ueberall gilt, und was zu einem Haus gehoert.
 *
 * Eigene Schluessel tragen immer feste Anteile. Die berechneten stehen schon
 * im System, und „Nach Verbrauch" ist ein einziger Systemschluessel: er
 * traegt selbst keine Werte, die Mengen stehen an der Kostenposition. Einen
 * davon je Objekt anzulegen hiesse, dieselbe leere Huelse noch einmal zu
 * bauen — deshalb gibt es beim Anlegen nichts zu waehlen.
 */
#[IsGranted(FinancePermissions::VIEW)]
final class DistributionKeyController extends AbstractController
{
    /** Drei Abschnitte: was ueberall gilt, was zu einem Haus gehoert, und Neues. */
    public const array SECTIONS = ['system', 'eigene', 'neu'];

    public function __construct(
        private readonly DistributionKeyRepository $keys,
        private readonly MaintainDistributionKeys $maintain,
        private readonly PropertyDirectory $properties,
        private readonly UnitDirectory $units,
        private readonly FinancePage $page,
        private readonly FinanceSections $frame,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/finanzen/verteilerschluessel', name: 'app_finance_key', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $keys = $this->keys->all();
        $frame = $this->frame->frame(
            'app_finance_key',
            [],
            'finance.key',
            self::SECTIONS,
            $request->query->getString('abschnitt'),
        );

        return $this->render('finance/keys/'.$frame['current'].'.html.twig', [
            ...$frame,
            'system' => array_values(array_filter($keys, static fn (DistributionKey $key): bool => $key->isSystem())),
            'own' => array_values(array_filter($keys, static fn (DistributionKey $key): bool => !$key->isSystem())),
            'properties' => $this->properties->all(),
            'names' => $this->propertyNames(),
            'heading' => $this->translator->trans('finance.key.heading'),
            'subheading' => $this->translator->trans('finance.key.explanation'),
            'trail' => $this->page->trail('finance.key.heading'),
        ]);
    }

    #[IsGranted(FinancePermissions::EDIT)]
    #[Route('/finanzen/verteilerschluessel/neu', name: 'app_finance_key_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        $this->guard($request);
        $property = $this->propertyByNumber(FormInput::intOrNull($request, 'property') ?? 0);

        if (null === $property) {
            $this->addFlash('error', 'finance.error.key_needs_property');

            return $this->redirectToRoute('app_finance_key');
        }

        try {
            $key = $this->maintain->add(
                $property->id,
                $request->request->getString('name'),
                DistributionKeyKind::Fixed,
            );
            $this->addFlash('success', 'finance.key.added');

            return $this->redirectToRoute('app_finance_key_show', ['id' => $key->id()]);
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'finance.error.key_name_required');

            return $this->redirectToRoute('app_finance_key');
        }
    }

    /** Ein eigener Schluessel mit seinen Anteilen. */
    #[Route('/finanzen/verteilerschluessel/{id}', name: 'app_finance_key_show', methods: ['GET'])]
    public function show(string $id): Response
    {
        $key = $this->required($id);
        $property = null === $key->propertyId() ? null : ($this->properties->byIds([$key->propertyId()])[$key->propertyId()] ?? null);

        return $this->render('finance/key.html.twig', [
            'key' => $key,
            'property' => $property,
            'units' => null === $property ? [] : $this->units->ofProperty($property->number),
            'shares' => self::sharesOf($key),
            'trail' => $this->page->trail('finance.key.heading'),
        ]);
    }

    #[IsGranted(FinancePermissions::EDIT)]
    #[Route('/finanzen/verteilerschluessel/{id}/anteile', name: 'app_finance_key_shares', methods: ['POST'])]
    public function shares(string $id, Request $request): Response
    {
        $key = $this->required($id);
        $this->guard($request);

        try {
            /** @var array<string, string> $shares */
            $shares = array_filter($request->request->all('shares'), \is_string(...));
            $this->maintain->hold($key, $shares);
            $this->addFlash('success', 'finance.key.shares_saved');
        } catch (DistributionKeyIsASystemKey) {
            $this->addFlash('error', 'finance.error.key_is_system');
        } catch (UnitBelongsElsewhere) {
            $this->addFlash('error', 'finance.error.unit_elsewhere');
        } catch (RecordedInTheMeantime) {
            $this->addFlash('error', 'finance.error.saved_in_the_meantime');
        } catch (InvalidArgumentException) {
            $this->addFlash('error', 'finance.error.share_invalid');
        }

        return $this->redirectToRoute('app_finance_key_show', ['id' => $id]);
    }

    #[IsGranted(FinancePermissions::DELETE)]
    #[Route('/finanzen/verteilerschluessel/{id}/loeschen', name: 'app_finance_key_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $key = $this->required($id);
        $this->guard($request);

        try {
            $this->maintain->drop($key);
            $this->addFlash('success', 'finance.key.removed');
        } catch (DistributionKeyIsInUse) {
            $this->addFlash('error', 'finance.error.key_in_use');

            return $this->redirectToRoute('app_finance_key_show', ['id' => $id]);
        } catch (DistributionKeyIsASystemKey) {
            $this->addFlash('error', 'finance.error.key_is_system');
        } catch (UsedByAPlan $held) {
            $this->addFlash('error', $held->getMessage());

            return $this->redirectToRoute('app_finance_key_show', ['id' => $id]);
        }

        return $this->redirectToRoute('app_finance_key');
    }

    /**
     * Die Anteile als Zuordnung Einheit auf Wert — so liest sie das Formular.
     *
     * @return array<string, string>
     */
    private static function sharesOf(DistributionKey $key): array
    {
        $shares = [];

        foreach ($key->shares() as $share) {
            $shares[$share->unitId()] = $share->share();
        }

        return $shares;
    }

    private function propertyByNumber(int $number): ?PropertyBrief
    {
        foreach ($this->properties->all() as $property) {
            if ($property->number === $number) {
                return $property;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function propertyNames(): array
    {
        $names = [];

        foreach ($this->properties->all() as $property) {
            $names[$property->id] = $property->oneLine();
        }

        return $names;
    }

    private function required(string $id): DistributionKey
    {
        return $this->keys->byId($id)
            ?? throw new NotFoundHttpException('Diesen Verteilerschlüssel gibt es nicht.');
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('finance_key', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
