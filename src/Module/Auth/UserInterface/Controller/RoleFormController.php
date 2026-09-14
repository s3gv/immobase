<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\Rbac\ManageRoles;
use App\Module\Auth\Domain\Rbac\AuthPermissions;
use App\Module\Auth\Domain\Rbac\Role;
use App\Module\Auth\Domain\Rbac\RoleName;
use App\Module\Auth\Domain\Rbac\RoleRepository;
use App\Shared\Text\Trimmed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Rollen anlegen, umbenennen, loeschen.
 *
 * Getrennt von der Matrix, weil es eine andere Frage ist — und weil Loeschen
 * ein eigenes Recht hat: wer Haekchen setzen darf, muss nicht auch Rollen
 * wegnehmen duerfen.
 *
 * Die Rueckmeldung laeuft ueber Flash-Nachrichten und nicht ueber ein
 * wiederbefuelltes Formular: die Eingabe ist ein Feld, und die Seite darunter
 * ist die eigentliche Arbeit.
 */
final class RoleFormController extends AbstractController
{
    /**
     * Die Kennung einer Rolle, wie PostgreSQL sie zurueckgibt.
     *
     * Erzeugt wird sie als 32 Hexziffern; die Spalte ist ein UUID, und von
     * dort kommt sie mit Bindestrichen zurueck. Ein Muster ohne sie faengt
     * genau nichts ab — die Seite koennte dann keinen einzigen Link zeichnen.
     */
    private const string ID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly ManageRoles $manage,
        private readonly RoleRepository $roles,
    ) {
    }

    #[IsGranted(AuthPermissions::ROLES_EDIT)]
    #[Route('/rollen/neu', name: 'app_role_new', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->guard($request, 'role_new');

        $name = $this->readName($request);

        if (null === $name) {
            return $this->back();
        }

        $taken = $this->manage->reasonAgainstName($name);

        if (null !== $taken) {
            return $this->back($taken);
        }

        $this->manage->create($name);

        return $this->back('role.created', 'success');
    }

    #[IsGranted(AuthPermissions::ROLES_EDIT)]
    #[Route('/rollen/{id}/umbenennen', name: 'app_role_rename', requirements: ['id' => self::ID], methods: ['POST'])]
    public function rename(string $id, Request $request): Response
    {
        $this->guard($request, 'role_rename_'.$id);

        $role = $this->role($id);
        $name = $this->readName($request);

        if (null === $name) {
            return $this->back();
        }

        $refusal = $this->manage->reasonAgainstRenaming($role) ?? $this->manage->reasonAgainstName($name, $role);

        if (null !== $refusal) {
            return $this->back($refusal);
        }

        $this->manage->rename($role, $name);

        return $this->back('role.renamed', 'success');
    }

    #[IsGranted(AuthPermissions::ROLES_DELETE)]
    #[Route('/rollen/{id}/loeschen', name: 'app_role_delete', requirements: ['id' => self::ID], methods: ['POST'])]
    public function delete(string $id, Request $request): Response
    {
        $this->guard($request, 'role_delete_'.$id);

        $role = $this->role($id);
        $refusal = $this->manage->reasonAgainstDeleting($role);

        if (null !== $refusal) {
            return $this->back($refusal);
        }

        $this->manage->delete($role);

        return $this->back('role.deleted', 'success');
    }

    private function guard(Request $request, string $token): void
    {
        if (!$this->isCsrfTokenValid($token, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    private function role(string $id): Role
    {
        return $this->roles->byId($id) ?? throw $this->createNotFoundException('Diese Rolle gibt es nicht.');
    }

    /** Meldet den Fehler selbst und gibt null zurueck, wenn der Name nicht taugt. */
    private function readName(Request $request): ?RoleName
    {
        $input = Trimmed::orNull($request->request->getString('name'));

        if (null === $input) {
            $this->addFlash('error', 'role.name_required');

            return null;
        }

        if (mb_strlen($input) > RoleName::MAX_LENGTH) {
            $this->addFlash('error', 'role.name_too_long');

            return null;
        }

        return RoleName::fromString($input);
    }

    private function back(?string $message = null, string $kind = 'error'): Response
    {
        if (null !== $message) {
            $this->addFlash($kind, $message);
        }

        return $this->redirectToRoute('app_role');
    }
}
