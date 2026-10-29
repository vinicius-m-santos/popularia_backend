<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Foto de perfil e capa de comuna, cada uma com uma key nova a cada troca';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD avatar_key VARCHAR(80) DEFAULT NULL');
        $this->addSql("ALTER TABLE app_user ADD CONSTRAINT chk_user_avatar_key CHECK (avatar_key IS NULL OR avatar_key ~ '^avatars/[0-9a-fA-F-]{36}\\.(jpg|png|webp)$')");
        $this->addSql('ALTER TABLE comuna ADD cover_key VARCHAR(80) DEFAULT NULL');
        $this->addSql("ALTER TABLE comuna ADD CONSTRAINT chk_comuna_cover_key CHECK (cover_key IS NULL OR cover_key ~ '^comuna-covers/[0-9a-fA-F-]{36}\\.(jpg|png|webp)$')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP CONSTRAINT chk_user_avatar_key');
        $this->addSql('ALTER TABLE app_user DROP avatar_key');
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT chk_comuna_cover_key');
        $this->addSql('ALTER TABLE comuna DROP cover_key');
    }
}
