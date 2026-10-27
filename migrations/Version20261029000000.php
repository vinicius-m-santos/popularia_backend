<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Beta: aceite jurídico, conta excluída, marcadores sem payload e limpeza física de PDFs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD legal_version VARCHAR(30) DEFAULT NULL, ADD legal_accepted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE square_message DROP CONSTRAINT chk_square_message_payload');
        $this->addSql("ALTER TABLE square_message ADD CONSTRAINT chk_square_message_payload CHECK (status <> 'published' OR ((body IS NOT NULL AND sticker_id IS NULL AND char_length(btrim(body)) BETWEEN 1 AND 2000) OR (body IS NULL AND sticker_id IS NOT NULL)))");
        $this->addSql('ALTER TABLE library_file DROP CONSTRAINT chk_library_file_status');
        $this->addSql("ALTER TABLE library_file ADD CONSTRAINT chk_library_file_status CHECK (status IN ('pending', 'ready', 'failed', 'purge_pending', 'purged'))");
        $this->addSql(<<<'SQL'
            CREATE OR REPLACE FUNCTION library_file_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              IF OLD.status = 'ready' AND ROW(NEW.storage_key, NEW.checksum, NEW.byte_size, NEW.page_count, NEW.mime_type, NEW.uploaded_by_id)
                IS DISTINCT FROM ROW(OLD.storage_key, OLD.checksum, OLD.byte_size, OLD.page_count, OLD.mime_type, OLD.uploaded_by_id) THEN
                RAISE EXCEPTION 'A finalized PDF is immutable';
              END IF;
              IF OLD.status = 'ready' AND NEW.original_filename IS DISTINCT FROM OLD.original_filename
                 AND NOT (NEW.original_filename = 'documento.pdf' AND EXISTS (SELECT 1 FROM app_user WHERE id = OLD.uploaded_by_id AND status = 'deleted')) THEN
                RAISE EXCEPTION 'A finalized PDF filename is immutable';
              END IF;
              RETURN NEW;
            END $$
            SQL);
        // Serializa inserções em andamento com a exclusão e impede gravar dados novos na conta encerrada.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION popularia_reject_deleted_account() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE field text; account_id uuid; account_status text;
            BEGIN
              FOREACH field IN ARRAY TG_ARGV LOOP
                account_id := (to_jsonb(NEW)->>field)::uuid;
                IF account_id IS NOT NULL THEN
                  SELECT status INTO account_status FROM app_user WHERE id = account_id FOR KEY SHARE;
                  IF account_status = 'deleted' THEN RAISE EXCEPTION 'Account was deleted' USING ERRCODE = '23514'; END IF;
                END IF;
              END LOOP;
              RETURN NEW;
            END $$
            SQL);
        foreach ([
            'auth_session' => ['user_id'], 'account_token' => ['user_id'], 'user_auth_identity' => ['user_id'],
            'post' => ['author_id'], 'comment' => ['author_id'], 'square_message' => ['author_id'],
            'user_follow' => ['follower_id', 'followed_id'], 'user_block' => ['blocker_id', 'blocked_id'],
            'library_file' => ['uploaded_by_id'], 'library_save' => ['user_id'],
            'square_reaction' => ['user_id'], 'square_visit' => ['user_id'],
            'comuna_membership' => ['user_id'], 'comuna_join_request' => ['user_id'],
            'comuna_ownership_transfer' => ['from_user_id', 'to_user_id'], 'content_report' => ['reporter_id'],
            'notification' => ['recipient_id', 'actor_id'],
            'library_collection' => ['created_by_id', 'user_id'],
        ] as $table => $fields) {
            $args = implode(', ', array_map(static fn (string $field): string => "'".$field."'", $fields));
            $this->addSql("CREATE TRIGGER reject_deleted_account BEFORE INSERT ON $table FOR EACH ROW EXECUTE FUNCTION popularia_reject_deleted_account($args)");
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(true, 'Exclusão é irreversível. Não restaurar dados pessoais por rollback.');
    }
}
