<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261112120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persiste apoios e ecos de publicações, com uma linha por pessoa.';
    }

    public function up(Schema $schema): void
    {
        foreach ([['post_support', 'support'], ['post_echo', 'echo']] as [$table, $suffix]) {
            $this->addSql('CREATE TABLE '.$table.' (id UUID NOT NULL, post_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY(id))');
            $this->addSql('CREATE INDEX IDX_'.strtoupper($table).'_POST ON '.$table.' (post_id)');
            $this->addSql('CREATE INDEX IDX_'.strtoupper($table).'_USER ON '.$table.' (user_id)');
            $this->addSql('CREATE UNIQUE INDEX uniq_'.$table.' ON '.$table.' (post_id, user_id)');
            $this->addSql('ALTER TABLE '.$table.' ADD CONSTRAINT FK_'.strtoupper($table).'_POST FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
            $this->addSql('ALTER TABLE '.$table.' ADD CONSTRAINT FK_'.strtoupper($table).'_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE post_echo');
        $this->addSql('DROP TABLE post_support');
    }
}
