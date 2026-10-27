<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Biblioteca V1: arquivos privados, origem, coleções, distribuição, salvos e referência em posts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD is_private BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE user_follow ADD approved BOOLEAN NOT NULL DEFAULT TRUE');
        $this->addSql('CREATE INDEX idx_follow_approval ON user_follow (followed_id, approved, id)');
        $this->addSql('CREATE TABLE library_collection (id UUID PRIMARY KEY, user_id UUID REFERENCES app_user(id) ON DELETE RESTRICT, comuna_id UUID REFERENCES comuna(id) ON DELETE CASCADE, created_by_id UUID NOT NULL REFERENCES app_user(id) ON DELETE RESTRICT, name VARCHAR(100) NOT NULL, description VARCHAR(500) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, CONSTRAINT chk_library_collection_origin CHECK ((user_id IS NULL) <> (comuna_id IS NULL)), CONSTRAINT chk_library_collection_name CHECK (char_length(btrim(name)) > 0), UNIQUE (id, user_id), UNIQUE (id, comuna_id))');
        $this->addSql("CREATE TABLE library_file (id UUID PRIMARY KEY, uploaded_by_id UUID NOT NULL REFERENCES app_user(id) ON DELETE RESTRICT, client_id VARCHAR(64) NOT NULL, storage_key VARCHAR(255) NOT NULL, original_filename VARCHAR(255) NOT NULL, checksum VARCHAR(64) NOT NULL, byte_size INT NOT NULL, page_count INT, status VARCHAR(20) NOT NULL, mime_type VARCHAR(64) NOT NULL DEFAULT 'application/pdf', created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, CONSTRAINT chk_library_file_mime CHECK (mime_type = 'application/pdf'), CONSTRAINT chk_library_file_size CHECK (byte_size BETWEEN 1 AND 26214400), CONSTRAINT chk_library_file_pages CHECK (page_count > 0), CONSTRAINT chk_library_file_checksum CHECK (checksum ~ '^[a-f0-9]{64}$'), CONSTRAINT chk_library_file_status CHECK (status IN ('pending', 'ready', 'failed', 'purged')))");
        $this->addSql('CREATE UNIQUE INDEX uniq_library_upload ON library_file (uploaded_by_id, client_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_storage_key ON library_file (storage_key)');
        $this->addSql('CREATE INDEX idx_library_file_cleanup ON library_file (status, created_at)');
        $this->addSql('CREATE INDEX idx_library_file_checksum ON library_file (checksum)');
        $this->addSql("CREATE TABLE library_document (id UUID PRIMARY KEY, file_id UUID NOT NULL UNIQUE REFERENCES library_file(id) ON DELETE RESTRICT, collection_id UUID NOT NULL REFERENCES library_collection(id) ON DELETE RESTRICT, user_id UUID REFERENCES app_user(id) ON DELETE RESTRICT, comuna_id UUID REFERENCES comuna(id) ON DELETE RESTRICT, title VARCHAR(160) NOT NULL, description VARCHAR(1000) NOT NULL, access VARCHAR(20) NOT NULL, is_public BOOLEAN NOT NULL DEFAULT FALSE, topic VARCHAR(20), status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, removed_at TIMESTAMP(0) WITH TIME ZONE, removed_by_id UUID REFERENCES app_user(id) ON DELETE SET NULL, removal_reason VARCHAR(500), CONSTRAINT chk_library_document_origin CHECK ((user_id IS NULL) <> (comuna_id IS NULL)), CONSTRAINT chk_library_document_title CHECK (char_length(btrim(title)) > 0), CONSTRAINT chk_library_document_access CHECK ((user_id IS NOT NULL AND access IN ('private', 'followers')) OR (comuna_id IS NOT NULL AND access IN ('visitors', 'members', 'moderators'))), CONSTRAINT chk_library_document_status CHECK (status IN ('published', 'deleted', 'removed')), CONSTRAINT chk_library_document_removal CHECK ((status = 'published') = (removed_at IS NULL)), CONSTRAINT fk_library_document_collection_user FOREIGN KEY (collection_id, user_id) REFERENCES library_collection(id, user_id), CONSTRAINT fk_library_document_collection_comuna FOREIGN KEY (collection_id, comuna_id) REFERENCES library_collection(id, comuna_id))");
        $this->addSql("ALTER TABLE library_document ADD CONSTRAINT chk_library_document_topic CHECK (topic IS NULL OR topic IN ('pol','trab','sind','eco','fem','anti','lgbt','edu','saude','clima','comun','cult','hist','livros','tec','games','esp','humor'))");
        $this->addSql('CREATE TABLE library_share (id UUID PRIMARY KEY, document_id UUID NOT NULL REFERENCES library_document(id) ON DELETE CASCADE, comuna_id UUID NOT NULL REFERENCES comuna(id) ON DELETE CASCADE, membership_id UUID NOT NULL REFERENCES comuna_membership(id) ON DELETE CASCADE, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'published\', CONSTRAINT chk_library_share_status CHECK (status IN (\'published\', \'removed\')))');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_share ON library_share (document_id, comuna_id)');
        $this->addSql('CREATE INDEX idx_library_share_comuna ON library_share (comuna_id, document_id)');
        $this->addSql('CREATE INDEX idx_library_share_membership ON library_share (membership_id)');
        $this->addSql('CREATE TABLE library_save (id UUID PRIMARY KEY, user_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, document_id UUID NOT NULL REFERENCES library_document(id) ON DELETE RESTRICT, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_library_save ON library_save (user_id, document_id)');
        $this->addSql('CREATE INDEX idx_library_save_user ON library_save (user_id, id)');
        $this->addSql('CREATE INDEX idx_library_collection_user ON library_collection (user_id, id)');
        $this->addSql('CREATE INDEX idx_library_collection_comuna ON library_collection (comuna_id, id)');
        foreach (['collection' => 'collection_id', 'user' => 'user_id', 'comuna' => 'comuna_id', 'topic' => 'topic', 'public' => 'is_public'] as $name => $column) {
            $this->addSql("CREATE INDEX idx_library_document_$name ON library_document ($column, status, id)");
        }
        // As extensões e a função normalizadora já existem na migration do Explorar.
        $this->addSql("CREATE INDEX idx_library_search_document ON library_document USING gin (popularia_search_text(title || ' ' || description) gin_trgm_ops) WHERE status = 'published'");
        $this->addSql("CREATE INDEX idx_library_search_title ON library_document USING gin (popularia_search_text(title) gin_trgm_ops) WHERE status = 'published'");
        $this->addSql('CREATE INDEX idx_library_search_collection ON library_collection USING gin (popularia_search_text(name) gin_trgm_ops)');
        $this->addSql('ALTER TABLE post ADD library_document_id UUID DEFAULT NULL REFERENCES library_document(id) ON DELETE RESTRICT');
        $this->addSql('CREATE INDEX idx_post_library_document ON post (library_document_id)');
        $this->addSql('ALTER TABLE content_report ALTER comuna_id DROP NOT NULL');
        $this->addSql("CREATE INDEX idx_library_reports_platform ON content_report (target_type, status, id) WHERE target_type = 'library_document'");
        $this->addSql(<<<'SQL'
            CREATE FUNCTION library_immutable_origin() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              IF TG_TABLE_NAME = 'library_document' AND (NEW.file_id IS DISTINCT FROM OLD.file_id OR NEW.user_id IS DISTINCT FROM OLD.user_id OR NEW.comuna_id IS DISTINCT FROM OLD.comuna_id) THEN
                RAISE EXCEPTION 'Document origin and file are immutable';
              END IF;
              RETURN NEW;
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER library_document_immutable BEFORE UPDATE ON library_document FOR EACH ROW EXECUTE FUNCTION library_immutable_origin()');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION library_file_immutable() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN
              IF OLD.status = 'ready' AND ROW(NEW.storage_key, NEW.checksum, NEW.byte_size, NEW.page_count, NEW.mime_type, NEW.original_filename, NEW.uploaded_by_id)
                IS DISTINCT FROM ROW(OLD.storage_key, OLD.checksum, OLD.byte_size, OLD.page_count, OLD.mime_type, OLD.original_filename, OLD.uploaded_by_id) THEN
                RAISE EXCEPTION 'A finalized PDF is immutable';
              END IF;
              RETURN NEW;
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER library_file_immutable BEFORE UPDATE ON library_file FOR EACH ROW EXECUTE FUNCTION library_file_immutable()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_library_reports_platform');
        $this->addSql("DELETE FROM content_report WHERE target_type = 'library_document'");
        $this->addSql('ALTER TABLE content_report ALTER comuna_id SET NOT NULL');
        $this->addSql('ALTER TABLE post DROP library_document_id');
        $this->addSql('DROP TABLE library_save, library_share, library_document, library_file, library_collection');
        $this->addSql('DROP FUNCTION library_immutable_origin()');
        $this->addSql('DROP FUNCTION library_file_immutable()');
        $this->addSql('ALTER TABLE user_follow DROP approved');
        $this->addSql('ALTER TABLE app_user DROP is_private');
    }
}
