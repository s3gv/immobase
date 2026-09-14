<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Plugin\Domain;

use App\Shared\Identity\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ein aktiviertes Plugin.
 *
 * **Haelt eine Momentaufnahme seines Manifests.** Nicht, weil die Datei
 * verschwinden koennte, sondern weil zwei Dinge daran haengen: die Navigation
 * liest bei jedem Seitenaufbau, und eine Anwendung, die dafuer Verzeichnisse
 * abtastet und JSON liest, wird mit jedem Plugin langsamer. Und was jemand
 * bestaetigt hat, steht damit fest — aendert die Datei sich, wird erneut
 * gefragt, statt dass die Zustimmung stillschweigend mitwaechst.
 *
 * **Ein eigener Prozess auf einem eigenen Port.** Das Plugin laeuft im
 * Anwendungscontainer, aber nicht in der Anwendung: der Core startet es als
 * eigenen PHP-Prozess, spricht per HTTP mit ihm und laedt keine Zeile davon.
 * Der Port wird beim Aktivieren vergeben und bleibt.
 *
 * Das Token liegt nur als Hash da. Es entsteht bei jedem Start des Prozesses
 * neu und geht ihm ueber seine Umgebung zu — niemand muss es abschreiben.
 */
#[ORM\Entity]
#[ORM\Table(name: 'plugin_installation')]
#[ORM\UniqueConstraint(name: 'plugin_installation_name', columns: ['name'])]
#[ORM\UniqueConstraint(name: 'plugin_installation_port', columns: ['port'])]
class Plugin
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $version;

    /** Das bestaetigte Manifest, wortgleich wie beim Aktivieren. */
    #[ORM\Column(type: Types::TEXT)]
    private string $manifest;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: PluginState::class)]
    private PluginState $state = PluginState::Active;

    #[ORM\Column(name: 'token_hash', type: Types::STRING, length: 128)]
    private string $tokenHash;

    /** Das Passwort der eigenen Datenbankrolle. */
    #[ORM\Column(name: 'storage_password', type: Types::STRING, length: 128)]
    private string $storagePassword;

    #[ORM\Column(name: 'activated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $activatedAt;

    /** Wer vertraut hat — im Klartext, damit es die Zeile ueberdauert. */
    #[ORM\Column(name: 'activated_by', type: Types::STRING, length: 200)]
    private string $activatedBy;

    #[ORM\Column(type: Types::INTEGER)]
    private int $port;

    public function __construct(
        string $name,
        string $version,
        string $manifest,
        string $tokenHash,
        string $storagePassword,
        DateTimeImmutable $activatedAt,
        string $activatedBy,
        int $port,
    ) {
        $this->id = Uuid::v4();
        $this->name = $name;
        $this->version = $version;
        $this->manifest = $manifest;
        $this->tokenHash = $tokenHash;
        $this->storagePassword = $storagePassword;
        $this->activatedAt = $activatedAt;
        $this->activatedBy = $activatedBy;
        $this->port = $port;
    }

    public function port(): int
    {
        return $this->port;
    }

    /**
     * Wo der Prozess lauscht — nur im Container erreichbar.
     *
     * An 127.0.0.1 gebunden und nicht an alle Schnittstellen: sonst kaeme
     * ein anderer Container im selben Netz an die Seiten des Plugins vorbei
     * an der Rechtepruefung des Cores.
     */
    public function address(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function manifest(): string
    {
        return $this->manifest;
    }

    public function state(): PluginState
    {
        return $this->state;
    }

    public function isActive(): bool
    {
        return PluginState::Active === $this->state;
    }

    public function tokenHash(): string
    {
        return $this->tokenHash;
    }

    public function storagePassword(): string
    {
        return $this->storagePassword;
    }

    public function activatedAt(): DateTimeImmutable
    {
        return $this->activatedAt;
    }

    public function activatedBy(): string
    {
        return $this->activatedBy;
    }

    public function displayName(): string
    {
        return $this->name;
    }

    /** Ein neues Token; das alte gilt damit nicht mehr. */
    public function reseal(string $tokenHash): void
    {
        $this->tokenHash = $tokenHash;
    }

    public function suspend(): void
    {
        $this->state = PluginState::Suspended;
    }

    public function resume(): void
    {
        $this->state = PluginState::Active;
    }

    /** Eine neue Fassung derselben Installation, erneut bestaetigt. */
    public function updateTo(string $version, string $manifest, DateTimeImmutable $at, string $by): void
    {
        $this->version = $version;
        $this->manifest = $manifest;
        $this->activatedAt = $at;
        $this->activatedBy = $by;
    }
}
