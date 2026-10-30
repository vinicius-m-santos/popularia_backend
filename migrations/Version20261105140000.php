<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Eventos de comuna, presença e lembrete. Não há exclusão física na aplicação:
 * cancelar muda o status. A comuna, se for apagada, leva os eventos junto.
 */
final class Version20261105140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Eventos de comuna, respostas de presença e lembretes duráveis.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE comuna_event (
                id UUID NOT NULL,
                comuna_id UUID NOT NULL,
                organizer_id UUID DEFAULT NULL,
                title VARCHAR(120) NOT NULL,
                description TEXT NOT NULL,
                format VARCHAR(20) NOT NULL,
                place_name VARCHAR(120) DEFAULT NULL,
                address VARCHAR(240) DEFAULT NULL,
                online_url VARCHAR(500) DEFAULT NULL,
                time_zone VARCHAR(64) NOT NULL,
                starts_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                ends_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                revision INT DEFAULT 0 NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_comuna_event_comuna FOREIGN KEY (comuna_id) REFERENCES comuna (id) ON DELETE CASCADE,
                CONSTRAINT fk_comuna_event_organizer FOREIGN KEY (organizer_id) REFERENCES app_user (id) ON DELETE SET NULL,
                CONSTRAINT chk_comuna_event_format CHECK (format IN ('in_person', 'online', 'hybrid')),
                CONSTRAINT chk_comuna_event_status CHECK (status IN ('scheduled', 'cancelled')),
                CONSTRAINT chk_comuna_event_span CHECK (ends_at IS NULL OR ends_at > starts_at),
                CONSTRAINT chk_comuna_event_place CHECK (
                    (format = 'online' AND online_url IS NOT NULL AND place_name IS NULL AND address IS NULL)
                    OR (format = 'in_person' AND place_name IS NOT NULL AND online_url IS NULL)
                    OR (format = 'hybrid' AND place_name IS NOT NULL AND online_url IS NOT NULL)
                )
            )
            SQL);
        $this->addSql('CREATE INDEX idx_comuna_event_when ON comuna_event (comuna_id, starts_at, id)');
        $this->addSql('CREATE INDEX idx_comuna_event_organizer ON comuna_event (organizer_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE event_attendance (
                id UUID NOT NULL,
                event_id UUID NOT NULL,
                user_id UUID NOT NULL,
                status VARCHAR(20) NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_event_attendance_event FOREIGN KEY (event_id) REFERENCES comuna_event (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_attendance_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE,
                CONSTRAINT uniq_event_attendance UNIQUE (event_id, user_id),
                CONSTRAINT chk_event_attendance_status CHECK (status IN ('going', 'maybe', 'not_going'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_event_attendance_status ON event_attendance (event_id, status, id)');
        $this->addSql('CREATE INDEX idx_event_attendance_user ON event_attendance (user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE event_reminder (
                id UUID NOT NULL,
                event_id UUID NOT NULL,
                user_id UUID NOT NULL,
                remind_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                sent_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                sent_for_starts_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_event_reminder_event FOREIGN KEY (event_id) REFERENCES comuna_event (id) ON DELETE CASCADE,
                CONSTRAINT fk_event_reminder_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE,
                CONSTRAINT uniq_event_reminder UNIQUE (event_id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_event_reminder_due ON event_reminder (remind_at, id) WHERE sent_at IS NULL');
        $this->addSql('CREATE INDEX idx_event_reminder_user ON event_reminder (user_id)');

        $this->addSql('ALTER TABLE post ADD event_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT fk_post_event FOREIGN KEY (event_id) REFERENCES comuna_event (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_post_event ON post (event_id) WHERE event_id IS NOT NULL');

        $this->addSql('ALTER TABLE notification ADD event_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT fk_notification_event FOREIGN KEY (event_id) REFERENCES comuna_event (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_notification_event ON notification (event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT fk_notification_event');
        $this->addSql('DROP INDEX idx_notification_event');
        $this->addSql('ALTER TABLE notification DROP event_id');
        $this->addSql('ALTER TABLE post DROP CONSTRAINT fk_post_event');
        $this->addSql('DROP INDEX idx_post_event');
        $this->addSql('ALTER TABLE post DROP event_id');
        $this->addSql('DROP TABLE event_reminder');
        $this->addSql('DROP TABLE event_attendance');
        $this->addSql('DROP TABLE comuna_event');
    }
}
