<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entrega C: comunas, regras, participações, publicações, comentários e bloqueios.
 */
final class Version20261026100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Comunas, publicações, comentários e bloqueios';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comment (id UUID NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, body TEXT NOT NULL, post_id UUID NOT NULL, parent_id UUID DEFAULT NULL, author_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_comment_post ON comment (post_id, id)');
        $this->addSql('CREATE INDEX IDX_9474526C4B89032C ON comment (post_id)');
        $this->addSql('CREATE INDEX IDX_9474526C727ACA70 ON comment (parent_id)');
        $this->addSql('CREATE INDEX IDX_9474526CF675F31B ON comment (author_id)');
        $this->addSql('CREATE TABLE comuna (id UUID NOT NULL, slug VARCHAR(40) NOT NULL, name VARCHAR(80) NOT NULL, description TEXT NOT NULL, place VARCHAR(80) NOT NULL, visibility VARCHAR(20) NOT NULL, tone VARCHAR(10) NOT NULL, topics JSONB DEFAULT \'[]\' NOT NULL, moderation_note TEXT DEFAULT \'\' NOT NULL, founded_year SMALLINT NOT NULL, member_count INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_comuna_slug ON comuna (slug)');
        $this->addSql('CREATE TABLE comuna_membership (id UUID NOT NULL, joined_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, role VARCHAR(20) NOT NULL, comuna_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_comuna_membership_user ON comuna_membership (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_comuna_membership ON comuna_membership (comuna_id, user_id)');
        $this->addSql('CREATE INDEX IDX_471698D573AEFE7 ON comuna_membership (comuna_id)');
        $this->addSql('CREATE TABLE comuna_rule (id UUID NOT NULL, position SMALLINT NOT NULL, title VARCHAR(120) NOT NULL, body TEXT NOT NULL, comuna_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_comuna_rule_position ON comuna_rule (comuna_id, position)');
        $this->addSql('CREATE INDEX IDX_E3F27A273AEFE7 ON comuna_rule (comuna_id)');
        $this->addSql('CREATE TABLE post (id UUID NOT NULL, status VARCHAR(20) NOT NULL, comment_count INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, body TEXT NOT NULL, author_id UUID NOT NULL, comuna_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_post_comuna ON post (comuna_id, status, id)');
        $this->addSql('CREATE INDEX idx_post_author ON post (author_id, id)');
        $this->addSql('CREATE INDEX IDX_5A8A6C8DF675F31B ON post (author_id)');
        $this->addSql('CREATE INDEX IDX_5A8A6C8D73AEFE7 ON post (comuna_id)');
        $this->addSql('CREATE TABLE user_block (id UUID NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, blocker_id UUID NOT NULL, blocked_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_user_block_blocked ON user_block (blocked_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_block ON user_block (blocker_id, blocked_id)');
        $this->addSql('CREATE INDEX IDX_61D96C7A548D5975 ON user_block (blocker_id)');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C727ACA70 FOREIGN KEY (parent_id) REFERENCES comment (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_membership ADD CONSTRAINT FK_471698D573AEFE7 FOREIGN KEY (comuna_id) REFERENCES comuna (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_membership ADD CONSTRAINT FK_471698D5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_rule ADD CONSTRAINT FK_E3F27A273AEFE7 FOREIGN KEY (comuna_id) REFERENCES comuna (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8DF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8D73AEFE7 FOREIGN KEY (comuna_id) REFERENCES comuna (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A548D5975 FOREIGN KEY (blocker_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT FK_61D96C7A21FF5136 FOREIGN KEY (blocked_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Defesa em profundidade: o banco recusa estados impossíveis mesmo se o código errar.
        $this->addSql('ALTER TABLE user_block ADD CONSTRAINT chk_user_block_not_self CHECK (blocker_id <> blocked_id)');
        $this->addSql('ALTER TABLE comuna ADD CONSTRAINT chk_comuna_member_count CHECK (member_count >= 0)');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT chk_post_comment_count CHECK (comment_count >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post DROP CONSTRAINT chk_post_comment_count');
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT chk_comuna_member_count');
        $this->addSql('ALTER TABLE user_block DROP CONSTRAINT chk_user_block_not_self');
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526C4B89032C');
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526C727ACA70');
        $this->addSql('ALTER TABLE comment DROP CONSTRAINT FK_9474526CF675F31B');
        $this->addSql('ALTER TABLE comuna_membership DROP CONSTRAINT FK_471698D573AEFE7');
        $this->addSql('ALTER TABLE comuna_membership DROP CONSTRAINT FK_471698D5A76ED395');
        $this->addSql('ALTER TABLE comuna_rule DROP CONSTRAINT FK_E3F27A273AEFE7');
        $this->addSql('ALTER TABLE post DROP CONSTRAINT FK_5A8A6C8DF675F31B');
        $this->addSql('ALTER TABLE post DROP CONSTRAINT FK_5A8A6C8D73AEFE7');
        $this->addSql('ALTER TABLE user_block DROP CONSTRAINT FK_61D96C7A548D5975');
        $this->addSql('ALTER TABLE user_block DROP CONSTRAINT FK_61D96C7A21FF5136');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE comuna');
        $this->addSql('DROP TABLE comuna_membership');
        $this->addSql('DROP TABLE comuna_rule');
        $this->addSql('DROP TABLE post');
        $this->addSql('DROP TABLE user_block');
    }
}
