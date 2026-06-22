<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Renomme l'action d'audit EXPORT en CONSOLIDATION (UC06).
 */
final class Version20260618140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Renomme l\'action d\'audit EXPORT en CONSOLIDATION dans audit_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE audit_log SET action = 'CONSOLIDATION' WHERE action = 'EXPORT'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE audit_log SET action = 'EXPORT' WHERE action = 'CONSOLIDATION'");
    }
}
