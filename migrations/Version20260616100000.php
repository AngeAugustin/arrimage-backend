<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration initiale — schéma Arrimage IFU (utilisateur, employeur, saisie, audit_log).
 */
final class Version20260616100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création des tables utilisateur, employeur, saisie et audit_log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE utilisateur (
            id SERIAL PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            nom VARCHAR(100) NOT NULL,
            prenom VARCHAR(100) NOT NULL,
            role VARCHAR(20) NOT NULL CHECK (role IN (\'agent1\',\'agent2\',\'controleur\',\'admin\')),
            is_active BOOLEAN NOT NULL DEFAULT true,
            is_first_connexion BOOLEAN NOT NULL DEFAULT true,
            dt_creation TIMESTAMP NOT NULL DEFAULT NOW(),
            dt_modification TIMESTAMP DEFAULT NULL,
            dt_last_login TIMESTAMP DEFAULT NULL,
            nbre_tentatives_connexion SMALLINT NOT NULL DEFAULT 0,
            duree_verrouillage TIMESTAMP DEFAULT NULL
        )');

        $this->addSql('CREATE TABLE employeur (
            num_cnss VARCHAR(20) PRIMARY KEY,
            raison_sociale VARCHAR(255) NOT NULL
        )');

        $this->addSql('CREATE TABLE saisie (
            id SERIAL PRIMARY KEY,
            num_cnss VARCHAR(20) NOT NULL UNIQUE REFERENCES employeur(num_cnss),
            ifu_agent1 VARCHAR(13) NOT NULL,
            agent1_id INTEGER NOT NULL REFERENCES utilisateur(id),
            dt_saisie1 TIMESTAMP NOT NULL DEFAULT NOW(),
            ifu_agent2 VARCHAR(13) DEFAULT NULL,
            agent2_id INTEGER DEFAULT NULL REFERENCES utilisateur(id),
            dt_saisie2 TIMESTAMP DEFAULT NULL,
            dt_modif TIMESTAMP DEFAULT NULL,
            flag_consolide BOOLEAN NOT NULL DEFAULT false,
            dt_export TIMESTAMP DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT \'SAISIE\' CHECK (status IN (\'SAISIE\',\'CONTRE_SAISIE\',\'CONSOLIDE\'))
        )');

        $this->addSql('CREATE INDEX idx_saisie_flag ON saisie(flag_consolide)');
        $this->addSql('CREATE INDEX idx_saisie_num_cnss ON saisie(num_cnss)');

        $this->addSql('CREATE TABLE audit_log (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES utilisateur(id),
            action VARCHAR(50) NOT NULL,
            entite_cible VARCHAR(50) DEFAULT NULL,
            valeur_avant TEXT DEFAULT NULL,
            valeur_apres TEXT DEFAULT NULL,
            timestamp TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            ip_address VARCHAR(45) DEFAULT NULL
        )');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audit_log');
        $this->addSql('DROP TABLE saisie');
        $this->addSql('DROP TABLE employeur');
        $this->addSql('DROP TABLE utilisateur');
    }
}
