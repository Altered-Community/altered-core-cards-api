<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260707120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add gameplay_format table to track synced manifest format versions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE gameplay_format (id VARCHAR(100) NOT NULL, name VARCHAR(255) NOT NULL, version INT NOT NULL, creation_date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, update_date TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE gameplay_format');
    }
}
