<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Private saved posts: one row per user and publication, newest save first.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE saved_post (id UUID PRIMARY KEY, user_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, post_id UUID NOT NULL REFERENCES post(id) ON DELETE CASCADE, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_saved_post_user_post ON saved_post (user_id, post_id)');
        $this->addSql('CREATE INDEX idx_saved_post_user ON saved_post (user_id, id)');
        $this->addSql('CREATE INDEX idx_saved_post_post ON saved_post (post_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE saved_post');
    }
}
