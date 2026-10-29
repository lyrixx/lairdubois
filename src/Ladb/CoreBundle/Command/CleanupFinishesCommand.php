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

#[AsCommand(name: 'ladb:cleanup:finishes', description: 'Cleanup finishes')]
class CleanupFinishesCommand extends Command {

	private $om;

	public function __construct(EntityManagerInterface $om) {
		$this->om = $om;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force removing')
			->setHelp(<<<EOT
The <info>ladb:cleanup:finishes</info> command remove unused finishes
EOT
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$om = $this->om;

		// Extract finishes /////

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'f' ))
			->from('LadbCoreBundle:Input\Finish', 'f')
		;

		try {
			$finishes = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		$finishCounters = array();
		foreach ($finishes as $finish) {
			$finishCounters[$finish->getId()] = array( $finish, 0 );
		}

		// Check creations /////

		$output->writeln('<info>Checking creations...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'c', 'f' ))
			->from('LadbCoreBundle:Wonder\Creation', 'c')
			->leftJoin('c.finishes', 'f')
		;

		try {
			$creations = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($creations as $creation) {
			foreach ($creation->getFinishes() as $finish) {
				$finishCounters[$finish->getId()][1]++;
			}
		}

		// Cleanup /////

		$forced = $input->getOption('force');
		$verbose = $input->getOption('verbose');
		$unusedFinishCount = 0;
		foreach ($finishCounters as $finishCounter) {
			$counter = $finishCounter[1];
			if ($counter == 0) {
				$unusedFinishCount++;
				$finish = $finishCounter[0];
				if ($verbose) {
					$output->writeln('<info> -> "'.$finish->getName().'" is unused</info>');
				}
				if ($forced) {
					$om->remove($finish);
				}
			}
		}

		if ($forced) {
			if ($unusedFinishCount > 0) {
				$om->flush();
			}
			$output->writeln('<info>'.$unusedFinishCount.' finishes removed</info>');
		} else {
			$output->writeln('<info>'.$unusedFinishCount.' finishes to remove</info>');
		}

		return 0;
	}

}