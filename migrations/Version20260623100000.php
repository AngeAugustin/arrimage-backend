<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index de performance sur saisie et audit_log (requêtes agents, stats, consolidation, audit).
 */
final class Version20260623100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute des index sur saisie (agents, dates, statut, export) et audit_log (timestamp, user, action)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_saisie_agent1_dt ON saisie (agent1_id, dt_saisie1)');
        $this->addSql('CREATE INDEX idx_saisie_agent2_dt ON saisie (agent2_id, dt_saisie2)');
        $this->addSql('CREATE INDEX idx_saisie_status ON saisie (status)');
        $this->addSql('CREATE INDEX idx_saisie_dt_saisie1 ON saisie (dt_saisie1)');
        $this->addSql('CREATE INDEX idx_saisie_dt_export ON saisie (dt_export)');

        $this->addSql('CREATE INDEX idx_audit_log_timestamp ON audit_log (timestamp)');
        $this->addSql('CREATE INDEX idx_audit_log_user_id ON audit_log (user_id)');
        $this->addSql('CREATE INDEX idx_audit_log_action ON audit_log (action)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_log_action');
        $this->addSql('DROP INDEX idx_audit_log_user_id');
        $this->addSql('DROP INDEX idx_audit_log_timestamp');

        $this->addSql('DROP INDEX idx_saisie_dt_export');
        $this->addSql('DROP INDEX idx_saisie_dt_saisie1');
        $this->addSql('DROP INDEX idx_saisie_status');
        $this->addSql('DROP INDEX idx_saisie_agent2_dt');
        $this->addSql('DROP INDEX idx_saisie_agent1_dt');
    }
}
