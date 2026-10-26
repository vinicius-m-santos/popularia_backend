<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persistent internal notifications and user follows.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_follow (id UUID NOT NULL, follower_id UUID NOT NULL, followed_id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_follow ON user_follow (follower_id, followed_id)');
        $this->addSql('CREATE INDEX IDX_USER_FOLLOW_FOLLOWED ON user_follow (followed_id)');
        $this->addSql('ALTER TABLE user_follow ADD CONSTRAINT fk_user_follow_follower FOREIGN KEY (follower_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_follow ADD CONSTRAINT fk_user_follow_followed FOREIGN KEY (followed_id) REFERENCES app_user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_follow ADD CONSTRAINT chk_user_follow_distinct CHECK (follower_id <> followed_id)');
        $this->addSql('CREATE TABLE notification (id UUID NOT NULL, recipient_id UUID NOT NULL, actor_id UUID DEFAULT NULL, post_id UUID DEFAULT NULL, comment_id UUID DEFAULT NULL, comuna_id UUID DEFAULT NULL, join_request_id UUID DEFAULT NULL, transfer_id UUID DEFAULT NULL, type VARCHAR(32) NOT NULL, event_key VARCHAR(80) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_event ON notification (recipient_id, type, event_key)');
        $this->addSql('CREATE INDEX idx_notification_recipient_id ON notification (recipient_id, id)');
        $this->addSql('CREATE INDEX idx_notification_recipient_read ON notification (recipient_id, read_at)');
        foreach (['recipient' => 'app_user', 'actor' => 'app_user', 'post' => 'post', 'comment' => 'comment', 'comuna' => 'comuna', 'join_request' => 'comuna_join_request', 'transfer' => 'comuna_ownership_transfer'] as $column => $table) {
            if ('recipient' !== $column) {
                $this->addSql(sprintf('CREATE INDEX idx_notification_%s ON notification (%s_id)', $column, $column));
            }
            $this->addSql(sprintf('ALTER TABLE notification ADD CONSTRAINT fk_notification_%s FOREIGN KEY (%s_id) REFERENCES %s (id) ON DELETE CASCADE', $column, $column, $table));
        }
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT chk_notification_not_self CHECK (actor_id IS NULL OR actor_id <> recipient_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE user_follow');
    }
}
