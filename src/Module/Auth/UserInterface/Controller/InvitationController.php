<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Application\CheckPassword;
use App\Module\Auth\Application\RedeemToken;
use App\Module\Auth\Application\SetUpAccount;
use App\Module\Auth\Domain\TokenPurpose;
use App\Module\Auth\Domain\User;
use App\Module\Auth\Domain\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Das Einrichten des eigenen Kontos ueber einen Link aus der E-Mail.
 *
 * Ohne Anmeldung erreichbar — der Schluessel in der Adresse ist der Ausweis.
 * Und ohne Anmeldung endend: wer den Link hat, bekommt hier keine Sitzung,
 * sondern richtet ein und meldet sich danach regulaer an. Erst diese Anmeldung
 * belegt, dass Adresse und Passwort zusammengefunden haben.
 *
 * Einladung und Passwort-Zuruecksetzen laufen durch denselben Ablauf. Der
 * Unterschied ist nur, was schon eingetragen ist: wer seinen Namen bereits
 * hat, ueberspringt den Schritt von selbst.
 *
 * **Beim Zuruecksetzen ist der Link mit dem neuen Passwort verbraucht.** Was
 * danach kommt, gehoert der Sitzung, die es gesetzt hat. Ein mitgelesener
 * Link setzt so nicht noch einmal ein Passwort, nachdem der Inhaber seines
 * gewaehlt hat — und das Konto erfaehrt per E-Mail davon.
 */
final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly RedeemToken $redeem,
        private readonly SetUpAccount $setUp,
        private readonly CheckPassword $password,
        private readonly SecondFactorSetup $factor,
        private readonly ResetProgress $progress,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/einladung/{token}', name: 'app_invitation', methods: ['GET', 'POST'])]
    public function invitation(string $token, Request $request): Response
    {
        return $this->run($token, TokenPurpose::Invite, 'app_invitation', $request);
    }

    #[Route('/passwort/neu/{token}', name: 'app_password_reset_link', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        return $this->run($token, TokenPurpose::Reset, 'app_password_reset_link', $request);
    }

    private function run(string $token, TokenPurpose $purpose, string $route, Request $request): Response
    {
        $user = $this->accountFor($token, $purpose);

        // Abgelaufen, verbraucht, unbekannt: dieselbe Seite und derselbe
        // Statuscode. Ein Unterschied waere eine Auskunft darueber, welche
        // Konten es gibt.
        if (null === $user) {
            return $this->expired($purpose);
        }

        if (!$request->isMethod('POST')) {
            return $this->show($user, $token, $purpose, $route);
        }

        if (!$this->isCsrfTokenValid('flow_invitation', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Ungültiges Formular-Token.');
        }

        return $this->handle($user, $token, $purpose, $route, $request);
    }

    /** Der Link — oder, beim Zuruecksetzen nach dem Passwort, die Sitzung. */
    private function accountFor(string $token, TokenPurpose $purpose): ?User
    {
        $continuing = TokenPurpose::Reset === $purpose ? $this->progress->accountOf($token) : null;

        if (null === $continuing) {
            return $this->redeem->owner($token, $purpose);
        }

        $user = $this->users->byId($continuing);

        return null !== $user && !$user->status()->isBlocked() ? $user : null;
    }

    private function handle(User $user, string $token, TokenPurpose $purpose, string $route, Request $request): Response
    {
        $step = $this->stepFor($user, $token, $purpose);
        $error = $this->apply($user, $step, $purpose, $request);

        if (null !== $error) {
            return $this->show($user, $token, $purpose, $route, $error);
        }

        if (InvitationFlow::PASSWORD === $step && TokenPurpose::Reset === $purpose) {
            $this->progress->rememberPassword($token, $user->id());
        }

        return InvitationFlow::FACTOR === $step || $this->nothingLeft($user, $token, $purpose)
            ? $this->finish($token, $purpose)
            : $this->redirectToRoute($route, ['token' => $token]);
    }

    /** Der naechste Schritt waere der Faktor, und der wird nicht angeboten. */
    private function nothingLeft(User $user, string $token, TokenPurpose $purpose): bool
    {
        return InvitationFlow::FACTOR === $this->stepFor($user, $token, $purpose) && !$this->offersFactor($user, $purpose);
    }

    /**
     * Erst hier ist eine Einladung verbraucht.
     *
     * Nicht schon beim ersten Schritt: wer beim zweiten Faktor abbricht, soll
     * mit demselben Link weitermachen koennen. Ein Link zum Zuruecksetzen ist
     * es schon — das Passwort hat ihn entwertet.
     */
    private function finish(string $token, TokenPurpose $purpose): Response
    {
        // Wer den Schluessel nicht verbraucht hat, war zu spaet — dann gilt
        // dieselbe Seite wie fuer jeden anderen ungueltigen Link.
        $finished = TokenPurpose::Reset === $purpose
            ? $this->progress->passwordIsSet($token)
            : $this->redeem->consume($token, $purpose);

        if (!$finished) {
            return $this->expired($purpose);
        }

        $this->progress->forget();
        $codes = $this->factor->takeRecoveryCodes();

        if ([] === $codes) {
            $this->addFlash('success', 'user.setup.done');

            return $this->redirectToRoute('app_login');
        }

        // Die Codes stehen genau einmal auf dem Bildschirm. Hier und nicht
        // als Meldung auf der Anmeldeseite: sie sollen sich in Ruhe
        // ausdrucken lassen.
        return $this->render('invitation/recovery_codes.html.twig', ['codes' => $codes]);
    }

    private function apply(User $user, string $step, TokenPurpose $purpose, Request $request): ?string
    {
        return match ($step) {
            InvitationFlow::PASSWORD => $this->setUp->choosePassword(
                $user,
                $request->request->getString('password'),
                $request->request->getString('repeated'),
                notify: TokenPurpose::Reset === $purpose,
            ),
            InvitationFlow::PROFILE => $this->setUp->describeYourself(
                $user,
                $request->request->getString('givenName'),
                $request->request->getString('familyName'),
                $request->request->getString('jobTitle'),
            ),
            default => $this->offersFactor($user, $purpose) ? $this->factor->handle($user, $request) : null,
        };
    }

    private function offersFactor(User $user, TokenPurpose $purpose): bool
    {
        return TokenPurpose::Reset !== $purpose || !$user->secondFactor()->kind()->isSet();
    }

    /**
     * Beim Einrichten beantwortet das Konto selbst, ob das Passwort erledigt
     * ist. Beim Zuruecksetzen kann es das nicht — dort *gibt* es eines, und
     * genau darum geht es.
     */
    private function stepFor(User $user, string $token, TokenPurpose $purpose): string
    {
        $passwordDone = TokenPurpose::Reset === $purpose
            ? $this->progress->passwordIsSet($token)
            : $user->hasPassword();

        return InvitationFlow::stepFor($user, $passwordDone);
    }

    private function show(User $user, string $token, TokenPurpose $purpose, string $route, ?string $error = null): Response
    {
        if ($this->nothingLeft($user, $token, $purpose)) {
            return $this->finish($token, $purpose);
        }

        $definition = InvitationFlow::definition($user, $this->offersFactor($user, $purpose));
        $step = $this->stepFor($user, $token, $purpose);

        return $this->render('invitation/'.$step.'.html.twig', [
            'definition' => $definition,
            'state' => InvitationFlow::stateFor($definition, $step),
            'step' => $definition->step($step),
            'action' => $this->generateUrl($route, ['token' => $token]),
            'heading' => 'user.setup.heading',
            'error' => $error,
            'user' => $user,
            'rules' => $this->password->rules(),
            'factor' => $this->factor->offer(),
        ]);
    }

    private function expired(TokenPurpose $purpose): Response
    {
        return $this->render('user/link_expired.html.twig', ['purpose' => $purpose->value]);
    }
}
