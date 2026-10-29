<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ladb:cleanup:tools', description: 'Cleanup tools')]
class CleanupToolsCommand extends Command {

	private $om;

	public function __construct(EntityManagerInterface $om) {
		$this->om = $om;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force removing')
			->setHelp(<<<EOT
The <info>ladb:cleanup:tools</info> command remove unused tools
EOT
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$om = $this->om;

		// Extract tools /////

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'f' ))
			->from(\Ladb\CoreBundle\Entity\Input\Tool::class, 'f')
		;

		try {
			$tools = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		$toolCounters = array();
		foreach ($tools as $tool) {
			$toolCounters[$tool->getId()] = array( $tool, 0 );
		}

		// Check creations /////

		$output->writeln('<info>Checking creations...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'c', 'f' ))
			->from(\Ladb\CoreBundle\Entity\Wonder\Creation::class, 'c')
			->leftJoin('c.tools', 'f')
		;

		try {
			$creations = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($creations as $creation) {
			foreach ($creation->getTools() as $tool) {
				$toolCounters[$tool->getId()][1]++;
			}
		}

		// Cleanup /////

		$forced = $input->getOption('force');
		$verbose = $input->getOption('verbose');
		$unusedToolCount = 0;
		foreach ($toolCounters as $toolCounter) {
			$counter = $toolCounter[1];
			if ($counter == 0) {
				$unusedToolCount++;
				$tool = $toolCounter[0];
				if ($verbose) {
					$output->writeln('<info> -> "'.$tool->getName().'" is unused</info>');
				}
				if ($forced) {
					$om->remove($tool);
				}
			}
		}

		if ($forced) {
			if ($unusedToolCount > 0) {
				$om->flush();
			}
			$output->writeln('<info>'.$unusedToolCount.' tools removed</info>');
		} else {
			$output->writeln('<info>'.$unusedToolCount.' tools to remove</info>');
		}

		return 0;
	}

}