<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comunas criadas e geridas pelas próprias pessoas: proprietário, arquivamento, pedidos de
 * entrada, transferência de propriedade e as garantias no banco (um único "founder" por comuna,
 * no máximo 3 comunas ativas por proprietário mesmo com requisições simultâneas).
 */
final class Version20261026200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Criação e gestão de comunas pelas pessoas';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD last_comuna_created_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        $this->addSql('ALTER TABLE comuna ADD owner_id UUID DEFAULT NULL');
        $this->addSql("ALTER TABLE comuna ADD status VARCHAR(20) DEFAULT 'active' NOT NULL");
        $this->addSql('ALTER TABLE comuna ADD archived_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE comuna ADD CONSTRAINT FK_7E7DAE497E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_7E7DAE497E3C61F9 ON comuna (owner_id)');
        $this->addSql('CREATE INDEX idx_comuna_owner_status ON comuna (owner_id, status)');
        $this->addSql("ALTER TABLE comuna ADD CONSTRAINT chk_comuna_status CHECK (status IN ('active', 'archived'))");
        $this->addSql("ALTER TABLE comuna ADD CONSTRAINT chk_comuna_archived_at CHECK ((status = 'archived') = (archived_at IS NOT NULL))");

        // Dados existentes: se uma comuna tiver mais de um "founder", fica o mais antigo; os outros
        // passam para a moderação. Depois, o "founder" vira o proprietário (owner_id).
        $this->addSql(<<<'SQL'
            UPDATE comuna_membership m SET role = 'moderator'
            WHERE m.role = 'founder'
              AND EXISTS (SELECT 1 FROM comuna_membership o WHERE o.comuna_id = m.comuna_id AND o.role = 'founder' AND o.id < m.id)
            SQL);
        $this->addSql("UPDATE comuna c SET owner_id = m.user_id FROM comuna_membership m WHERE m.comuna_id = c.id AND m.role = 'founder'");
        $this->addSql("CREATE UNIQUE INDEX uniq_comuna_membership_founder ON comuna_membership (comuna_id) WHERE role = 'founder'");

        $this->addSql('CREATE TABLE comuna_join_request (id UUID NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, comuna_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_C580D0CC73AEFE7 ON comuna_join_request (comuna_id)');
        $this->addSql('CREATE INDEX IDX_C580D0CCA76ED395 ON comuna_join_request (user_id)');
        $this->addSql('CREATE INDEX idx_comuna_join_request_comuna ON comuna_join_request (comuna_id, status, id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_comuna_join_request_pending ON comuna_join_request (comuna_id, user_id) WHERE status = 'pending'");
        $this->addSql('ALTER TABLE comuna_join_request ADD CONSTRAINT FK_C580D0CC73AEFE7 FOREIGN KEY (comuna_id) REFERENCES comuna (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_join_request ADD CONSTRAINT FK_C580D0CCA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE comuna_join_request ADD CONSTRAINT chk_comuna_join_request_status CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))");

        $this->addSql('CREATE TABLE comuna_ownership_transfer (id UUID NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, comuna_id UUID NOT NULL, from_user_id UUID NOT NULL, to_user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_7DDC61A673AEFE7 ON comuna_ownership_transfer (comuna_id)');
        $this->addSql('CREATE INDEX IDX_7DDC61A62130303A ON comuna_ownership_transfer (from_user_id)');
        $this->addSql('CREATE INDEX IDX_7DDC61A629F6EE60 ON comuna_ownership_transfer (to_user_id)');
        $this->addSql('CREATE INDEX idx_comuna_ownership_transfer_to ON comuna_ownership_transfer (to_user_id, status)');
        $this->addSql("CREATE UNIQUE INDEX uniq_comuna_ownership_transfer_pending ON comuna_ownership_transfer (comuna_id) WHERE status = 'pending'");
        $this->addSql('ALTER TABLE comuna_ownership_transfer ADD CONSTRAINT FK_7DDC61A673AEFE7 FOREIGN KEY (comuna_id) REFERENCES comuna (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_ownership_transfer ADD CONSTRAINT FK_7DDC61A62130303A FOREIGN KEY (from_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comuna_ownership_transfer ADD CONSTRAINT FK_7DDC61A629F6EE60 FOREIGN KEY (to_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE comuna_ownership_transfer ADD CONSTRAINT chk_comuna_ownership_transfer_status CHECK (status IN ('pending', 'accepted', 'declined', 'cancelled'))");
        $this->addSql('ALTER TABLE comuna_ownership_transfer ADD CONSTRAINT chk_comuna_ownership_transfer_distinct CHECK (from_user_id <> to_user_id)');

        // Limite de comunas ativas por proprietário, garantido no banco. O gatilho trava a linha da
        // conta antes de contar: duas transações simultâneas para a mesma pessoa contam uma depois
        // da outra, nunca as duas sobre o mesmo total. O app confere antes (mensagem amigável);
        // isto é a última linha de defesa. O número 3 é Comuna::MAX_ACTIVE_PER_OWNER.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION comuna_enforce_owner_limit() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                active_count integer;
            BEGIN
                IF NEW.owner_id IS NULL OR NEW.status <> 'active' THEN
                    RETURN NEW;
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.owner_id IS NOT DISTINCT FROM NEW.owner_id AND OLD.status = NEW.status THEN
                    RETURN NEW;
                END IF;
                PERFORM 1 FROM app_user WHERE id = NEW.owner_id FOR UPDATE;
                SELECT count(*) INTO active_count FROM comuna WHERE owner_id = NEW.owner_id AND status = 'active' AND id <> NEW.id;
                IF active_count >= 3 THEN
                    RAISE EXCEPTION 'comuna_owner_limit_reached' USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        $this->addSql('CREATE TRIGGER comuna_owner_limit BEFORE INSERT OR UPDATE OF owner_id, status ON comuna FOR EACH ROW EXECUTE FUNCTION comuna_enforce_owner_limit()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER comuna_owner_limit ON comuna');
        $this->addSql('DROP FUNCTION comuna_enforce_owner_limit()');
        $this->addSql('DROP TABLE comuna_ownership_transfer');
        $this->addSql('DROP TABLE comuna_join_request');
        $this->addSql('DROP INDEX uniq_comuna_membership_founder');
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT chk_comuna_archived_at');
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT chk_comuna_status');
        $this->addSql('ALTER TABLE comuna DROP CONSTRAINT FK_7E7DAE497E3C61F9');
        $this->addSql('DROP INDEX idx_comuna_owner_status');
        $this->addSql('DROP INDEX IDX_7E7DAE497E3C61F9');
        $this->addSql('ALTER TABLE comuna DROP owner_id');
        $this->addSql('ALTER TABLE comuna DROP status');
        $this->addSql('ALTER TABLE comuna DROP archived_at');
        $this->addSql('ALTER TABLE app_user DROP last_comuna_created_at');
    }
}
