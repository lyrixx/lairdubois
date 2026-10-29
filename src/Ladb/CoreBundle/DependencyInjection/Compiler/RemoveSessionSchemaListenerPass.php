<?php

namespace Ladb\CoreBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The sessions table stays out of the schema of Doctrine, as before Symfony 6.3: it is created by
 * docs/database/schema-sessions.sql (castor app:db:schema), nothing changes in production. With
 * the listener of DoctrineBridge 6.3+, that DoctrineBundle registers for a PdoSessionHandler, each
 * schema command (schema:update, schema:validate, migrations:diff) would propose to alter the
 * table, and would create and drop a table to check that it is in the same database. MariaDB
 * commits on such a statement: in a test, the transaction of dama/doctrine-test-bundle is gone,
 * and PHPUnit stops ("There is no active transaction", CommandTest on ladb:init, which runs
 * doctrine:schema:update). The schema_filter of config.yml hides the table from these commands:
 * doctrine:schema:update --complete does not drop it.
 */
final readonly class RemoveSessionSchemaListenerPass implements CompilerPassInterface {

	public function process(ContainerBuilder $container): void {
		$container->removeDefinition('doctrine.orm.listeners.pdo_session_handler_schema_listener');
	}

}
