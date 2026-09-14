<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// SPDX-FileCopyrightText: 2026 s3gv

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Was eine E-Rechnung ueber das hinaus braucht, was eine Rechnung auf Papier hat.
 *
 * Am Mietverhaeltnis: die Referenz, unter der der Mieter die Rechnung
 * zuordnet (BT-10), und die Adresse, an die sie elektronisch geht (BT-49).
 * Zahlt er per Lastschrift, dazu Mandatsreferenz (BT-89) und seine IBAN
 * (BT-91). Alles kommt vom Mieter und wird bei ihm erfragt.
 *
 * Am Konto des Objekts: die Glaeubiger-ID (BT-90), ohne die kein Einzug
 * zuzuordnen ist.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'E-Rechnungs-Angaben am Mietverhältnis, Gläubiger-ID am Objektkonto.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE tenancy ADD buyer_reference VARCHAR(100) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE tenancy ADD buyer_e_address VARCHAR(200) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE tenancy ADD sepa_mandate VARCHAR(35) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE tenancy ADD debtor_iban VARCHAR(34) DEFAULT '' NOT NULL");
        $this->addSql("ALTER TABLE property ADD bank_creditor_id VARCHAR(35) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property DROP bank_creditor_id');
        $this->addSql('ALTER TABLE tenancy DROP debtor_iban');
        $this->addSql('ALTER TABLE tenancy DROP sepa_mandate');
        $this->addSql('ALTER TABLE tenancy DROP buyer_e_address');
        $this->addSql('ALTER TABLE tenancy DROP buyer_reference');
    }
}
