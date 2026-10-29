<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index outgoing approved follows for following lists and counts.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_follow_outgoing ON user_follow (follower_id, approved, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_follow_outgoing');
    }
}
