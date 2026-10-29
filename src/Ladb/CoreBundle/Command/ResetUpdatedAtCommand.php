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

#[AsCommand(name: 'ladb:reset:updatedat', description: 'Reset updatedAt')]
class ResetUpdatedAtCommand extends Command {

	private $em;

	public function __construct(EntityManagerInterface $em) {
		$this->em = $em;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:reset:updatedat</info> command reset updatedAt
EOT
			);
	}

	private function _reset($entityClass, $em, OutputInterface $output) {

		$output->write('<info>Retrieve '.$entityClass.'...</info>');

		$queryBuilder = $em->createQueryBuilder();
		$queryBuilder
			->select(array( 'e' ))
			->from($entityClass, 'e')
		;

		try {
			$entities = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$entities = array();
		}

		$output->writeln('<comment> ['.count($entities).' '.$entityClass.']</comment>');

		foreach ($entities as $entity) {
			$entity->setUpdatedAt(null);
		}

		return count($entities);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');

		$em = $this->em;

		$entityCount = 0;

		$entityCount += $this->_reset('LadbCoreBundle:Blog\Post', $em, $output);
		$entityCount += $this->_reset('LadbCoreBundle:Faq\Question', $em, $output);
		$entityCount += $this->_reset('LadbCoreBundle:Find\Find', $em, $output);
		$entityCount += $this->_reset('LadbCoreBundle:Howto\Howto', $em, $output);
		$entityCount += $this->_reset('LadbCoreBundle:Howto\Article', $em, $output);
		$entityCount += $this->_reset('LadbCoreBundle:Wonder\Creation', $em, $output);
		$entityCount += $this->_reset('LadbCoreBundle:Wonder\Plan', $em, $output);
		$entityCount += $this->_reset('LadbCoreBundle:Wonder\Workshop', $em, $output);

		if ($forced) {
			$em->flush();
			$output->writeln('<info>'.$entityCount.' reseted</info>');
		} else {
			$output->writeln('<info>'.$entityCount.' to reseted</info>');
		}

		return 0;

	}

}