<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ladb:clear', description: 'Clear database')]
class ClearCommand extends Command {

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force removing')
			->setHelp(<<<EOT
The <info>ladb:clear</info> command drop the default connections
database en create a new one :

<info>php app/console ladb:drop</info>

<error>Be careful: All data in a given database will be lost when executing
this command.</error>
EOT
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Run doctrine:database:drop --force

		$forced = $input->getOption('force');

		$command = $this->getApplication()->find('doctrine:database:drop');
		$arguments = array(
			'command' => 'doctrine:database:drop',
			'--force' => $forced,
		);

		$dropInput = new ArrayInput($arguments);
		$returnCode = $command->run($dropInput, $output);

		if ($returnCode) {
			return $returnCode;
		}

		// Run doctrine:database:create

		$command = $this->getApplication()->find('doctrine:database:create');
		$arguments = array(
			'command' => 'doctrine:database:create',
		);

		$createInput = new ArrayInput($arguments);
		$returnCode = $command->run($createInput, $output);

		if ($returnCode) {
			return $returnCode;
		}

		$output->writeln('<info>Ladb clear complete</info>');

		return 0;
	}

}