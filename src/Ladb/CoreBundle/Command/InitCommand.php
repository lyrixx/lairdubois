<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ladb:init', description: 'Reset database')]
class InitCommand extends Command {

	protected function configure(): void {
		$this
			->setHelp(<<<EOT
The <info>ladb:init</info> command create the default connections
database and update schema :

<info>php app/console ladb:init</info>

<error>Be careful: All data in a given database will be lost when executing
this command.</error>
EOT
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Run doctrine:schema:update --force

		$arguments = array(
			'command' => 'doctrine:schema:update',
			'--force' => true
		);

		$updateInput = new ArrayInput($arguments);
		// Through the application, with the events of the console: the SchemaFilterListener of
		// DoctrineMigrationsBundle hides migration_versions from doctrine:schema:update, always
		// complete in ORM 3, only when it sees the command start
		$returnCode = $this->getApplication()->doRun($updateInput, $output);

		if ($returnCode) {
			return $returnCode;
		}

		$output->writeln('<info>Ladb init complete</info>');

		return 0;
	}

}