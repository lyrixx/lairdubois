<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Entity\Core\Vote;

#[AsCommand(name: 'ladb:generate:votecount', description: 'Generate votecount')]
class GenerateVoteCountCommand extends Command {

	private $om;

	public function __construct(EntityManagerInterface $om) {
		$this->om = $om;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:generate:votecount</info> command generate textures
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');

		$om = $this->om;
		$voteRepository = $om->getRepository(Vote::CLASS_NAME);

		// Retrieve Woods

		$output->write('<info>Resetting values...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'v' ))
			->from(\Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue::class, 'v')
		;

		try {
			$values = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$values = array();
		}

		$output->writeln('<comment> ['.count($values).' $values]</comment>');

		foreach ($values as $value) {

			$output->write('<info>Processing '.get_class($value).' ...</info>');

			$votes = $voteRepository->findByEntityTypeAndEntityId($value->getType(), $value->getId());
			$value->setVoteCount(count($votes));

			$output->writeln('<comment> ['.count($votes).' votes]</comment>');

		}

		if ($forced) {
			$om->flush();
		}

		return 0;

	}

}