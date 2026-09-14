<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace App\Module\Tenancy\Application;

use App\Module\Property\Contract\UnitDirectory;
use App\Module\Tenancy\Domain\Deposit;
use App\Module\Tenancy\Domain\Payment;
use App\Module\Tenancy\Domain\Taxation;
use App\Module\Tenancy\Domain\TaxOnlyForCommercial;
use App\Module\Tenancy\Domain\Tenancy;
use App\Module\Tenancy\Domain\TenancyNeedsAnEnd;
use App\Module\Tenancy\Domain\TenancyNeedsATenant;
use App\Module\Tenancy\Domain\TenancyRepository;
use App\Module\Tenancy\Domain\Term;
use App\Module\Tenancy\Domain\UnitAlreadyLet;
use App\Module\Tenancy\Domain\UnitLetInThatPeriod;
use App\Module\Tenancy\Domain\UnknownUnit;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Ein Mietverhaeltnis anlegen und aendern.
 *
 * Jeder Schritt speichert sofort — wie beim Objekt und aus demselben Grund:
 * ein Mietvertrag wird abgeschrieben, nicht auswendig eingegeben, und wer
 * mitten drin einen Mieter in den Stammdaten anlegen muss, soll nichts
 * verlieren.
 *
 * Die Einheit wird nachgeschlagen, bevor sie uebernommen wird. Ohne das
 * liesse sich ueber ein nachgebautes Formular jede beliebige Kennung
 * eintragen; die Zeile waere danach nicht einmal mehr anzeigbar. Der
 * Fremdschluessel in der Datenbank ist die letzte Grenze, diese Pruefung die
 * verstaendliche.
 */
final readonly class SaveTenancy
{
    /** Postgres meldet eine verletzte Ausschlussbedingung mit diesem Zustand. */
    private const string EXCLUSION_VIOLATION = '23P01';

    public function __construct(
        private TenancyRepository $tenancies,
        private UnitDirectory $units,
        private KeepUnitsFree $free,
    ) {
    }

    /**
     * Ein neues Mietverhaeltnis — als Entwurf, auch fuer eine vermietete
     * Einheit.
     *
     * Ob sie frei ist, entscheidet sich beim Aktivieren. Hier zu pruefen
     * hiesse, den Nachmieter erst erfassen zu duerfen, wenn der Vormieter
     * schon ausgezogen ist.
     *
     * @throws UnknownUnit
     */
    public function forUnit(string $unitId): Tenancy
    {
        $this->refuseUnknownUnit($unitId);

        $tenancy = new Tenancy($this->tenancies->nextNumber(), $unitId);
        $this->saved($tenancy);

        return $tenancy;
    }

    /**
     * Umziehen — beim laufenden Mietverhaeltnis nur auf eine freie Einheit.
     *
     * Ein Entwurf darf auf eine vermietete zeigen; er blockiert nichts, und
     * geprueft wird beim Aktivieren.
     *
     * @throws UnknownUnit
     * @throws UnitAlreadyLet
     */
    public function moveTo(Tenancy $tenancy, string $unitId): void
    {
        if ($tenancy->unitId() !== $unitId) {
            $this->refuseUnknownUnit($unitId);

            if ($tenancy->status()->isActive()) {
                $this->free->refuseIfLet($unitId, $tenancy->id());
            }

            $tenancy->moveTo($unitId);
        }

        $this->tenancies->save($tenancy);
    }

    public function noteThat(Tenancy $tenancy, string $note): void
    {
        $tenancy->noteThat($note);
        $this->tenancies->save($tenancy);
    }

    public function runFor(Tenancy $tenancy, Term $term): void
    {
        $tenancy->runFor($term);
        $this->tenancies->save($tenancy);
    }

    /**
     * Die Umsatzsteuervereinbarung — nur bei gewerblicher Nutzung.
     *
     * Geprueft wird hier, weil hier die Einheit bekannt ist. Ein
     * ausgeblendetes Feld schuetzt vor dem Formular und nicht vor der
     * Anwendung, und was durchginge, stuende spaeter auf einer Rechnung.
     *
     * @throws TaxOnlyForCommercial
     */
    public function taxAs(Tenancy $tenancy, Taxation $taxation): void
    {
        if ($taxation->isCharged() && !$this->isCommercial($tenancy->unitId())) {
            throw TaxOnlyForCommercial::of();
        }

        $tenancy->taxAs($taxation);
        $this->tenancies->save($tenancy);
    }

    public function paidBy(Tenancy $tenancy, Payment $payment): void
    {
        $tenancy->paidBy($payment);
        $this->tenancies->save($tenancy);
    }

    public function secureWith(Tenancy $tenancy, Deposit $deposit): void
    {
        $tenancy->secureWith($deposit);
        $this->tenancies->save($tenancy);
    }

    /**
     * Abschliessen: ab jetzt ist die Einheit vermietet.
     *
     * @throws UnitAlreadyLet
     * @throws UnitLetInThatPeriod
     * @throws TenancyNeedsATenant
     */
    public function complete(Tenancy $tenancy): void
    {
        $this->free->beforeLetting($tenancy);

        $tenancy->activate();
        $this->saved($tenancy);
    }

    /**
     * Beenden — mit dem Tag, an dem es zu Ende war.
     *
     * @throws TenancyNeedsAnEnd
     */
    public function end(Tenancy $tenancy, ?DateTimeImmutable $endsOn): void
    {
        $tenancy->endOn($endsOn);
        $this->tenancies->save($tenancy);
    }

    /**
     * Eine versehentliche Beendigung zuruecknehmen.
     *
     * Geprueft wird wie beim Abschliessen: in der Zwischenzeit kann die
     * Einheit weitervermietet worden sein, und dann steht das hier nicht mehr
     * zur Wahl.
     *
     * Das eingetragene Mietende bleibt stehen; die Oberflaeche weist darauf
     * hin, dass die Laufzeit zu pruefen ist.
     *
     * @throws UnitAlreadyLet
     * @throws UnitLetInThatPeriod
     * @throws TenancyNeedsATenant
     */
    public function reopen(Tenancy $tenancy): void
    {
        $this->free->beforeLetting($tenancy);

        $tenancy->activate();
        $this->saved($tenancy);
    }

    private function isCommercial(string $unitId): bool
    {
        return 'commercial' === ($this->units->byIds([$unitId])[$unitId] ?? null)?->usage;
    }

    /**
     * Speichern — und einen verlorenen Wettlauf fachlich uebersetzen.
     *
     * Zwei Regeln stehen in der Datenbank, und beide koennen erst dort
     * auffallen: der partielle eindeutige Index laesst je Einheit nur ein
     * aktives Mietverhaeltnis zu, die Ausschlussbedingung keine zwei mit
     * ueberlappender Laufzeit. Gewinnt zwischen Pruefung und Speichern eine
     * andere Anfrage, meldet es die Datenbank — roh weitergereicht waere das
     * ein 500er statt der Absage, die es fachlich ist.
     *
     * @throws UnitAlreadyLet
     * @throws UnitLetInThatPeriod
     */
    private function saved(Tenancy $tenancy): void
    {
        try {
            $this->tenancies->save($tenancy);
        } catch (UniqueConstraintViolationException) {
            throw UnitAlreadyLet::inTheMeantime();
        } catch (DriverException $problem) {
            if (self::EXCLUSION_VIOLATION !== $problem->getSQLState()) {
                throw $problem;
            }

            throw UnitLetInThatPeriod::inTheMeantime();
        }
    }

    private function refuseUnknownUnit(string $unitId): void
    {
        if ([] === $this->units->byIds([$unitId])) {
            throw UnknownUnit::of($unitId);
        }
    }
}
