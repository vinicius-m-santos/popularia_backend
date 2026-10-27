<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Praça V1: histórico, alterações incrementais, presença, leitura, reações, figurinhas e denúncias';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE square (id UUID PRIMARY KEY, comuna_id UUID DEFAULT NULL REFERENCES comuna(id) ON DELETE CASCADE, created_by_id UUID NOT NULL REFERENCES app_user(id) ON DELETE RESTRICT, title VARCHAR(80) DEFAULT NULL, status VARCHAR(20) NOT NULL, version BIGINT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, closed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, CONSTRAINT chk_square_status CHECK (status IN ('active', 'closed')), CONSTRAINT chk_square_closed CHECK ((status = 'closed') = (closed_at IS NOT NULL)), CONSTRAINT chk_square_version CHECK (version >= 0))");
        // Unique nullable: uma V1 por comuna sem impedir futuras praças independentes.
        $this->addSql('CREATE UNIQUE INDEX uniq_square_comuna ON square (comuna_id)');
        $this->addSql('CREATE INDEX idx_square_creator ON square (created_by_id)');
        $this->addSql("CREATE TABLE sticker_pack (id UUID PRIMARY KEY, comuna_id UUID DEFAULT NULL REFERENCES comuna(id) ON DELETE CASCADE, scope VARCHAR(20) NOT NULL, name VARCHAR(80) NOT NULL, CONSTRAINT chk_sticker_scope CHECK ((scope = 'popularia' AND comuna_id IS NULL) OR (scope = 'comuna' AND comuna_id IS NOT NULL)))");
        $this->addSql('CREATE UNIQUE INDEX uniq_sticker_pack_comuna ON sticker_pack (comuna_id)');
        $this->addSql("CREATE UNIQUE INDEX uniq_sticker_pack_global ON sticker_pack (scope) WHERE scope = 'popularia'");
        $this->addSql("CREATE TABLE sticker (id UUID PRIMARY KEY, pack_id UUID NOT NULL REFERENCES sticker_pack(id) ON DELETE CASCADE, label VARCHAR(24) NOT NULL, symbol VARCHAR(16) NOT NULL, tone VARCHAR(10) NOT NULL, active BOOLEAN NOT NULL, asset_key VARCHAR(255) DEFAULT NULL, CONSTRAINT chk_sticker_label CHECK (char_length(btrim(label)) > 0), CONSTRAINT chk_sticker_tone CHECK (tone IN ('red', 'black', 'cream')))");
        $this->addSql('CREATE INDEX idx_sticker_pack_active ON sticker (pack_id, active)');
        $this->addSql("CREATE TABLE square_message (id UUID PRIMARY KEY, square_id UUID NOT NULL REFERENCES square(id) ON DELETE CASCADE, author_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, body TEXT DEFAULT NULL, sticker_id UUID DEFAULT NULL REFERENCES sticker(id) ON DELETE RESTRICT, reply_to_id UUID DEFAULT NULL, status VARCHAR(20) NOT NULL, client_id VARCHAR(64) NOT NULL, revision BIGINT NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, edited_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, CONSTRAINT uniq_square_message_scope UNIQUE (id, square_id), CONSTRAINT chk_square_message_payload CHECK ((body IS NOT NULL AND sticker_id IS NULL AND char_length(btrim(body)) BETWEEN 1 AND 2000) OR (body IS NULL AND sticker_id IS NOT NULL)), CONSTRAINT chk_square_message_status CHECK (status IN ('published', 'deleted', 'removed')), CONSTRAINT chk_square_message_revision CHECK (revision > 0), CONSTRAINT fk_square_message_reply FOREIGN KEY (reply_to_id, square_id) REFERENCES square_message (id, square_id) ON DELETE SET NULL (reply_to_id) DEFERRABLE INITIALLY DEFERRED)");
        $this->addSql("CREATE INDEX idx_square_message_activity ON square_message (square_id, created_at) WHERE status = 'published'");
        $this->addSql('CREATE INDEX idx_square_message_history ON square_message (square_id, id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_square_message_revision ON square_message (square_id, revision)');
        $this->addSql('CREATE INDEX idx_square_message_changes ON square_message (square_id, revision)');
        $this->addSql('CREATE INDEX idx_square_message_author ON square_message (author_id)');
        $this->addSql('CREATE INDEX idx_square_message_reply ON square_message (reply_to_id)');
        $this->addSql('CREATE INDEX idx_square_message_sticker ON square_message (sticker_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_square_message_client ON square_message (square_id, author_id, client_id)');
        $this->addSql('CREATE TABLE square_reaction (id UUID PRIMARY KEY, message_id UUID NOT NULL REFERENCES square_message(id) ON DELETE CASCADE, user_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, emoji VARCHAR(16) NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_square_reaction ON square_reaction (message_id, user_id, emoji)');
        $this->addSql('CREATE INDEX idx_square_reaction_user ON square_reaction (user_id)');
        $this->addSql('CREATE TABLE square_visit (id UUID PRIMARY KEY, square_id UUID NOT NULL REFERENCES square(id) ON DELETE CASCADE, user_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, last_read_id UUID DEFAULT NULL, seen_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL)');
        $this->addSql('ALTER TABLE square_visit ADD CONSTRAINT fk_square_visit_read FOREIGN KEY (last_read_id, square_id) REFERENCES square_message (id, square_id) ON DELETE SET NULL (last_read_id) DEFERRABLE INITIALLY DEFERRED');
        $this->addSql('CREATE UNIQUE INDEX uniq_square_visit ON square_visit (square_id, user_id)');
        $this->addSql('CREATE INDEX idx_square_visit_presence ON square_visit (square_id, seen_at)');
        $this->addSql('CREATE INDEX idx_square_visit_user ON square_visit (user_id)');
        $this->addSql("CREATE TABLE content_report (id UUID PRIMARY KEY, comuna_id UUID NOT NULL REFERENCES comuna(id) ON DELETE CASCADE, reporter_id UUID NOT NULL REFERENCES app_user(id) ON DELETE CASCADE, target_type VARCHAR(32) NOT NULL, target_id UUID NOT NULL, reason VARCHAR(500) NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, resolved_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, CONSTRAINT chk_content_report_status CHECK (status IN ('pending', 'dismissed', 'removed')))");
        $this->addSql('CREATE UNIQUE INDEX uniq_content_report ON content_report (reporter_id, target_type, target_id)');
        $this->addSql('CREATE INDEX idx_content_report_queue ON content_report (comuna_id, status, id)');
        $this->addSql('ALTER TABLE notification ADD square_message_id UUID DEFAULT NULL REFERENCES square_message(id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX idx_notification_square_message ON notification (square_message_id)');
        $this->addSql("INSERT INTO sticker_pack (id, comuna_id, scope, name) VALUES ('019a2000-0000-7000-8000-000000000001', NULL, 'popularia', 'Popularia')");
        foreach ([['✊', 'JUNTOS'], ['📢', 'BORA!'], ['❤️', 'SOLIDARIEDADE'], ['🤝', 'TAMO JUNTO'], ['🔥', 'NA LUTA'], ['🌱', 'VAI FLORESCER']] as $i => [$symbol, $label]) {
            $id = sprintf('019a2000-0000-7000-8000-%012d', $i + 10);
            $this->addSql('INSERT INTO sticker (id, pack_id, label, symbol, tone, active) VALUES (?, ?, ?, ?, ?, true)', [$id, '019a2000-0000-7000-8000-000000000001', $label, $symbol, 0 === $i % 2 ? 'red' : 'black']);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP COLUMN square_message_id');
        foreach (['content_report', 'square_reaction', 'square_visit', 'square_message', 'sticker', 'sticker_pack', 'square'] as $table) {
            $this->addSql('DROP TABLE '.$table.' CASCADE');
        }
    }
}
