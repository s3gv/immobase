<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Settings\UserInterface\Controller;

use App\Module\Settings\Application\Settings;
use App\Module\Settings\Application\SettingsPage;
use App\Module\Settings\Contract\ApplicationSettings;
use App\Module\Settings\Contract\SettingsPermissions;
use App\Module\Settings\Domain\LogoRepository;
use App\Module\Settings\Domain\OrganisationKeys;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Die Einstellungen der Installation.
 *
 * Ein Abschnitt je Thema, und jeder speichert fuer sich: wer die Anschrift
 * aendert, soll nicht das Logo mit abschicken, und ein Fehler im einen darf
 * den anderen nicht zurueckwerfen. Deshalb je Abschnitt eine eigene Route
 * und kein gemeinsames POST.
 *
 * Was hier nicht hingehoert: der Mailserver. Seine Angaben enthalten ein
 * Passwort, sie werden einmal eingerichtet, und sie muessen auch dann noch
 * aenderbar sein, wenn niemand mehr in die Anwendung hineinkommt. Sie stehen
 * deshalb in .env.local und werden von install.sh abgefragt.
 */
#[IsGranted(SettingsPermissions::VIEW)]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly Settings $settings,
        private readonly SettingsPage $page,
        private readonly OrganisationInput $organisationInput,
        private readonly LogoRepository $logos,
    ) {
    }

    #[Route('/einstellungen', name: 'app_settings', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $frame = $this->page->frame($request->query->getString('abschnitt'));

        // Abschnitte, die einem anderen Modul gehören, liefert dieses Modul
        // nicht aus — es schickt hin. Ohne diese Zeile suchte die Anwendung
        // hier nach einer Vorlage, die es nur dort gibt.
        if (isset(SettingsPage::FOREIGN[$frame['current']])) {
            return $this->redirectToRoute(SettingsPage::FOREIGN[$frame['current']]);
        }

        return $this->show($frame['current']);
    }

    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/organisation', name: 'app_settings_organisation', methods: ['POST'])]
    public function organisation(Request $request): Response
    {
        $this->guard($request);
        $errors = $this->organisationInput->save($request);

        if ([] === $errors) {
            return $this->saved('organisation');
        }

        // Zurueck mit dem, was abgeschickt wurde: wer sich vertippt hat, will
        // die Stelle verbessern und nicht alles noch einmal tippen.
        return $this->show('organisation', $errors, $request->request->all());
    }

    /**
     * Speichern ist eine eigene Route, nicht das POST derselben.
     *
     * Ansehen und Aendern sind zwei Rechte, und ein #[IsGranted] gilt fuer
     * die ganze Route — mit einer Route fuer beides liesse sich nur eines
     * davon pruefen.
     */
    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/app', name: 'app_settings_app', methods: ['POST'])]
    public function app(Request $request): Response
    {
        $this->guard($request);
        $this->settings->setBool(
            ApplicationSettings::REDUCED_MOTION,
            $request->request->getBoolean('reduced_motion'),
        );

        return $this->saved('app');
    }

    /**
     * Die beiden Werte des Portals.
     *
     * Sie werden hier begrenzt und nicht erst beim Lesen: wer 300 Tage
     * eintraegt und danach 7 dastehen saehe, wuesste nicht, was nun gilt.
     * Beim Lesen wird trotzdem noch einmal begrenzt — fuer Werte, die auf
     * einem anderen Weg hereinkamen.
     */
    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/portal', name: 'app_settings_portal', methods: ['POST'])]
    public function portal(Request $request): Response
    {
        $this->guard($request);

        $this->settings->setInt(ApplicationSettings::PORTAL_FILE_RETENTION_DAYS, self::within(
            $request->request->getInt('retention_days', ApplicationSettings::PORTAL_FILE_RETENTION_DEFAULT),
            ApplicationSettings::PORTAL_FILE_RETENTION_MIN,
            ApplicationSettings::PORTAL_FILE_RETENTION_MAX,
        ));

        $this->settings->setInt(ApplicationSettings::PORTAL_NOTIFY_AFTER_MINUTES, self::within(
            $request->request->getInt('notify_after_minutes', ApplicationSettings::PORTAL_NOTIFY_AFTER_DEFAULT),
            ApplicationSettings::PORTAL_NOTIFY_AFTER_MIN,
            ApplicationSettings::PORTAL_NOTIFY_AFTER_MAX,
        ));

        return $this->saved('portal');
    }

    #[IsGranted(SettingsPermissions::EDIT)]
    #[Route('/einstellungen/sicherheit', name: 'app_settings_security', methods: ['POST'])]
    public function security(Request $request): Response
    {
        $this->guard($request);
        $this->settings->setBool(ApplicationSettings::LEAK_CHECK, $request->request->getBoolean('leak_check'));

        return $this->saved('sicherheit');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $submitted
     */
    private function show(string $section, array $errors = [], array $submitted = []): Response
    {
        return $this->render('settings/'.$section.'.html.twig', [
            ...$this->page->frame($section),
            'leakCheck' => $this->settings->bool(ApplicationSettings::LEAK_CHECK, true),
            'reducedMotion' => $this->settings->bool(ApplicationSettings::REDUCED_MOTION, false),
            'values' => $this->valuesFor($submitted),
            'portal' => $this->portalValues(),
            'logo' => $this->logos->current(),
            'errors' => $errors,
        ]);
    }

    /**
     * Was im Organisationsformular steht.
     *
     * Nach einem Fehler das Abgeschickte, sonst der gespeicherte Stand: wer
     * sich vertippt hat, will die Stelle verbessern und nicht alles noch
     * einmal tippen. Die Regel steht hier und nicht in der Vorlage —
     * vierzehnmal derselbe Dreisatz waere vierzehnmal die Gelegenheit, ihn
     * einmal anders zu schreiben.
     *
     * @param array<string, mixed> $submitted
     *
     * @return array<string, string>
     */
    private function valuesFor(array $submitted): array
    {
        $values = [];

        foreach (OrganisationKeys::all() as $field => $key) {
            $sent = $submitted[$field] ?? null;
            $values[$field] = \is_string($sent) ? $sent : $this->settings->text($key);
        }

        return $values;
    }

    /**
     * Die Portalwerte samt ihrer Grenzen — die Vorlage soll keine Zahlen
     * kennen, die sonst nirgends stehen.
     *
     * @return array<string, int>
     */
    private function portalValues(): array
    {
        return [
            'retentionDays' => $this->settings->int(
                ApplicationSettings::PORTAL_FILE_RETENTION_DAYS,
                ApplicationSettings::PORTAL_FILE_RETENTION_DEFAULT,
            ),
            'retentionLeast' => ApplicationSettings::PORTAL_FILE_RETENTION_MIN,
            'retentionMost' => ApplicationSettings::PORTAL_FILE_RETENTION_MAX,
            'notifyMinutes' => $this->settings->int(
                ApplicationSettings::PORTAL_NOTIFY_AFTER_MINUTES,
                ApplicationSettings::PORTAL_NOTIFY_AFTER_DEFAULT,
            ),
            'notifyLeast' => ApplicationSettings::PORTAL_NOTIFY_AFTER_MIN,
            'notifyMost' => ApplicationSettings::PORTAL_NOTIFY_AFTER_MAX,
        ];
    }

    private static function within(int $value, int $least, int $most): int
    {
        return max($least, min($most, $value));
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('settings', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }
    }

    /** Zurueck in den Abschnitt, aus dem gespeichert wurde. */
    private function saved(string $section): Response
    {
        $this->addFlash('success', 'settings.saved');

        return $this->redirectToRoute('app_settings', ['abschnitt' => $section]);
    }
}
