<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Permet user_id NULL dans audit_log pour les connexions refusées (identifiant inconnu).
 */
final class Version20260619160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rend user_id nullable dans audit_log pour les tentatives de connexion refusées';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN user_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM audit_log WHERE user_id IS NULL');
        $this->addSql('ALTER TABLE audit_log ALTER COLUMN user_id SET NOT NULL');
    }
}
