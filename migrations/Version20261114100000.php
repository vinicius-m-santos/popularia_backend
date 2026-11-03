<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publicação com múltiplos assuntos oficiais; o primeiro continua em topic para a descoberta';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE post ADD topics JSONB DEFAULT '[]' NOT NULL");
        $this->addSql('UPDATE post SET topics = jsonb_build_array(topic) WHERE topic IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post DROP topics');
    }
}
