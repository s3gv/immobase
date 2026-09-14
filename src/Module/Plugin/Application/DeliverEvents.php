<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Application;

use App\Module\Plugin\Domain\Delivery;
use App\Module\Plugin\Domain\DeliveryRepository;
use App\Module\Plugin\Domain\Plugin;
use App\Module\Plugin\Domain\PluginRepository;
use App\Module\Plugin\Infrastructure\DbalDeliveryRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpFailure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Traegt aus, was in der Ablage liegt.
 *
 * **Ein eigener Lauf und kein Teil des Speicherns.** Er laeuft im
 * Hintergrundlauf des Anwendungscontainers mit — derselbe, der auch die
 * Anhaenge und das Protokoll aufraeumt. Ein Webhook waehrend des Speicherns haette die
 * Erreichbarkeit eines fremden Prozesses zur Bedingung dafuer gemacht, dass
 * eine Kostenposition abgelegt werden kann.
 *
 * **Wiederholt mit wachsendem Abstand** und irgendwann aufgegeben. Ein
 * Plugin, das seit Monaten aus ist, soll keinen Stapel erzeugen, den nie
 * jemand abtraegt.
 *
 * **Ausgesetzt oder entfernt heisst: die Zeile geht mit.** Gefragt wird der
 * Zustand und nicht nur die Anwesenheit — eine Installation gibt es auch im
 * ausgesetzten Zustand, und „aussetzen" muss aussetzen, sonst liefe die
 * Zustellung weiter, waehrend die Oberflaeche das Plugin als abgeschaltet
 * zeigt. Ein Stapel, der beim Wiederaufnehmen auf einen Schlag hinausginge,
 * waere fuer das Plugin schlimmer als die Luecke.
 */
final readonly class DeliverEvents
{
    /** Minuten bis zum naechsten Versuch, je Fehlschlag. */
    private const array BACKOFF = [1, 5, 25, 125];

    private const int BATCH = 50;

    private const int TIMEOUT = 5;

    public function __construct(
        private DeliveryRepository $deliveries,
        private PluginRepository $plugins,
        private ActiveManifests $manifests,
        private HttpClientInterface $client,
        private ClockInterface $clock,
    ) {
    }

    /** Wie viele Zustellungen angekommen sind. */
    public function __invoke(): int
    {
        $delivered = 0;

        foreach ($this->deliveries->due($this->clock->now(), self::BATCH) as $delivery) {
            $delivered += $this->deliver($delivery) ? 1 : 0;
        }

        return $delivered;
    }

    private function deliver(Delivery $delivery): bool
    {
        $plugin = $this->plugins->byName($delivery->plugin);
        $manifest = $this->manifests->of($delivery->plugin);

        if (null === $plugin || !$plugin->isActive() || null === $manifest) {
            $this->deliveries->forget($delivery->id);

            return false;
        }

        $failure = $this->post($plugin->address().$manifest->webhookPath, $delivery, $plugin);

        if (null !== $failure) {
            $this->again($delivery, $failure);

            return false;
        }

        $this->deliveries->forget($delivery->id);

        return true;
    }

    /** Der Fehler, wenn es einen gab — sonst nichts. */
    private function post(string $url, Delivery $delivery, Plugin $plugin): ?string
    {
        try {
            $status = $this->client->request('POST', $url, [
                'headers' => self::headersFor($delivery, $plugin),
                'body' => $delivery->payload,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::TIMEOUT,
                'max_redirects' => 0,
            ])->getStatusCode();
        } catch (HttpFailure $failed) {
            return $failed->getMessage();
        }

        return $status >= 200 && $status < 300 ? null : 'HTTP '.$status;
    }

    private function again(Delivery $delivery, string $error): void
    {
        $next = $delivery->attempts + 1;

        if ($next >= DbalDeliveryRepository::TRIES) {
            $this->deliveries->giveUp($delivery->id, $error);

            return;
        }

        $minutes = self::BACKOFF[$delivery->attempts] ?? self::BACKOFF[\count(self::BACKOFF) - 1];
        $this->deliveries->retryLater($delivery->id, $this->clock->now()->modify('+'.$minutes.' minutes'), $error);
    }

    /**
     * Die Kopfzeilen einer Zustellung.
     *
     * **Unterschrieben mit dem Abdruck des Tokens.** Beide Seiten kennen ihn:
     * das Plugin hat sein Token und kann ihn ausrechnen, der Core hat ihn
     * gespeichert. Das Token selbst geht dabei nie ueber die Leitung — eine
     * Unterschrift, die das Geheimnis mitschickt, ist keine.
     *
     * Die Kennung der Zustellung steht dabei, damit ein Plugin dieselbe
     * Meldung nicht zweimal verarbeitet: bei einem Wiederholungsversuch nach
     * einer Zeitueberschreitung kann sie ein zweites Mal ankommen.
     *
     * @return array<string, string>
     */
    private static function headersFor(Delivery $delivery, Plugin $plugin): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-ImmoBase-Delivery' => $delivery->id,
            'X-ImmoBase-Event' => $delivery->event,
            'X-ImmoBase-Signature' => 'sha256='.hash_hmac('sha256', $delivery->payload, $plugin->tokenHash()),
        ];
    }
}
