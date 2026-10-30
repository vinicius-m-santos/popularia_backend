<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adiciona localização declarada e códigos IBGE ao perfil.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE app_user ADD state VARCHAR(80) DEFAULT NULL, ADD country VARCHAR(80) DEFAULT NULL, ADD country_code VARCHAR(2) DEFAULT 'BR' NOT NULL, ADD state_ibge_code VARCHAR(3) DEFAULT NULL, ADD municipality_ibge_code VARCHAR(7) DEFAULT NULL");
        $this->addSql('UPDATE app_user SET city = NULL, state = NULL, country = CASE WHEN country = \'Brasil\' OR country IS NULL THEN \'Brasil\' ELSE country END');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP state, DROP country, DROP country_code, DROP state_ibge_code, DROP municipality_ibge_code');
    }
}
