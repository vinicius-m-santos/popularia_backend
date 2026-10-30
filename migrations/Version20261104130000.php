<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Publicações fixadas na comuna. A publicação continua na tabela post.
 * O gatilho trava a linha da comuna antes de contar, como o limite de comunas
 * por proprietário: duas fixações simultâneas não passam de 3.
 */
final class Version20261104130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Publicações fixadas por comuna, no máximo 3, com ordem explícita.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE comuna_pinned_post (id UUID NOT NULL, comuna_id UUID NOT NULL, post_id UUID NOT NULL, position SMALLINT NOT NULL, pinned_by_id UUID DEFAULT NULL, pinned_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_comuna_pinned_post ON comuna_pinned_post (comuna_id, post_id)');
        $this->addSql('CREATE INDEX idx_comuna_pinned_post_order ON comuna_pinned_post (comuna_id, position)');
        $this->addSql('CREATE INDEX idx_comuna_pinned_post_post ON comuna_pinned_post (post_id)');
        $this->addSql('CREATE INDEX idx_comuna_pinned_post_pinned_by ON comuna_pinned_post (pinned_by_id)');
        $this->addSql('ALTER TABLE comuna_pinned_post ADD CONSTRAINT FK_comuna_pinned_post_comuna FOREIGN KEY (comuna_id) REFERENCES comuna (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_pinned_post ADD CONSTRAINT FK_comuna_pinned_post_post FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_pinned_post ADD CONSTRAINT FK_comuna_pinned_post_pinned_by FOREIGN KEY (pinned_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_pinned_post ADD CONSTRAINT chk_comuna_pinned_post_position CHECK (position BETWEEN 1 AND 3)');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION comuna_enforce_pin_limit() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                pin_count integer;
            BEGIN
                PERFORM 1 FROM comuna WHERE id = NEW.comuna_id FOR UPDATE;
                SELECT count(*) INTO pin_count FROM comuna_pinned_post WHERE comuna_id = NEW.comuna_id;
                IF pin_count >= 3 THEN
                    RAISE EXCEPTION 'comuna_pin_limit_reached' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER comuna_pin_limit BEFORE INSERT ON comuna_pinned_post FOR EACH ROW EXECUTE FUNCTION comuna_enforce_pin_limit()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER comuna_pin_limit ON comuna_pinned_post');
        $this->addSql('DROP FUNCTION comuna_enforce_pin_limit()');
        $this->addSql('ALTER TABLE comuna_pinned_post DROP CONSTRAINT chk_comuna_pinned_post_position');
        $this->addSql('ALTER TABLE comuna_pinned_post DROP CONSTRAINT FK_comuna_pinned_post_pinned_by');
        $this->addSql('ALTER TABLE comuna_pinned_post DROP CONSTRAINT FK_comuna_pinned_post_post');
        $this->addSql('ALTER TABLE comuna_pinned_post DROP CONSTRAINT FK_comuna_pinned_post_comuna');
        $this->addSql('DROP TABLE comuna_pinned_post');
    }
}
