<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Identidades de provider social (Google, Apple) e flag de onboarding pendente.
 *
 * - user_auth_identity: vincula User a identity de provider externo pelo `sub` estável.
 * - app_user.pending_onboarding: usuários criados via social login precisam escolher handle/nome.
 */
final class Version20261028000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Social login: user_auth_identity e pending_onboarding em app_user';
    }

    public function up(Schema $schema): void
    {
        // Coluna pending_onboarding para contas sociais sem handle/nome definidos ainda.
        $this->addSql('ALTER TABLE app_user ADD pending_onboarding BOOLEAN DEFAULT FALSE NOT NULL');

        // Tabela de identidades de provider.
        $this->addSql('CREATE TABLE user_auth_identity (id UUID NOT NULL, provider VARCHAR(20) NOT NULL, provider_subject VARCHAR(255) NOT NULL, provider_email VARCHAR(320) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_auth_identity_provider_subject ON user_auth_identity (provider, provider_subject)');
        $this->addSql('CREATE INDEX idx_auth_identity_user ON user_auth_identity (user_id)');
        $this->addSql('CREATE INDEX IDX_AUTH_IDENTITY_USER_FK ON user_auth_identity (user_id)');
        $this->addSql('ALTER TABLE user_auth_identity ADD CONSTRAINT FK_USER_AUTH_IDENTITY_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_auth_identity DROP CONSTRAINT FK_USER_AUTH_IDENTITY_USER');
        $this->addSql('DROP INDEX uniq_auth_identity_provider_subject');
        $this->addSql('DROP INDEX idx_auth_identity_user');
        $this->addSql('DROP INDEX IDX_AUTH_IDENTITY_USER_FK');
        $this->addSql('DROP TABLE user_auth_identity');
        $this->addSql('ALTER TABLE app_user DROP pending_onboarding');
    }
}
