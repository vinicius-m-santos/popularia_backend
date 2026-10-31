<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prazo opcional na enquete: sem prazo, ela continua aberta.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poll ADD ends_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE poll DROP ends_at');
    }
}
