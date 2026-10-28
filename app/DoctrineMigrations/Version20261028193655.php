<?php declare(strict_types=1);

namespace Application\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028193655 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Mapping of the LogEntry of gedmo/doctrine-extensions 3: object_class and username in VARCHAR(191).';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');
        // No migration creates it: a database made by these migrations alone does not have it
        $this->skipIf(!$schema->hasTable('ext_log_entries'), 'No ext_log_entries table.');

        $this->addSql('ALTER TABLE ext_log_entries CHANGE object_class object_class VARCHAR(191) NOT NULL, CHANGE username username VARCHAR(191) DEFAULT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');
        $this->skipIf(!$schema->hasTable('ext_log_entries'), 'No ext_log_entries table.');

        $this->addSql('ALTER TABLE ext_log_entries CHANGE object_class object_class VARCHAR(255) NOT NULL, CHANGE username username VARCHAR(255) DEFAULT NULL');
    }
}
