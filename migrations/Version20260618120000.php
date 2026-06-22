<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute le statut CONSOLIDE au workflow de saisie (UC06).
 */
final class Version20260618120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Autorise le statut CONSOLIDE sur la table saisie et met à jour les lignes déjà consolidées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE saisie DROP CONSTRAINT IF EXISTS saisie_status_check');
        $this->addSql("ALTER TABLE saisie ADD CONSTRAINT saisie_status_check CHECK (status IN ('SAISIE','CONTRE_SAISIE','CONSOLIDE'))");
        $this->addSql("UPDATE saisie SET status = 'CONSOLIDE' WHERE flag_consolide = true AND status <> 'CONSOLIDE'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE saisie SET status = 'CONTRE_SAISIE' WHERE status = 'CONSOLIDE'");
        $this->addSql('ALTER TABLE saisie DROP CONSTRAINT IF EXISTS saisie_status_check');
        $this->addSql("ALTER TABLE saisie ADD CONSTRAINT saisie_status_check CHECK (status IN ('SAISIE','CONTRE_SAISIE'))");
    }
}
