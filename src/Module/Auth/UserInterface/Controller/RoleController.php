<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\Rbac\AssignPermissions;
use App\Module\Auth\Application\Rbac\EffectivePermissions;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Rollen dieser Installation und die Rechtematrix.
 *
 * Eine Seite fuer beides, in zwei Abschnitten. Eine zusaetzliche Detailseite
 * je Rolle mit denselben Kaestchen waere eine zweite Wahrheit — und die
 * erste, die jemand vergisst mitzupflegen.
 */
#[IsGranted(AuthPermissions::ROLES_VIEW)]
final class RoleController extends AbstractController
{
    public function __construct(
        private readonly RolePage $page,
        private readonly AssignPermissions $permissions,
        private readonly EffectivePermissions $effective,
    ) {
    }

    #[Route('/rollen', name: 'app_role', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $section = RolePage::known($request->query->getString('abschnitt'));

        return $this->render('roles/'.$section.'.html.twig', $this->page->data($section));
    }

    /**
     * Die ganze Matrix mit einem Klick.
     *
     * Ein Speichern fuer alles: wer Haekchen ueber mehrere Rollen hinweg
     * verschiebt, will einen Stand ablegen und nicht sieben. Fehlende
     * Kaestchen sind Absicht — ein nicht gesendetes Feld heisst „nicht
     * gesetzt", und genau so wird es gelesen.
     *
     * Abgelehnt wird der Stand nur in einem Fall: wenn danach niemand mehr
     * Benutzer verwalten koennte. Auch ueber die Matrix darf sich eine
     * Installation nicht selbst aussperren.
     */
    #[IsGranted(AuthPermissions::ROLES_EDIT)]
    #[Route('/rollen/rechte', name: 'app_role_permissions', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('role_permissions', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        $me = $this->getUser();

        if (!$me instanceof User) {
            throw $this->createAccessDeniedException('Kein angemeldetes Konto.');
        }

        $saved = $this->permissions->saveMatrix(self::submitted($request), $this->effective->of($me));

        $this->addFlash($saved ? 'success' : 'error', $saved ? 'role.saved' : 'role.no_manager_left');

        // Zurueck in den Abschnitt, in dem gearbeitet wurde: eine Meldung auf
        // einer anderen Seite als der, auf der man gerade war, liest niemand.
        return $this->redirectToRoute('app_role', ['abschnitt' => RolePage::MATRIX]);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function submitted(Request $request): array
    {
        $byRole = [];

        foreach ($request->request->all('permissions') as $roleId => $keys) {
            if (\is_string($roleId) && \is_array($keys)) {
                $byRole[$roleId] = array_values(array_filter($keys, \is_string(...)));
            }
        }

        return $byRole;
    }
}
