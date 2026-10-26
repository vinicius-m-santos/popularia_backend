<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261026220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Assunto de publicações de perfil e índices PostgreSQL para descoberta e busca';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS unaccent WITH SCHEMA public');
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm WITH SCHEMA public');
        // Dicionário fixo: se suas regras mudarem, reconstruir os índices dependentes.
        $this->addSql(<<<'SQL'
            CREATE FUNCTION popularia_search_text(text) RETURNS text
            LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE
            AS $$ SELECT lower(public.unaccent('public.unaccent'::regdictionary, $1)) $$
            SQL);
        $this->addSql('ALTER TABLE post ADD topic VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT chk_post_topic_source CHECK (comuna_id IS NULL OR topic IS NULL)');
        $this->addSql("CREATE INDEX idx_search_people ON app_user USING gin (popularia_search_text(display_name || ' ' || handle) gin_trgm_ops) WHERE status = 'active'");
        $this->addSql("CREATE INDEX idx_search_comunas ON comuna USING gin (popularia_search_text(name || ' ' || description) gin_trgm_ops) WHERE status = 'active'");
        $this->addSql("CREATE INDEX idx_search_posts ON post USING gin (popularia_search_text(body) gin_trgm_ops) WHERE status = 'published'");
        $this->addSql("CREATE INDEX idx_discover_comuna_topic ON comuna ((topics->>0), id DESC) WHERE status = 'active'");
        $this->addSql("CREATE INDEX idx_discover_post_topic ON post (topic, id DESC) WHERE status = 'published' AND comuna_id IS NULL");
        $this->addSql("CREATE INDEX idx_discover_post_recent ON post (created_at DESC, id DESC) WHERE status = 'published'");
        $this->addSql("CREATE INDEX idx_discover_people ON app_user (id DESC) WHERE status = 'active'");
    }

    public function down(Schema $schema): void
    {
        foreach (['idx_search_people', 'idx_search_comunas', 'idx_search_posts', 'idx_discover_comuna_topic', 'idx_discover_post_topic', 'idx_discover_post_recent', 'idx_discover_people'] as $index) {
            $this->addSql('DROP INDEX '.$index);
        }
        $this->addSql('ALTER TABLE post DROP CONSTRAINT chk_post_topic_source');
        $this->addSql('ALTER TABLE post DROP topic');
        $this->addSql('DROP FUNCTION popularia_search_text(text)');
        // Extensões podem ser compartilhadas por outros recursos: não removê-las.
    }
}
