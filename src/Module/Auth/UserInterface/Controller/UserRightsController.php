<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\ChangeOutcome;
use App\Module\Auth\Application\ManageUser;
use App\Module\Auth\Application\Rbac\AssignPermissions;
use App\Module\Auth\Application\Rbac\AssignRoles;
use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rollen und Zusatzrechte an einem fremden Konto.
 *
 * Beides steht auf derselben Seite wie die uebrigen Angaben: wer einen
 * Benutzer oeffnet, findet dort alles, was er ueber ihn entscheidet.
 *
 * Dieselben Sperren wie ueberall in der Benutzerverwaltung — niemand trifft
 * sein eigenes Konto, und das letzte, das noch Benutzer verwalten kann,
 * bleibt stehen. Ohne die zweite koennte sich eine Installation ueber die
 * Rollen genauso aussperren wie ueber den Abschaltknopf. Sie stehen deshalb
 * nicht hier, sondern in ManageUser::guarded(): dort gelten sie unter
 * derselben Datenbanksperre wie die Aenderung.
 */
#[IsGranted(AuthPermissions::USERS_EDIT)]
final class UserRightsController extends AbstractController
{
    public function __construct(
        private readonly RequireUser $user,
        private readonly ManageUser $manage,
        private readonly AssignRoles $assign,
        private readonly AssignPermissions $permissions,
        private readonly EffectivePermissions $effective,
    ) {
    }

    #[Route('/benutzer/{number}/rollen', name: 'app_user_roles', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function roles(int $number, Request $request): Response
    {
        $user = ($this->user)($number);
        $this->guard($request, 'user_roles_'.$number);

        return $this->apply($user, $request);
    }

    #[Route('/benutzer/{number}/rechte', name: 'app_user_permissions', requirements: ['number' => '\d+'], methods: ['POST'])]
    public function extras(int $number, Request $request): Response
    {
        $user = ($this->user)($number);
        $this->guard($request, 'user_permissions_'.$number);

        return $this->applyExtras($user, $request);
    }

    private function apply(User $user, Request $request): Response
    {
        $chosen = self::strings($request, 'roles');
        $refusal = $this->assign->reasonAgainst($chosen);

        if (null !== $refusal) {
            return $this->back($user, UserRightsPage::ROLES, ChangeOutcome::refused($refusal));
        }

        $outcome = $this->manage->guarded($user, $this->actorId(), function () use ($user, $chosen): string {
            $this->assign->to($user, $chosen);

            return 'role.assignment.saved';
        });

        return $this->back($user, UserRightsPage::ROLES, $outcome);
    }

    private function applyExtras(User $user, Request $request): Response
    {
        $keys = self::strings($request, 'permissions');

        $outcome = $this->manage->guarded($user, $this->actorId(), function () use ($user, $keys): string {
            // Was aus einer Rolle kommt, wird erst hier bestimmt: unter der
            // Sperre, und damit nach jeder Rollenaenderung, die parallel lief.
            $this->permissions->toUser($user, $keys, $this->effective->fromRoles($user));

            return 'role.extra.saved';
        });

        return $this->back($user, UserRightsPage::PERMISSIONS, $outcome);
    }

    /**
     * @return list<string>
     */
    private static function strings(Request $request, string $field): array
    {
        return array_values(array_filter($request->request->all($field), \is_string(...)));
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    private function actorId(): string
    {
        $actor = $this->getUser();

        return $actor instanceof User ? $actor->id() : '';
    }

    /**
     * Zurueck in den Abschnitt, in dem gearbeitet wurde.
     *
     * Eine Meldung auf einer anderen Seite als der, auf der man gerade war,
     * liest niemand.
     */
    private function back(User $user, string $section, ChangeOutcome $outcome): Response
    {
        $this->addFlash($outcome->flash(), $outcome->message);

        return $this->redirectToRoute('app_user_show', [
            'number' => $user->number(),
            'abschnitt' => $section,
        ]);
    }
}
