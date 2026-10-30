<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Denúncias V1: motivo estruturado, escopo, escalonamento, trecho e fila da plataforma.
 * A unicidade passa a valer só enquanto a denúncia está aberta.
 * Comuna suspensa pela plataforma é um estado próprio, diferente de arquivada.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Denúncias com escopo, motivo, escalonamento e suspensão de comuna pela plataforma';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report ALTER reason TYPE VARCHAR(800)');
        $this->addSql('ALTER TABLE content_report ADD reason_code VARCHAR(40) DEFAULT NULL');
        $this->addSql("ALTER TABLE content_report ADD scope VARCHAR(16) NOT NULL DEFAULT 'comuna'");
        $this->addSql('ALTER TABLE content_report ADD escalated BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE content_report ADD snapshot VARCHAR(280) DEFAULT NULL');
        $this->addSql('ALTER TABLE content_report ADD reviewer_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE content_report ADD claimed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql("UPDATE content_report SET scope = 'platform' WHERE comuna_id IS NULL");
        $this->addSql('ALTER TABLE content_report ADD CONSTRAINT fk_content_report_reviewer FOREIGN KEY (reviewer_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT chk_content_report_status');
        $this->addSql("ALTER TABLE content_report ADD CONSTRAINT chk_content_report_status CHECK (status IN ('pending', 'reviewing', 'resolved', 'dismissed', 'removed'))");
        $this->addSql("ALTER TABLE content_report ADD CONSTRAINT chk_content_report_reason CHECK (reason_code IS NULL OR reason_code IN ('harassment', 'hate', 'threat', 'spam', 'fraud', 'sexual', 'doxxing', 'illegal', 'other'))");
        $this->addSql("ALTER TABLE content_report ADD CONSTRAINT chk_content_report_scope CHECK (scope IN ('comuna', 'platform'))");
        $this->addSql("ALTER TABLE content_report ADD CONSTRAINT chk_content_report_scope_comuna CHECK (scope = 'platform' OR comuna_id IS NOT NULL)");
        $this->addSql('DROP INDEX uniq_content_report');
        $this->addSql("CREATE UNIQUE INDEX uniq_content_report_open ON content_report (reporter_id, target_type, target_id) WHERE status IN ('pending', 'reviewing')");
        $this->addSql("CREATE INDEX idx_content_report_platform ON content_report (status, id) WHERE (scope = 'platform' OR escalated = true)");
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT chk_comuna_status');
        $this->addSql("ALTER TABLE comuna ADD CONSTRAINT chk_comuna_status CHECK (status IN ('active', 'archived', 'suspended'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE comuna SET status = 'archived' WHERE status = 'suspended'");
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT chk_comuna_status');
        $this->addSql("ALTER TABLE comuna ADD CONSTRAINT chk_comuna_status CHECK (status IN ('active', 'archived'))");
        $this->addSql('DROP INDEX idx_content_report_platform');
        $this->addSql('DROP INDEX uniq_content_report_open');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_report ON content_report (reporter_id, target_type, target_id)');
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT chk_content_report_scope_comuna');
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT chk_content_report_scope');
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT chk_content_report_reason');
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT chk_content_report_status');
        $this->addSql("DELETE FROM content_report WHERE status IN ('reviewing', 'resolved')");
        $this->addSql("ALTER TABLE content_report ADD CONSTRAINT chk_content_report_status CHECK (status IN ('pending', 'dismissed', 'removed'))");
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT fk_content_report_reviewer');
        $this->addSql('ALTER TABLE content_report DROP claimed_at');
        $this->addSql('ALTER TABLE content_report DROP reviewer_id');
        $this->addSql('ALTER TABLE content_report DROP snapshot');
        $this->addSql('ALTER TABLE content_report DROP escalated');
        $this->addSql('ALTER TABLE content_report DROP scope');
        $this->addSql('ALTER TABLE content_report DROP reason_code');
        $this->addSql('ALTER TABLE content_report ALTER reason TYPE VARCHAR(500)');
    }
}
