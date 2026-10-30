<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Busca global: eventos por trigrama, slug de comuna e full-text português das publicações.
 * unaccent e pg_trgm já existem (Version20261026220000). Não remove extensões no down.
 */
final class Version20261107120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Índices da busca global para eventos, slug de comuna e texto de publicações';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_search_events ON comuna_event USING gin (popularia_search_text(title || ' ' || description || ' ' || COALESCE(place_name, '')) gin_trgm_ops)");
        $this->addSql("CREATE INDEX idx_search_comuna_slug ON comuna USING gin (popularia_search_text(slug) gin_trgm_ops) WHERE status = 'active'");
        $this->addSql("CREATE INDEX idx_search_posts_fts ON post USING gin (to_tsvector('portuguese', popularia_search_text(body))) WHERE status = 'published'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_search_events');
        $this->addSql('DROP INDEX idx_search_comuna_slug');
        $this->addSql('DROP INDEX idx_search_posts_fts');
    }
}
