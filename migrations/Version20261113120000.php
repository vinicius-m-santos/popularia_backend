<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Título opcional nas publicações: com ele, o cartão abre em manchete.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post ADD title VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post DROP title');
    }
}
