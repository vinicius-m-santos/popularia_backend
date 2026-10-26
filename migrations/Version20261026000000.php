<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrega A/B: contas, sessões, códigos por e-mail, registro de segurança, preservação de
 * evidências, auditoria administrativa e a tabela de estado dos limites de taxa.
 */
final class Version20261026000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Contas, sessões, registros de segurança e auditoria';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account_token (id UUID NOT NULL, purpose VARCHAR(30) NOT NULL, code_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, failed_attempts INT DEFAULT 0 NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_account_token_user_purpose ON account_token (user_id, purpose)');
        $this->addSql('CREATE INDEX IDX_69B8784AA76ED395 ON account_token (user_id)');
        $this->addSql('CREATE TABLE admin_audit_log (id UUID NOT NULL, actor_label VARCHAR(40) NOT NULL, action VARCHAR(64) NOT NULL, target_user_id UUID DEFAULT NULL, reason TEXT NOT NULL, details JSONB DEFAULT \'{}\' NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, actor_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_admin_audit_log_target ON admin_audit_log (target_user_id, occurred_at)');
        $this->addSql('CREATE INDEX IDX_1F16C5C710DAF24A ON admin_audit_log (actor_id)');
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, email VARCHAR(180) NOT NULL, email_verified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, password VARCHAR(255) NOT NULL, handle VARCHAR(20) NOT NULL, display_name VARCHAR(50) NOT NULL, bio VARCHAR(160) DEFAULT \'\' NOT NULL, city VARCHAR(80) DEFAULT NULL, interests JSONB DEFAULT \'[]\' NOT NULL, status VARCHAR(20) DEFAULT \'active\' NOT NULL, platform_role VARCHAR(30) DEFAULT \'user\' NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_email ON app_user (email)');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_handle ON app_user (handle)');
        $this->addSql('CREATE TABLE auth_session (id UUID NOT NULL, device_label VARCHAR(60) DEFAULT NULL, access_token_hash VARCHAR(64) NOT NULL, access_token_expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, revoked_reason VARCHAR(40) DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_auth_session_user ON auth_session (user_id, revoked_at)');
        $this->addSql('CREATE UNIQUE INDEX uniq_auth_session_access_token ON auth_session (access_token_hash)');
        $this->addSql('CREATE INDEX IDX_9E60F527A76ED395 ON auth_session (user_id)');
        $this->addSql('CREATE TABLE evidence_hold (id UUID NOT NULL, reason TEXT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, released_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, release_reason TEXT DEFAULT NULL, subject_id UUID NOT NULL, created_by_id UUID NOT NULL, released_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_evidence_hold_subject ON evidence_hold (subject_id, released_at)');
        $this->addSql('CREATE INDEX IDX_9175339E23EDC87 ON evidence_hold (subject_id)');
        $this->addSql('CREATE INDEX IDX_9175339EB03A8386 ON evidence_hold (created_by_id)');
        $this->addSql('CREATE INDEX IDX_9175339E445947DD ON evidence_hold (released_by_id)');
        $this->addSql('CREATE TABLE refresh_token (id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, rotated_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, session_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_refresh_token_session ON refresh_token (session_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_refresh_token_hash ON refresh_token (token_hash)');
        $this->addSql('CREATE TABLE security_event (id UUID NOT NULL, type VARCHAR(50) NOT NULL, session_id UUID DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, context JSONB DEFAULT \'{}\' NOT NULL, occurred_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, user_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_security_event_user ON security_event (user_id, occurred_at)');
        $this->addSql('CREATE INDEX idx_security_event_occurred ON security_event (occurred_at)');
        $this->addSql('CREATE INDEX IDX_D712E90DA76ED395 ON security_event (user_id)');
        $this->addSql('CREATE TABLE cache_items (item_id VARCHAR(255) NOT NULL, item_data BYTEA NOT NULL, item_lifetime INT DEFAULT NULL, item_time INT NOT NULL, PRIMARY KEY (item_id))');
        $this->addSql('ALTER TABLE account_token ADD CONSTRAINT FK_69B8784AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_1F16C5C710DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE auth_session ADD CONSTRAINT FK_9E60F527A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evidence_hold ADD CONSTRAINT FK_9175339E23EDC87 FOREIGN KEY (subject_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evidence_hold ADD CONSTRAINT FK_9175339EB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE evidence_hold ADD CONSTRAINT FK_9175339E445947DD FOREIGN KEY (released_by_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE refresh_token ADD CONSTRAINT FK_C74F2195613FECDF FOREIGN KEY (session_id) REFERENCES auth_session (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE security_event ADD CONSTRAINT FK_D712E90DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        // A auditoria administrativa só recebe inserções: o banco recusa alterar ou apagar linhas.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION admin_audit_log_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'admin_audit_log aceita apenas inserções';
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER admin_audit_log_no_change BEFORE UPDATE OR DELETE ON admin_audit_log FOR EACH ROW EXECUTE FUNCTION admin_audit_log_append_only()');
        $this->addSql('CREATE TRIGGER admin_audit_log_no_truncate BEFORE TRUNCATE ON admin_audit_log FOR EACH STATEMENT EXECUTE FUNCTION admin_audit_log_append_only()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER admin_audit_log_no_truncate ON admin_audit_log');
        $this->addSql('DROP TRIGGER admin_audit_log_no_change ON admin_audit_log');
        $this->addSql('DROP FUNCTION admin_audit_log_append_only()');
        $this->addSql('ALTER TABLE account_token DROP CONSTRAINT FK_69B8784AA76ED395');
        $this->addSql('ALTER TABLE admin_audit_log DROP CONSTRAINT FK_1F16C5C710DAF24A');
        $this->addSql('ALTER TABLE auth_session DROP CONSTRAINT FK_9E60F527A76ED395');
        $this->addSql('ALTER TABLE evidence_hold DROP CONSTRAINT FK_9175339E23EDC87');
        $this->addSql('ALTER TABLE evidence_hold DROP CONSTRAINT FK_9175339EB03A8386');
        $this->addSql('ALTER TABLE evidence_hold DROP CONSTRAINT FK_9175339E445947DD');
        $this->addSql('ALTER TABLE refresh_token DROP CONSTRAINT FK_C74F2195613FECDF');
        $this->addSql('ALTER TABLE security_event DROP CONSTRAINT FK_D712E90DA76ED395');
        $this->addSql('DROP TABLE account_token');
        $this->addSql('DROP TABLE admin_audit_log');
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE auth_session');
        $this->addSql('DROP TABLE evidence_hold');
        $this->addSql('DROP TABLE refresh_token');
        $this->addSql('DROP TABLE security_event');
        $this->addSql('DROP TABLE cache_items');
    }
}
