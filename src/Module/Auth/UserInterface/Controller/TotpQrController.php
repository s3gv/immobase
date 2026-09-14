<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\UserInterface\Controller;

use App\Module\Auth\Domain\Totp;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Der QR-Code zum gerade eingerichteten Geheimnis.
 *
 * Als eigene Adresse und nicht als data:-URI im HTML: eine data:-URI landet
 * im Seitenquelltext, im Browserverlauf und in jedem Screenshot-Werkzeug.
 * Diese Adresse liefert nur, was in *dieser* Sitzung gerade eingerichtet wird,
 * und nichts, wenn dort nichts steht.
 *
 * SVG statt PNG: keine GD-Erweiterung noetig, und der Code bleibt in jeder
 * Groesse scharf.
 */
final class TotpQrController extends AbstractController
{
    public function __construct(private readonly RequestStack $requests)
    {
    }

    #[Route('/zwei-faktor/qr', name: 'app_totp_qr', methods: ['GET'])]
    public function qr(): Response
    {
        $secret = $this->pendingSecret();

        if (null === $secret) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $result = (new Builder(
            writer: new SvgWriter(),
            data: Totp::uri($secret, $this->pendingAccount(), 'ImmoBase'),
            size: 200,
            margin: 0,
        ))->build();

        return new Response($result->getString(), Response::HTTP_OK, [
            'Content-Type' => $result->getMimeType(),
            // Ein Geheimnis gehoert in keinen Zwischenspeicher.
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function pendingSecret(): ?string
    {
        /** @var array<string, mixed> $pending */
        $pending = $this->requests->getSession()->get('auth.factor_setup', []);
        $secret = $pending['secret'] ?? null;

        return \is_string($secret) && '' !== $secret ? $secret : null;
    }

    /**
     * Der Name, unter dem der Eintrag in der App erscheint.
     *
     * Beim Einrichten ueber einen Einladungslink gibt es noch keine
     * angemeldete Sitzung — dann steht dort die Adresse, die die Sitzung
     * ohnehin schon kennt.
     */
    private function pendingAccount(): string
    {
        /** @var array<string, mixed> $pending */
        $pending = $this->requests->getSession()->get('auth.factor_setup', []);
        $account = $pending['account'] ?? null;

        return \is_string($account) ? $account : 'ImmoBase';
    }
}
