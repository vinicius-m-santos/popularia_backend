<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Session-bound push devices, category preferences and durable delivery outbox.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification ADD push_queued_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Existing history must never be pushed when enabling the worker.
        $this->addSql('UPDATE notification SET push_queued_at = CURRENT_TIMESTAMP');
        $this->addSql('CREATE INDEX idx_notification_push_pending ON notification (id) WHERE push_queued_at IS NULL');
        $this->addSql('CREATE TABLE push_device (id UUID NOT NULL PRIMARY KEY, user_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, session_id UUID NOT NULL REFERENCES auth_session(id) ON DELETE CASCADE, token VARCHAR(255) NOT NULL, revocation_hash VARCHAR(64) NOT NULL, platform VARCHAR(10) NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, registered_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, disabled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_push_device_token ON push_device (token)');
        $this->addSql('CREATE INDEX idx_push_device_user ON push_device (user_id)');
        $this->addSql('CREATE INDEX idx_push_device_session ON push_device (session_id)');
        $this->addSql('CREATE TABLE notification_preference (user_id UUID NOT NULL PRIMARY KEY REFERENCES app_user(id) ON DELETE CASCADE, push_categories JSON NOT NULL)');
        $this->addSql('CREATE TABLE push_delivery (id UUID NOT NULL PRIMARY KEY, notification_id UUID NOT NULL REFERENCES notification(id) ON DELETE CASCADE, device_id UUID NOT NULL REFERENCES push_device(id) ON DELETE CASCADE, token_hash VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, attempts INT NOT NULL, ticket VARCHAR(100) DEFAULT NULL, error_code VARCHAR(64) DEFAULT NULL, available_at TIMESTAMP(0) WITH TIME ZONE NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_push_delivery ON push_delivery (notification_id, device_id)');
        $this->addSql('CREATE INDEX idx_push_delivery_due ON push_delivery (status, available_at)');
        $this->addSql('CREATE INDEX idx_push_delivery_device ON push_delivery (device_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE push_delivery, push_device, notification_preference');
        $this->addSql('DROP INDEX idx_notification_push_pending');
        $this->addSql('ALTER TABLE notification DROP push_queued_at');
    }
}
