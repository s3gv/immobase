<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Infrastructure;

use App\Module\Plugin\Application\ActiveManifests;
use App\Module\Plugin\Domain\Delivery;
use App\Module\Plugin\Domain\DeliveryRepository;
use App\Module\Plugin\Domain\Manifest\Manifest;
use App\Shared\Api\PublishesResource;
use App\Shared\Identity\Uuid;
use App\Shared\Write\ObservesWrites;
use App\Shared\Write\WriteHappened;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Aus jedem Schreibvorgang wird ein Webhook — fuer die, die ihn sehen duerfen.
 *
 * **Es gibt Ereignisse genau fuer die Ressourcen, die es auch zu lesen gibt.**
 * Ein Datensatz ohne veroeffentlichte Ressource loest keines aus, und ein
 * Plugin, dem der Lesebereich fehlt, bekommt keines. Damit kann durch die
 * Hintertuer nichts hinausgehen, was vorne niemand abrufen koennte — nicht
 * einmal die Auskunft, dass es sich geaendert hat.
 *
 * **Die Nutzlast traegt keine Fachdaten**, nur den Hinweis: Ereignis,
 * Ressource, Kennung, Zeitpunkt. Mit Daten waere sie eine zweite Stelle, an
 * der Berechtigungen geprueft werden muessen — und damit eine zweite, an der
 * man sie vergisst. Das Plugin holt sich, was es braucht, ueber `/api/v1/`.
 */
final readonly class DeliversEvents implements ObservesWrites
{
    /**
     * @param iterable<PublishesResource> $resources
     */
    public function __construct(
        #[AutowireIterator('api.resource')]
        private iterable $resources,
        private ActiveManifests $manifests,
        private DeliveryRepository $deliveries,
    ) {
    }

    public function saw(array $writes): void
    {
        $plugins = $this->manifests->all();

        if ([] === $plugins) {
            return;
        }

        $pending = [];

        foreach ($writes as $write) {
            $pending = [...$pending, ...$this->forWrite($write, $plugins)];
        }

        if ([] !== $pending) {
            $this->deliveries->append($pending);
        }
    }

    /**
     * @param list<array{string, Manifest}> $plugins
     *
     * @return list<Delivery>
     */
    private function forWrite(WriteHappened $write, array $plugins): array
    {
        $owner = $this->ownerOf($write->entity);

        if (null === $owner) {
            return [];
        }

        [$resource, $id] = $owner;
        $event = $resource->name().'.'.$write->action;
        $payload = self::payload($event, $resource->name(), $id, $write);
        $found = [];

        foreach ($plugins as [$name, $manifest]) {
            if (self::wants($manifest, $event, $resource->permission())) {
                $found[] = new Delivery(Uuid::v4(), $name, $event, $payload, $write->at, $write->at);
            }
        }

        return $found;
    }

    /**
     * Die Ressource, zu der ein geschriebener Datensatz gehoert — und die
     * Kennung, unter der sie ihn fuehrt.
     *
     * Die erste, die ihn erkennt, gewinnt. Zwei Ressourcen fuer denselben
     * Datensatz gibt es nicht: dann waere schon die Frage falsch, welche ihn
     * meldet.
     *
     * @return array{PublishesResource, string}|null
     */
    private function ownerOf(object $entity): ?array
    {
        foreach ($this->resources as $resource) {
            $id = $resource->identifies($entity);

            if (null !== $id && '' !== $id) {
                return [$resource, $id];
            }
        }

        return null;
    }

    /** Angemeldet **und** freigegeben — beides, nicht eines von beiden. */
    private static function wants(Manifest $manifest, string $event, string $permission): bool
    {
        if (!\in_array($permission, $manifest->reads, true)) {
            return false;
        }

        foreach ($manifest->events as $pattern) {
            if (self::matches($pattern, $event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `costs.*`, `*.created`, `costs.updated` — Stern steht fuer alles.
     *
     * Zwei Teile und keine regulaeren Ausdruecke: ein Manifest soll keine
     * Sprache mitbringen, die man erst lernen muss, und ein Muster aus einer
     * fremden Datei soll nichts ausfuehren koennen.
     */
    private static function matches(string $pattern, string $event): bool
    {
        $wanted = explode('.', $pattern);
        $happened = explode('.', $event);

        if (2 !== \count($wanted) || 2 !== \count($happened)) {
            return false;
        }

        return ('*' === $wanted[0] || $wanted[0] === $happened[0])
            && ('*' === $wanted[1] || $wanted[1] === $happened[1]);
    }

    private static function payload(string $event, string $resource, string $id, WriteHappened $write): string
    {
        return json_encode([
            'event' => $event,
            'resource' => $resource,
            'id' => $id,
            'at' => $write->at->format(DateTimeImmutable::ATOM),
        ], \JSON_THROW_ON_ERROR);
    }
}
