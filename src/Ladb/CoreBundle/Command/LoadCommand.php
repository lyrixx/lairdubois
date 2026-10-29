<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ladb:load', description: 'Load fixture data into database')]
class LoadCommand extends Command {

	protected function configure(): void {
		$this
			->setHelp(<<<EOT
The <info>ladb:reset</info> command load fixtures :

<info>php app/console ladb:load</info>
EOT
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		// Run doctrine:fixtures:load

		$command = $this->getApplication()->find('doctrine:fixtures:load');
		$arguments = array(
			'command' => 'doctrine:fixtures:load'
		);

		$fixturesInput = new ArrayInput($arguments);
		$returnCode = $command->run($fixturesInput, $output);

		if ($returnCode) {
			return $returnCode;
		}

		$output->writeln('<info>Ladb load complete</info>');

		return 0;
	}

}