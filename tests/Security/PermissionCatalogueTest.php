<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Tests\Security;

use App\Module\Auth\Application\Rbac\PermissionCatalogue;
use App\Shared\Security\Permission;
use ReflectionAttribute;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted as IsGrantedAttribute;
use Symfony\Component\Yaml\Yaml;

/**
 * Verwaiste Rechte gibt es nicht — hier faellt der Lauf durch.
 *
 * Das Vorgaengersystem hielt den Katalog in der Datenbank, glich ihn per
 * Konsolenbefehl mit dem Code ab und liess ein CI-Tor ueber die Abweichung
 * wachen. Steht der Katalog im Code, wird daraus eine Frage, die ein Test
 * beantworten kann: verlangt ueberhaupt jemand dieses Recht?
 *
 * Gelesen wird Symfonys eigenes #[IsGranted]. Ein eigenes Attribut waere eine
 * zweite Wahrheit — und die erste, an der sich jemand vorbeischreibt.
 */
final class PermissionCatalogueTest extends KernelTestCase
{
    private const array LOCALES = ['de', 'en'];

    public function testEveryDeclaredPermissionIsRequiredSomewhere(): void
    {
        $required = self::requiredKeys();

        foreach (self::catalogue()->keys() as $key) {
            self::assertContains($key, $required, \sprintf(
                'Die Permission "%s" steht im Katalog, aber keine Route verlangt sie. '
                .'In der Matrix wäre das ein Kästchen ohne Wirkung — entweder eine Route schützen oder die Deklaration entfernen.',
                $key,
            ));
        }
    }

    public function testEveryRequiredPermissionIsDeclared(): void
    {
        $catalogue = self::catalogue();

        foreach (self::requiredKeys() as $key) {
            self::assertTrue($catalogue->has($key), \sprintf(
                'Eine Route verlangt "%s", aber kein Bereich deklariert es. '
                .'Der Prüfer verweigert dann stumm — die Permission gehört in eine PermissionSource.',
                $key,
            ));
        }
    }

    public function testEveryPermissionHasLabelAndExplanation(): void
    {
        $messages = array_map(self::messagesOf(...), array_combine(self::LOCALES, self::LOCALES));

        foreach (self::catalogue()->all() as $permission) {
            foreach ($messages as $locale => $translations) {
                self::assertKeyExists($translations, $permission->labelKey(), $locale);
                self::assertKeyExists($translations, $permission->explanationKey(), $locale);
                self::assertKeyExists($translations, $permission->areaLabelKey(), $locale);
            }
        }
    }

    /**
     * Jeder #[IsGranted]-Wert, der wie ein Rechteschluessel aussieht.
     *
     * Klasse und Methode werden beide gelesen: die meisten Controller schuetzen
     * sich in der Klassenzeile und verschaerfen einzelne Aktionen darunter.
     *
     * @return list<string>
     */
    private static function requiredKeys(): array
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $keys = [];

        foreach ($router->getRouteCollection() as $route) {
            foreach (self::attributesOf($route->getDefault('_controller')) as $value) {
                if (\is_string($value) && Permission::looksLikeKey($value)) {
                    $keys[$value] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * @return list<mixed> die Attributwerte aller IsGranted an Klasse und Methode
     */
    private static function attributesOf(mixed $controller): array
    {
        if (!\is_string($controller) || !str_contains($controller, '::')) {
            return [];
        }

        $parts = explode('::', $controller, 2);

        if (2 !== \count($parts) || !class_exists($parts[0])) {
            return [];
        }

        [$class, $method] = $parts;

        $reflection = new ReflectionClass($class);
        $attributes = $reflection->getAttributes(IsGrantedAttribute::class);

        if ($reflection->hasMethod($method)) {
            $attributes = [...$attributes, ...$reflection->getMethod($method)->getAttributes(IsGrantedAttribute::class)];
        }

        return array_map(
            static fn (ReflectionAttribute $attribute): mixed => $attribute->newInstance()->attribute,
            $attributes,
        );
    }

    private static function catalogue(): PermissionCatalogue
    {
        $catalogue = self::getContainer()->get(PermissionCatalogue::class);
        self::assertInstanceOf(PermissionCatalogue::class, $catalogue);

        return $catalogue;
    }

    /**
     * Gelesen wird die Datei und nicht der Uebersetzer.
     *
     * Der faellt bei einer fehlenden englischen Zeichenkette auf die deutsche
     * zurueck — die Luecke waere dann genau da nicht zu sehen, wo dieser Test
     * hinschaut.
     *
     * @return array<mixed, mixed>
     */
    private static function messagesOf(string $locale): array
    {
        $parsed = Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.'.$locale.'.yaml');
        self::assertIsArray($parsed);

        return $parsed;
    }

    /**
     * @param array<mixed, mixed> $messages
     */
    private static function assertKeyExists(array $messages, string $key, string $locale): void
    {
        $node = $messages;

        foreach (explode('.', $key) as $segment) {
            self::assertIsArray($node, \sprintf('%s fehlt in messages.%s.yaml.', $key, $locale));
            self::assertArrayHasKey($segment, $node, \sprintf(
                '%s fehlt in messages.%s.yaml. Eine Permission ohne Bezeichnung und Erklärung '
                .'ist für den Menschen davor keine — sie steht in der Matrix und unter "Meine Rechte".',
                $key,
                $locale,
            ));
            $node = $node[$segment];
        }

        self::assertIsString($node);
        self::assertNotSame('', trim($node));
    }
}
