<?php declare(strict_types=1);

namespace Application\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261115140000 extends AbstractMigration
{
    // The tables of the entities and relations that master removed from its mapping, and that no
    // migration of master still in the repository drops (#312): "doctrine:schema:update" of the
    // ORM 3 would drop them, and its check after the migrations (docs/04-delivery.md) would stop
    // the delivery. Some were dropped by migrations that master removed in 2020, others never had
    // one: those the database of the production has, if any. A join table comes before the table
    // it references
    private const TABLES = [
        'tbl_find_content_event_picture',
        'tbl_find_content_event',
        'tbl_knowledge2_book_review',
        'tbl_knowledge2_tool_value_identity',
        'tbl_knowledge2_value_tool_identity',
        'tbl_knowledge2_software_value_application',
        'tbl_knowledge2_value_application',
        'tbl_core_user_skill',
        'tbl_core_user_subscription',
        'tbl_knowledge2_book_value_author',
        'tbl_knowledge2_school_education',
        'tbl_knowledge2_software_value_license_url',
        'tbl_knowledge2_software_value_licenses',
        'tbl_knowledge2_tool_value_user_guide',
        'tbl_workflow_workflow_inspiration',
        'tbl_youtube_video',
    ];

    public function getDescription() : string
    {
        return 'Drops the tables of the entities and relations removed from the mapping by master, if the database still has them.';
    }

    // MariaDB commits before and after each DROP TABLE: a transaction around them would be
    // committed already when doctrine/migrations commits it
    public function isTransactional() : bool
    {
        return false;
    }

    public function up(Schema $schema) : void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'Migration can only be executed safely on \'mysql\'.');

        foreach (self::TABLES as $table) {
            $this->addSql("DROP TABLE IF EXISTS {$table}");
        }
    }

    public function down(Schema $schema) : void
    {
        $this->throwIrreversibleMigrationException('The tables of the entities removed by master are not created again: the backup of the database has them.');
    }
}
