<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Tipo do chamado: entrada livre (sem linha), teto de vagas ou meta mínima de confirmações.
 */
final class Version20261114120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Capacidade do chamado: modo (vagas/meta) e limite de pessoas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comuna_event ADD capacity_mode VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE comuna_event ADD capacity INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comuna_event DROP capacity_mode');
        $this->addSql('ALTER TABLE comuna_event DROP capacity');
    }
}
