<?php declare(strict_types=1);

namespace Application\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261028235655 extends AbstractMigration
{
    public function getDescription() : string
    {
        return 'Mapping of the json type of doctrine/dbal 3 on MariaDB: tbl_webpush_user_subscription.subscription in JSON.';
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        // MariaDB checks each row (json_valid()). DBAL 3 flags the LONGTEXT column of DBAL 2
        // (declarationMismatch) and doctrine/migrations 3 adds its diff to every migration it runs:
        // the ALTER already comes with the ones before (Version20261028193655 on the production
        // server), and again here, without effect.
        $this->addSql('ALTER TABLE tbl_webpush_user_subscription CHANGE subscription subscription JSON NOT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema) : void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE tbl_webpush_user_subscription CHANGE subscription subscription LONGTEXT NOT NULL COMMENT \'(DC2Type:json)\'');
    }
}
