<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Structured user mentions on posts and comments, keyed by account id.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE content_mention (id UUID NOT NULL, post_id UUID DEFAULT NULL, comment_id UUID DEFAULT NULL, mentioned_user_id UUID NOT NULL, start_offset INT NOT NULL, end_offset INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_content_mention_user ON content_mention (mentioned_user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_mention_post_start ON content_mention (post_id, start_offset)');
        $this->addSql('CREATE UNIQUE INDEX uniq_content_mention_comment_start ON content_mention (comment_id, start_offset)');
        $this->addSql('ALTER TABLE content_mention ADD CONSTRAINT FK_content_mention_post FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE content_mention ADD CONSTRAINT FK_content_mention_comment FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE content_mention ADD CONSTRAINT FK_content_mention_user FOREIGN KEY (mentioned_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE content_mention ADD CONSTRAINT chk_content_mention_target CHECK ((post_id IS NULL) <> (comment_id IS NULL))');
        $this->addSql('ALTER TABLE content_mention ADD CONSTRAINT chk_content_mention_range CHECK (start_offset >= 0 AND end_offset > start_offset)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_mention DROP CONSTRAINT chk_content_mention_range');
        $this->addSql('ALTER TABLE content_mention DROP CONSTRAINT chk_content_mention_target');
        $this->addSql('ALTER TABLE content_mention DROP CONSTRAINT FK_content_mention_user');
        $this->addSql('ALTER TABLE content_mention DROP CONSTRAINT FK_content_mention_comment');
        $this->addSql('ALTER TABLE content_mention DROP CONSTRAINT FK_content_mention_post');
        $this->addSql('DROP TABLE content_mention');
    }
}
