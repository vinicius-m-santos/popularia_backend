<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalized hashtags and post associations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE hashtag (id UUID NOT NULL, normalized_name VARCHAR(50) NOT NULL, display_name VARCHAR(50) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_hashtag_name ON hashtag (normalized_name)');
        $this->addSql('CREATE TABLE post_hashtag (post_id UUID NOT NULL, hashtag_id UUID NOT NULL, PRIMARY KEY (post_id, hashtag_id))');
        $this->addSql('CREATE INDEX idx_post_hashtag_hashtag_post ON post_hashtag (hashtag_id, post_id)');
        $this->addSql('ALTER TABLE post_hashtag ADD CONSTRAINT fk_post_hashtag_post FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE post_hashtag ADD CONSTRAINT fk_post_hashtag_hashtag FOREIGN KEY (hashtag_id) REFERENCES hashtag (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE post_hashtag');
        $this->addSql('DROP TABLE hashtag');
    }
}
