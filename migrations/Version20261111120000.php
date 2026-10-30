<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marca (imagem de perfil) da comuna, com uma key nova a cada troca';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comuna ADD mark_key VARCHAR(80) DEFAULT NULL');
        $this->addSql("ALTER TABLE comuna ADD CONSTRAINT chk_comuna_mark_key CHECK (mark_key IS NULL OR mark_key ~ '^comuna-marks/[0-9a-fA-F-]{36}\\.(jpg|png|webp)$')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT chk_comuna_mark_key');
        $this->addSql('ALTER TABLE comuna DROP mark_key');
    }
}
