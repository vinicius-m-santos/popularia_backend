<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persiste apoios em comentários, cria o apoio inicial da autoria e limita a bio a 150 caracteres.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comment_support (id UUID NOT NULL, comment_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_737789B5F8697D13 ON comment_support (comment_id)');
        $this->addSql('CREATE INDEX IDX_737789B5A76ED395 ON comment_support (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_comment_support ON comment_support (comment_id, user_id)');
        $this->addSql('ALTER TABLE comment_support ADD CONSTRAINT FK_COMMENT_SUPPORT_COMMENT FOREIGN KEY (comment_id) REFERENCES comment (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE comment_support ADD CONSTRAINT FK_COMMENT_SUPPORT_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql("INSERT INTO comment_support (id, comment_id, user_id) SELECT uuidv7(), id, author_id FROM comment WHERE status = 'published' ON CONFLICT DO NOTHING");
        $this->addSql('ALTER TABLE app_user ALTER bio TYPE VARCHAR(150)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ALTER bio TYPE VARCHAR(160)');
        $this->addSql('DROP TABLE comment_support');
    }
}
