<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261027000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Uma Praça estrutural por Comuna; preenche ausências e sincroniza o lifecycle sem recriar histórico';
    }

    public function up(Schema $schema): void
    {
        // Estabiliza o conjunto e o estado das Comunas durante o preenchimento transacional.
        $this->addSql('LOCK TABLE comuna IN SHARE ROW EXCLUSIVE MODE');
        // Comunas da plataforma podem não ter owner/founder: não inventar autoria histórica.
        $this->addSql('ALTER TABLE square ALTER COLUMN created_by_id DROP NOT NULL');
        $this->addSql(<<<'SQL'
            INSERT INTO square (id, comuna_id, created_by_id, title, status, version, created_at, updated_at, closed_at)
            SELECT uuidv7(), c.id,
                COALESCE(c.owner_id, (SELECT m.user_id FROM comuna_membership m WHERE m.comuna_id = c.id AND m.role = 'founder' ORDER BY m.joined_at, m.id LIMIT 1)),
                NULL, CASE WHEN c.status = 'archived' THEN 'closed' ELSE 'active' END, 0,
                c.created_at,
                CASE WHEN c.status = 'archived' THEN COALESCE(c.archived_at, CURRENT_TIMESTAMP) ELSE c.created_at END,
                CASE WHEN c.status = 'archived' THEN COALESCE(c.archived_at, CURRENT_TIMESTAMP) ELSE NULL END
            FROM comuna c WHERE NOT EXISTS (SELECT 1 FROM square s WHERE s.comuna_id = c.id)
            ON CONFLICT (comuna_id) DO NOTHING
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO sticker_pack (id, comuna_id, scope, name)
            SELECT uuidv7(), c.id, 'comuna', c.name FROM comuna c
            WHERE NOT EXISTS (SELECT 1 FROM sticker_pack p WHERE p.comuna_id = c.id)
            ON CONFLICT (comuna_id) DO NOTHING
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE square s SET status = 'active', closed_at = NULL,
                updated_at = CURRENT_TIMESTAMP, version = s.version + 1
            FROM comuna c WHERE s.comuna_id = c.id AND c.status = 'active'
                AND (s.status <> 'active' OR s.closed_at IS NOT NULL)
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE square s SET status = 'closed',
                closed_at = COALESCE(c.archived_at, s.closed_at, CURRENT_TIMESTAMP),
                updated_at = CURRENT_TIMESTAMP, version = s.version + 1
            FROM comuna c WHERE s.comuna_id = c.id AND c.status = 'archived'
                AND (s.status <> 'closed' OR s.closed_at IS DISTINCT FROM COALESCE(c.archived_at, s.closed_at, CURRENT_TIMESTAMP))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(0 < (int) $this->connection->fetchOne('SELECT COUNT(*) FROM square WHERE created_by_id IS NULL'), 'Há Praças históricas sem autoria conhecida. Não é seguro restaurar NOT NULL inventando autoria ou apagando dados.');
        $this->addSql('ALTER TABLE square ALTER COLUMN created_by_id SET NOT NULL');
        // Nunca excluir Praças preenchidas nem desfazer dados/status que já podem ter nova atividade.
    }
}
