<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\UserInterface\Controller;

use App\Module\Auth\Contract\UserDirectory;
use App\Module\Plugin\Application\ActivatePlugin;
use App\Module\Plugin\Application\DiscoverPlugins;
use App\Module\Plugin\Application\FoundPlugin;
use App\Module\Plugin\Application\RemovePlugin;
use App\Module\Plugin\Application\SetPluginState;
use App\Module\Plugin\Application\UpdatePlugin;
use App\Module\Plugin\Domain\PluginAlreadyActive;
use App\Module\Plugin\Domain\PluginState;
use App\Module\Settings\Application\SettingsPage;
use App\Module\Settings\Contract\SettingsPermissions;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Plugins ansehen, aktivieren, aussetzen, entfernen.
 *
 * Liegt unter den Einstellungen und benutzt deren Seitenrahmen — wer dort
 * etwas sucht, soll nicht anderswo landen. Die Seite gehoert trotzdem diesem
 * Modul: es haelt die Manifeste, die Prozesse und den Speicher.
 *
 * **Aktivieren ist ein eigener Schritt mit eigener Seite.** Auf ihr steht,
 * was das Plugin verlangt und was es anlegt, und darunter die Marke, mit der
 * jemand erklaert, dass er ihm vertraut. Ein Knopf in einer Liste waere dafuer
 * zu wenig.
 */
#[IsGranted(SettingsPermissions::VIEW)]
final class PluginSettingsController extends AbstractController
{
    public function __construct(
        private readonly DiscoverPlugins $discover,
        private readonly SettingsPage $page,
        private readonly Security $security,
        private readonly UserDirectory $users,
    ) {
    }

    #[Route('/einstellungen/plugins', name: 'app_plugin_settings', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('settings/plugins.html.twig', [
            ...$this->page->frame('plugins'),
            'plugins' => ($this->discover)(),
        ]);
    }

    /** Die Seite, auf der jemand vertraut. */
    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/plugins/{name}', name: 'app_plugin_confirm', methods: ['GET'], requirements: ['name' => '[a-z][a-z0-9_]*'])]
    public function confirm(string $name): Response
    {
        $plugin = $this->find($name);

        if ($plugin->isBroken() || !$plugin->onDisk) {
            return $this->redirectToRoute('app_plugin_settings');
        }

        return $this->render('settings/plugin_confirm.html.twig', [
            ...$this->page->frame('plugins'),
            'plugin' => $plugin,
        ]);
    }

    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/plugins/{name}/aktivieren', name: 'app_plugin_activate', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]*'])]
    public function activate(string $name, Request $request, ActivatePlugin $activate, UpdatePlugin $update): Response
    {
        $this->guard($request);

        if (!$request->request->getBoolean('trusted')) {
            $this->addFlash('error', 'plugin.flash.untrusted');

            return $this->redirectToRoute('app_plugin_confirm', ['name' => $name]);
        }

        $actor = $this->actor();

        if ($this->find($name)->isInstalled()) {
            $update($name, $actor);
            $this->addFlash('success', 'plugin.flash.updated');
        } else {
            $this->activateOnce($activate, $name, $actor);
        }

        return $this->redirectToRoute('app_plugin_settings');
    }

    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/plugins/{name}/zustand', name: 'app_plugin_state', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]*'])]
    public function state(string $name, Request $request, SetPluginState $set): Response
    {
        $this->guard($request);
        $set($name, $request->request->getBoolean('active') ? PluginState::Active : PluginState::Suspended);
        $this->addFlash('success', 'plugin.flash.state');

        return $this->redirectToRoute('app_plugin_settings');
    }

    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/plugins/{name}/entfernen', name: 'app_plugin_remove', methods: ['POST'], requirements: ['name' => '[a-z][a-z0-9_]*'])]
    public function remove(string $name, Request $request, RemovePlugin $remove): Response
    {
        $this->guard($request);
        $remove($name);
        $this->addFlash('success', 'plugin.flash.removed');

        return $this->redirectToRoute('app_plugin_settings');
    }

    /**
     * Zwei, die zugleich aktivieren, bekommen beide eine ruhige Antwort.
     *
     * Die Seite leitet ein schon aktives Plugin sonst zum Aktualisieren; hier
     * landet nur, wer im selben Augenblick wie jemand anderes gedrueckt hat.
     */
    private function activateOnce(ActivatePlugin $activate, string $name, string $actor): void
    {
        try {
            $activate($name, $actor);
            $this->addFlash('success', 'plugin.flash.activated');
        } catch (PluginAlreadyActive) {
            $this->addFlash('info', 'plugin.flash.already_active');
        }
    }

    private function find(string $name): FoundPlugin
    {
        foreach (($this->discover)() as $plugin) {
            if ($plugin->name === $name) {
                return $plugin;
            }
        }

        throw $this->createNotFoundException(\sprintf('Kein Plugin namens „%s".', $name));
    }

    /**
     * Wer vertraut hat — mit Namen und nicht als Kennung.
     *
     * Dieselbe Auskunft wie im Änderungsprotokoll: die Zeile soll auch dann
     * noch lesbar sein, wenn das Konto längst weg ist.
     */
    private function actor(): string
    {
        $email = $this->security->getUser()?->getUserIdentifier() ?? 'System';

        return $this->users->byEmail($email)->displayName ?? $email;
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('plugins', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }
}
