<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\ChangeOutcome;
use App\Module\Auth\Application\ManageSecondFactor;
use App\Module\Auth\Application\ManageUser;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserFilter;
use App\Module\Auth\Domain\UserRepository;
use App\Module\Auth\Domain\UserStatus;
use App\Shared\Http\FormInput;
use App\Shared\Ui\Page;
use App\Shared\Ui\Past;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Benutzerverwaltung.
 *
 * Anschauen ist ein eigenes Recht: wer hier hereinschaut, sieht die Adressen
 * aller Kolleginnen und Kollegen. Aendern und Loeschen stehen noch einmal
 * getrennt daneben — eine Vertretung, die nur nachsehen soll, wer wann
 * zuletzt angemeldet war, braucht dafuer nicht das Recht, Konten
 * abzuschalten.
 */
#[IsGranted(AuthPermissions::USERS_VIEW)]
final class UserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RequireUser $user,
        private readonly ManageUser $manage,
        private readonly ManageSecondFactor $factors,
        private readonly UserPage $page,
        private readonly UserRightsPage $rights,
    ) {
    }

    #[Route('/benutzer', name: 'app_user', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = UserFilter::of(
            $request->query->getString('q'),
            $request->query->getString('status'),
            $request->query->getBoolean('admins'),
            Past::from($request)->shown,
        );

        $page = Page::of(FormInput::queryIntOrNull($request, 'page') ?? 1, $this->users->countMatching($filter));
        $users = $this->users->matching($filter, $page);

        return $this->render('user/index.html.twig', [
            'users' => $users,
            'touchable' => $this->touchable($users),
            'page' => $page,
            'filter' => $filter,
            'statuses' => UserStatus::cases(),
            'url' => $this->page->listUrl($request),
            'trail' => $this->page->trail(null),
        ]);
    }

    #[Route('/benutzer/{number}', name: 'app_user_show', requirements: ['number' => '\d+'], methods: ['GET'])]
    public function show(int $number, Request $request): Response
    {
        $user = ($this->user)($number);
        $reason = $this->manage->reasonAgainstTouching($user, $this->actorId());

        // Rollen und Zusatzrechte stehen nur da, wer sie auch aendern darf —
        // und nie am eigenen Konto.
        $mayManage = null === $reason && $this->isGranted(AuthPermissions::USERS_EDIT);
        $section = $this->rights->known($request->query->getString('abschnitt'), $mayManage);

        return $this->render('user/detail/'.$section.'.html.twig', [
            ...$this->rights->data($user, $section, $mayManage),
            'user' => $user,
            'reason' => $reason,
            'trail' => $this->page->trail($user),
        ]);
    }

    #[IsGranted(AuthPermissions::USERS_EDIT)]
    #[Route('/benutzer/{number}/aktivierung', name: 'app_user_activation', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function activation(int $number, Request $request): Response
    {
        $user = ($this->user)($number);

        if (!$this->isCsrfTokenValid('user_activation_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        return $this->report($this->manage->toggleActivation($user, $this->actorId()), $number);
    }

    /**
     * Der Weg fuer ein verlorenes Geraet.
     *
     * Wiederherstellungscodes decken den Regelfall ab; wer auch die verloren
     * hat, braucht jemanden, der den Faktor abschaltet. Ohne diesen Knopf
     * bliebe nur der Weg in die Datenbank.
     */
    #[IsGranted(AuthPermissions::USERS_EDIT)]
    #[Route('/benutzer/{number}/zwei-faktor', name: 'app_user_reset_factor', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function resetSecondFactor(int $number, Request $request): Response
    {
        $user = ($this->user)($number);

        if (!$this->isCsrfTokenValid('user_factor_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $outcome = $this->manage->guarded($user, $this->actorId(), function () use ($user): string {
            $this->factors->turnOff($user);

            return 'user.factor.reset_done';
        });

        return $this->report($outcome, $number);
    }

    #[IsGranted(AuthPermissions::USERS_DELETE)]
    #[Route('/benutzer/{number}/loeschen', name: 'app_user_delete', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function delete(int $number, Request $request): Response
    {
        $user = ($this->user)($number);

        if (!$this->isCsrfTokenValid('user_delete_'.$number, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $outcome = $this->manage->delete($user, $this->actorId());

        $this->addFlash($outcome->flash(), $outcome->message);

        // Ein geloeschtes Konto hat keine Seite mehr; ein abgelehntes schon.
        return $outcome->applied
            ? $this->redirectToRoute('app_user')
            : $this->redirectToRoute('app_user_show', ['number' => $number]);
    }

    /**
     * Meldung und Rueckweg — fuer die Aenderungen, die auf der Kontoseite
     * enden.
     *
     * Die Sperren stehen nicht hier, sondern in ManageUser: dort gelten sie
     * unter derselben Datenbanksperre wie die Aenderung selbst. Ein
     * abgeschalteter Knopf haelt niemanden auf, der das Formular nachbaut, und
     * eine Pruefung davor haelt keine zweite Anfrage auf, die im selben
     * Augenblick laeuft.
     */
    private function report(ChangeOutcome $outcome, int $number): Response
    {
        $this->addFlash($outcome->flash(), $outcome->message);

        return $this->redirectToRoute('app_user_show', ['number' => $number]);
    }

    /**
     * @param list<User> $users
     *
     * @return array<string, bool>
     */
    private function touchable(array $users): array
    {
        $actor = $this->actorId();
        $touchable = [];

        foreach ($users as $user) {
            $touchable[$user->id()] = $this->manage->mayTouch($user, $actor);
        }

        return $touchable;
    }

    private function actorId(): string
    {
        $actor = $this->getUser();

        return $actor instanceof User ? $actor->id() : '';
    }
}
