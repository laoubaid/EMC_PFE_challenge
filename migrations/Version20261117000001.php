<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261117000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add duration_from_origin_min column to waypoint table for cumulative driving duration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waypoint ADD duration_from_origin_min INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE waypoint DROP COLUMN duration_from_origin_min');
    }
}
