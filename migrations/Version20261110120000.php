<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve moderation reports after account/comuna deletion, anonymize reporter and route orphaned reports to platform';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE content_report ALTER reporter_id DROP NOT NULL');
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT content_report_reporter_id_fkey');
        $this->addSql('ALTER TABLE content_report ADD CONSTRAINT content_report_reporter_id_fkey FOREIGN KEY (reporter_id) REFERENCES app_user(id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE content_report DROP CONSTRAINT content_report_comuna_id_fkey');
        $this->addSql('ALTER TABLE content_report ADD CONSTRAINT content_report_comuna_id_fkey FOREIGN KEY (comuna_id) REFERENCES comuna(id) ON DELETE SET NULL');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION preserve_orphaned_report() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.comuna_id IS NULL THEN NEW.scope := 'platform'; END IF;
                IF NEW.reporter_id IS NULL THEN NEW.reason := ''; END IF;
                RETURN NEW;
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER preserve_orphaned_report BEFORE UPDATE OF comuna_id, reporter_id ON content_report FOR EACH ROW EXECUTE FUNCTION preserve_orphaned_report()');
    }

    public function down(Schema $schema): void
    {
        // Reintroduzir CASCADE/NOT NULL perderia justamente as evidências preservadas.
        $this->throwIrreversibleMigrationException('Restaurar CASCADE apagaria denúncias preservadas e anonimizadas.');
    }
}
