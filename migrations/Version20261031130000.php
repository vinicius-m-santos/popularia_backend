<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Enquete opcional da publicação: pergunta, opções e um voto por pessoa';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE poll (id UUID NOT NULL, post_id UUID NOT NULL, question VARCHAR(140) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_poll_post ON poll (post_id)');
        $this->addSql('ALTER TABLE poll ADD CONSTRAINT FK_POLL_POST FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE poll ADD CONSTRAINT chk_poll_question CHECK (char_length(btrim(question)) BETWEEN 1 AND 140)');

        $this->addSql('CREATE TABLE poll_option (id UUID NOT NULL, poll_id UUID NOT NULL, position SMALLINT NOT NULL, label VARCHAR(80) NOT NULL, vote_count INT DEFAULT 0 NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_POLL_OPTION_POLL ON poll_option (poll_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_poll_option_position ON poll_option (poll_id, position)');
        $this->addSql('ALTER TABLE poll_option ADD CONSTRAINT FK_POLL_OPTION_POLL FOREIGN KEY (poll_id) REFERENCES poll (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE poll_option ADD CONSTRAINT chk_poll_option_position CHECK (position BETWEEN 0 AND 5)');
        $this->addSql('ALTER TABLE poll_option ADD CONSTRAINT chk_poll_option_votes CHECK (vote_count >= 0)');
        $this->addSql('ALTER TABLE poll_option ADD CONSTRAINT chk_poll_option_label CHECK (char_length(btrim(label)) BETWEEN 1 AND 80)');

        $this->addSql('CREATE TABLE poll_vote (id UUID NOT NULL, poll_id UUID NOT NULL, option_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_POLL_VOTE_OPTION ON poll_vote (option_id)');
        $this->addSql('CREATE INDEX IDX_POLL_VOTE_USER ON poll_vote (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_poll_vote_user ON poll_vote (poll_id, user_id)');
        $this->addSql('ALTER TABLE poll_vote ADD CONSTRAINT FK_POLL_VOTE_POLL FOREIGN KEY (poll_id) REFERENCES poll (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE poll_vote ADD CONSTRAINT FK_POLL_VOTE_OPTION FOREIGN KEY (option_id) REFERENCES poll_option (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE poll_vote ADD CONSTRAINT FK_POLL_VOTE_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION poll_vote_same_poll() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NOT EXISTS (SELECT 1 FROM poll_option WHERE id = NEW.option_id AND poll_id = NEW.poll_id) THEN
                RAISE EXCEPTION 'Poll option does not belong to the poll' USING ERRCODE = '23514';
              END IF;
              RETURN NEW;
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER poll_vote_same_poll BEFORE INSERT OR UPDATE ON poll_vote FOR EACH ROW EXECUTE FUNCTION poll_vote_same_poll()');
        $this->addSql("CREATE TRIGGER reject_deleted_account BEFORE INSERT ON poll_vote FOR EACH ROW EXECUTE FUNCTION popularia_reject_deleted_account('user_id')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER IF EXISTS reject_deleted_account ON poll_vote');
        $this->addSql('DROP TRIGGER IF EXISTS poll_vote_same_poll ON poll_vote');
        $this->addSql('DROP FUNCTION IF EXISTS poll_vote_same_poll()');
        $this->addSql('DROP TABLE poll_vote');
        $this->addSql('DROP TABLE poll_option');
        $this->addSql('DROP TABLE poll');
    }
}
