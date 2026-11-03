<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chamados no perfil: evento sem comuna, ligado à publicação do próprio autor';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comuna_event ALTER comuna_id DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM post WHERE event_id IN (SELECT id FROM comuna_event WHERE comuna_id IS NULL)');
        $this->addSql('DELETE FROM comuna_event WHERE comuna_id IS NULL');
        $this->addSql('ALTER TABLE comuna_event ALTER comuna_id SET NOT NULL');
    }
}
