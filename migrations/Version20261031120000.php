<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aumenta de 15 para 30 segundos o limite de vídeo em publicações';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_media DROP CONSTRAINT chk_post_media_size');
        $this->addSql("ALTER TABLE post_media ADD CONSTRAINT chk_post_media_size CHECK ((kind = 'image' AND byte_size BETWEEN 1 AND 10485760 AND duration_ms IS NULL) OR (kind = 'video' AND byte_size BETWEEN 1 AND 104857600 AND duration_ms BETWEEN 1 AND 30000))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_media DROP CONSTRAINT chk_post_media_size');
        $this->addSql("ALTER TABLE post_media ADD CONSTRAINT chk_post_media_size CHECK ((kind = 'image' AND byte_size BETWEEN 1 AND 10485760 AND duration_ms IS NULL) OR (kind = 'video' AND byte_size BETWEEN 1 AND 104857600 AND duration_ms BETWEEN 1 AND 15000))");
    }
}
