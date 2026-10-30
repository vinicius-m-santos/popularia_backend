<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261106120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gamification V1: private profile, idempotent ledger, daily missions and achievements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gamification_profile (user_id UUID NOT NULL, xp_total INT DEFAULT 0 NOT NULL, streak_current INT DEFAULT 0 NOT NULL, last_activity_on DATE DEFAULT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(user_id))');
        $this->addSql('ALTER TABLE gamification_profile ADD CONSTRAINT fk_gamification_profile_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE gamification_profile ADD CONSTRAINT chk_gamification_profile_xp CHECK (xp_total >= 0)');
        $this->addSql('ALTER TABLE gamification_profile ADD CONSTRAINT chk_gamification_profile_streak CHECK (streak_current >= 0)');

        $this->addSql('CREATE TABLE gamification_entry (id UUID NOT NULL, user_id UUID NOT NULL, action VARCHAR(40) NOT NULL, source_type VARCHAR(40) NOT NULL, source_id UUID NOT NULL, xp INT NOT NULL, civil_date DATE NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_gamification_entry_source ON gamification_entry (user_id, action, source_type, source_id)');
        $this->addSql('CREATE INDEX idx_gamification_entry_user_action_day ON gamification_entry (user_id, action, civil_date)');
        $this->addSql('CREATE INDEX IDX_6FEEA8B2A76ED395 ON gamification_entry (user_id)');
        $this->addSql('ALTER TABLE gamification_entry ADD CONSTRAINT fk_gamification_entry_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE gamification_entry ADD CONSTRAINT chk_gamification_entry_xp CHECK (xp > 0)');
        $this->addSql("ALTER TABLE gamification_entry ADD CONSTRAINT chk_gamification_entry_action CHECK (action IN ('poll_participated', 'post_saved', 'discussion_participated', 'comuna_joined', 'event_rsvp_positive', 'mission_completed', 'missions_cleared'))");
        $this->addSql("ALTER TABLE gamification_entry ADD CONSTRAINT chk_gamification_entry_source CHECK (source_type IN ('poll', 'post', 'comuna', 'event', 'mission', 'civil_day'))");

        $this->addSql('CREATE TABLE gamification_mission (id UUID NOT NULL, user_id UUID NOT NULL, civil_date DATE NOT NULL, slot SMALLINT NOT NULL, action VARCHAR(40) NOT NULL, completed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_gamification_mission_slot ON gamification_mission (user_id, civil_date, slot)');
        $this->addSql('CREATE UNIQUE INDEX uniq_gamification_mission_action ON gamification_mission (user_id, civil_date, action)');
        $this->addSql('CREATE INDEX IDX_3F18C71CA76ED395 ON gamification_mission (user_id)');
        $this->addSql('ALTER TABLE gamification_mission ADD CONSTRAINT fk_gamification_mission_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE gamification_mission ADD CONSTRAINT chk_gamification_mission_slot CHECK (slot BETWEEN 1 AND 3)');
        $this->addSql("ALTER TABLE gamification_mission ADD CONSTRAINT chk_gamification_mission_action CHECK (action IN ('poll_participated', 'post_saved', 'discussion_participated', 'comuna_joined', 'event_rsvp_positive'))");

        $this->addSql('CREATE TABLE gamification_achievement (user_id UUID NOT NULL, code VARCHAR(40) NOT NULL, unlocked_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY(user_id, code))');
        $this->addSql('CREATE INDEX IDX_FA2F2CF4A76ED395 ON gamification_achievement (user_id)');
        $this->addSql('ALTER TABLE gamification_achievement ADD CONSTRAINT fk_gamification_achievement_user FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE gamification_achievement ADD CONSTRAINT chk_gamification_achievement_code CHECK (code IN ('primeiro_passo', 'para_depois', 'na_conversa', 'em_comunidade', 'organizando', 'constancia', 'uma_semana'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gamification_entry');
        $this->addSql('DROP TABLE gamification_mission');
        $this->addSql('DROP TABLE gamification_achievement');
        $this->addSql('DROP TABLE gamification_profile');
    }
}
