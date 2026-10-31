<?php declare(strict_types=1);

namespace Application\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031093745 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Mapping of doctrine/dbal 4: no more (DC2Type:…) comments on the array, simple_array and json columns.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        // Only the comments change: on MariaDB 10.11, each statement only changes the metadata
        // (ALGORITHM=INSTANT), without copying the table. No migration creates ext_log_entries: a
        // database made by these migrations alone does not have it
        if ($schema->hasTable('ext_log_entries')) {
            $this->addSql('ALTER TABLE ext_log_entries CHANGE data data LONGTEXT DEFAULT NULL');
        }
        $this->addSql('ALTER TABLE tbl_collection CHANGE entry_type_counters entry_type_counters LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE tbl_core_user CHANGE roles roles LONGTEXT NOT NULL');
        $this->addSql('ALTER TABLE tbl_core_witness CHANGE meta meta LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE tbl_webpush_user_subscription CHANGE subscription subscription JSON NOT NULL');
        $this->addSql('ALTER TABLE tbl_wonder_plan CHANGE kinds kinds LONGTEXT NOT NULL');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        if ($schema->hasTable('ext_log_entries')) {
            $this->addSql('ALTER TABLE ext_log_entries CHANGE data data LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:array)\'');
        }
        $this->addSql('ALTER TABLE tbl_collection CHANGE entry_type_counters entry_type_counters LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
        $this->addSql('ALTER TABLE tbl_core_user CHANGE roles roles LONGTEXT NOT NULL COMMENT \'(DC2Type:array)\'');
        $this->addSql('ALTER TABLE tbl_core_witness CHANGE meta meta LONGTEXT DEFAULT NULL COMMENT \'(DC2Type:simple_array)\'');
        $this->addSql('ALTER TABLE tbl_webpush_user_subscription CHANGE subscription subscription JSON NOT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('ALTER TABLE tbl_wonder_plan CHANGE kinds kinds LONGTEXT NOT NULL COMMENT \'(DC2Type:simple_array)\'');
    }
}
