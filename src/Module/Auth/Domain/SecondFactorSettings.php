<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Auth\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Der eingerichtete zweite Faktor eines Kontos.
 *
 * Art, Geheimnis und zuletzt verbrauchtes Zeitfenster gehoeren zusammen: das
 * Geheimnis ergibt nur bei der App einen Sinn, und das Fenster nur zum
 * Geheimnis. Getrennt am Konto waeren drei Felder, die man einzeln vergessen
 * kann zurueckzusetzen.
 *
 * Unveraenderlich — jede Aenderung gibt einen neuen Zustand zurueck.
 */
#[ORM\Embeddable]
final readonly class SecondFactorSettings
{
    #[ORM\Column(type: Types::STRING, length: 16, enumType: SecondFactor::class)]
    private SecondFactor $kind;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $secret;

    /**
     * Das zuletzt eingeloeste Zeitfenster.
     *
     * Ohne diese Zahl liesse sich ein abgefangener Code innerhalb seiner
     * dreissig Sekunden ein zweites Mal verwenden.
     */
    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    private ?int $usedStep;

    private function __construct(SecondFactor $kind, ?string $secret, ?int $usedStep)
    {
        $this->kind = $kind;
        $this->secret = $secret;
        $this->usedStep = $usedStep;
    }

    public static function none(): self
    {
        return new self(SecondFactor::None, null, null);
    }

    public static function app(string $secret): self
    {
        return new self(SecondFactor::App, $secret, null);
    }

    public static function email(): self
    {
        return new self(SecondFactor::Email, null, null);
    }

    public function kind(): SecondFactor
    {
        return $this->kind;
    }

    public function secret(): ?string
    {
        return $this->secret;
    }

    public function usedStep(): ?int
    {
        return $this->usedStep;
    }

    /**
     * Prueft einen Code der App und verbraucht sein Zeitfenster.
     *
     * Gibt den neuen Zustand zurueck oder null, wenn der Code nicht passt —
     * auch dann, wenn er zwar richtig ist, sein Fenster aber schon einmal
     * benutzt wurde.
     */
    public function accepting(string $code, int $timestamp): ?self
    {
        if (SecondFactor::App !== $this->kind || null === $this->secret) {
            return null;
        }

        $step = Totp::matchingStep($this->secret, $code, $timestamp);

        if (null === $step || (null !== $this->usedStep && $step <= $this->usedStep)) {
            return null;
        }

        return new self($this->kind, $this->secret, $step);
    }
}
