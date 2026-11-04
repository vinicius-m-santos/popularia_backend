<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Cadastro social pendente e versões separadas dos documentos legais. */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cadastro social pendente com token/expiração e versões de termos, regras e privacidade';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD legal_terms_version VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD legal_rules_version VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE app_user ADD legal_privacy_version VARCHAR(30) DEFAULT NULL');
        $this->addSql('CREATE TABLE pending_social_signup (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, provider VARCHAR(20) NOT NULL, provider_subject VARCHAR(255) NOT NULL, provider_email VARCHAR(320) DEFAULT NULL, display_name VARCHAR(100) DEFAULT NULL, email_verified BOOLEAN NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_pending_social_token_hash ON pending_social_signup (token_hash)');
        $this->addSql('CREATE UNIQUE INDEX uniq_pending_social_provider_subject ON pending_social_signup (provider, provider_subject)');
        $this->addSql('CREATE INDEX idx_pending_social_expires_at ON pending_social_signup (expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pending_social_signup');
        $this->addSql('ALTER TABLE app_user DROP legal_terms_version');
        $this->addSql('ALTER TABLE app_user DROP legal_rules_version');
        $this->addSql('ALTER TABLE app_user DROP legal_privacy_version');
    }
}
