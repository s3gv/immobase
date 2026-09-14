<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Portal\UserInterface\Controller;

use App\Module\Portal\Domain\Attachment;
use App\Module\Portal\Domain\FileVault;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Eine Datei herausgeben — fuer beide Seiten dieselbe Regel.
 *
 * **Die Frist wird hier geprueft und nicht erst vom Aufraeumlauf.** Sie gilt
 * ab der Sekunde und nicht ab dem naechsten Durchgang; ein Versprechen, das
 * an einem Zeitplan haengt, ist keines.
 *
 * **Nichts wird im Browser dargestellt.** Immer als Anhang, immer
 * `application/octet-stream`, immer `nosniff`. Die gemeldete Art der Datei
 * steht zwar in der Zeile, wird aber nie zum Ausliefern benutzt: sie kommt
 * vom Browser des Absenders und ist damit eine Behauptung. Was der Browser
 * nicht darstellt, kann er auch nicht ausfuehren.
 *
 * Wem die Datei gehoert, entscheidet der Aufrufer — im Portal die Partei aus
 * der Sitzung, im Verwalterbereich das Recht an den Anfragen.
 */
final readonly class ServeAttachment
{
    public function __construct(
        private FileVault $vault,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(Attachment $attachment): Response
    {
        if ($attachment->isExpired($this->clock->now())) {
            throw new NotFoundHttpException('Diese Datei ist abgelaufen.');
        }

        $plain = $this->vault->open($attachment->sealed(), $attachment->id());

        if (null === $plain) {
            throw new NotFoundHttpException('Diese Datei lässt sich nicht öffnen.');
        }

        $response = new Response($plain);
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $attachment->name(),
            // Der Ersatzname fuer Browser, die keine Umlaute im Kopf
            // vertragen. Der eigene Name ist eine Beschriftung, nie ein Pfad.
            'anhang',
        ));

        return $response;
    }
}
