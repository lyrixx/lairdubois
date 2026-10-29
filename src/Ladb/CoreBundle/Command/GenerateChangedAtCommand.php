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

#[AsCommand(name: 'ladb:generate:changedat', description: 'Generate ChangedAt')]
class GenerateChangedAtCommand extends Command {

	private $em;

	public function __construct(EntityManagerInterface $em) {
		$this->em = $em;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:generate:changedat</info> command generate ChangedAt
EOT
			);
	}

	private function _process($entityClass, $em, OutputInterface $output) {

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

		$entityCount = 0;
		foreach ($entities as $entity) {
			$entity->setChangedAt($entity->getCreatedAt());
			$entityCount++;
		}

		return $entityCount;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');

		$em = $this->em;

		$entityCount = 0;

		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Blog\Post::class, $em, $output);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Faq\Question::class, $em, $output);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Find\Find::class, $em, $output);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Howto\Howto::class, $em, $output);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Howto\Article::class, $em, $output);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Creation::class, $em, $output);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Plan::class, $em, $output);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Workshop::class, $em, $output);

		if ($forced) {
			$em->flush();
			$output->writeln('<info>'.$entityCount.' generated</info>');
		} else {
			$output->writeln('<info>'.$entityCount.' to generate</info>');
		}

		return 0;

	}

}