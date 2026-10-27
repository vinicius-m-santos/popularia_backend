<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cria tabela waitlist_entry para a lista de espera da landing page.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE waitlist_entry (
                id UUID NOT NULL,
                email VARCHAR(320) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL,
                source VARCHAR(40) DEFAULT NULL,
                PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_waitlist_email ON waitlist_entry (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE waitlist_entry');
    }
}
