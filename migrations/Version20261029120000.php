<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Imagem ou vídeo curto, privado e idempotente, em publicações';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE post_media (id UUID NOT NULL, uploaded_by_id UUID NOT NULL, client_id VARCHAR(64) NOT NULL, storage_key VARCHAR(255) NOT NULL, kind VARCHAR(10) NOT NULL, mime_type VARCHAR(64) NOT NULL, checksum VARCHAR(64) NOT NULL, byte_size INT NOT NULL, width INT NOT NULL, height INT NOT NULL, duration_ms INT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id), CONSTRAINT chk_post_media_kind CHECK (kind IN ('image', 'video')), CONSTRAINT chk_post_media_status CHECK (status IN ('pending', 'ready', 'failed', 'purge_pending', 'purged')), CONSTRAINT chk_post_media_checksum CHECK (checksum ~ '^[a-f0-9]{64}$'), CONSTRAINT chk_post_media_dimensions CHECK (width > 0 AND height > 0), CONSTRAINT chk_post_media_size CHECK ((kind = 'image' AND byte_size BETWEEN 1 AND 10485760 AND duration_ms IS NULL) OR (kind = 'video' AND byte_size BETWEEN 1 AND 104857600 AND duration_ms BETWEEN 1 AND 15000)))");
        $this->addSql('CREATE UNIQUE INDEX uniq_post_media_upload ON post_media (uploaded_by_id, client_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_post_media_storage_key ON post_media (storage_key)');
        $this->addSql('CREATE INDEX idx_post_media_cleanup ON post_media (status, created_at)');
        $this->addSql('ALTER TABLE post_media ADD CONSTRAINT FK_POST_MEDIA_USER FOREIGN KEY (uploaded_by_id) REFERENCES app_user (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE post ADD media_id UUID DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_post_media_attachment ON post (media_id)');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_POST_MEDIA FOREIGN KEY (media_id) REFERENCES post_media (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT chk_post_single_attachment CHECK (library_document_id IS NULL OR media_id IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post DROP CONSTRAINT chk_post_single_attachment');
        $this->addSql('ALTER TABLE post DROP CONSTRAINT FK_POST_MEDIA');
        $this->addSql('DROP INDEX uniq_post_media_attachment');
        $this->addSql('ALTER TABLE post DROP media_id');
        $this->addSql('DROP TABLE post_media');
    }
}
