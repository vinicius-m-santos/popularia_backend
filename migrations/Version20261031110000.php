<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona autoria opcional e indexada aos documentos da biblioteca.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE library_document ADD author VARCHAR(200) DEFAULT NULL');
        $this->addSql("CREATE INDEX idx_library_search_author ON library_document USING gin (popularia_search_text(COALESCE(author, '')) gin_trgm_ops) WHERE status = 'published'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_library_search_author');
        $this->addSql('ALTER TABLE library_document DROP author');
    }
}
