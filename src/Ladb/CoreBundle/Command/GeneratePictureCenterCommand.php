<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ladb:generate:picturecenter', description: 'Generate pictures center')]
class GeneratePictureCenterCommand extends Command {

	private $om;

	public function __construct(EntityManagerInterface $om) {
		$this->om = $om;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:generate:picturecenter</info> command generate pictures picturecenter
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');
		$verbose = $input->getOption('verbose');

		$om = $this->om;

		// Count pictures /////

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'count(p.id)' ))
			->from(\Ladb\CoreBundle\Entity\Core\Picture::class, 'p')
		;

		try {
			$pictureCount = $queryBuilder->getQuery()->getSingleScalarResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$pictureCount = 0;
		}

		$output->writeln('<comment> ['.$pictureCount.' pictures]</comment>');

		$progress = new ProgressBar($output, $pictureCount);
		$progress->start();

		$batchSize = 1000;
		$batchCount = $pictureCount / $batchSize;

		for ($batchIndex = 0; $batchIndex <= $batchCount; $batchIndex++) {

			// Extract pictures /////

			$queryBuilder = $om->createQueryBuilder();
			$queryBuilder
				->select(array( 'p' ))
				->from(\Ladb\CoreBundle\Entity\Core\Picture::class, 'p')
				->setFirstResult($batchIndex * $batchSize)
				->setMaxResults($batchSize)
			;

			try {
				$pictures = $queryBuilder->getQuery()->getResult();
			} catch (\Doctrine\ORM\NoResultException $e) {
				$pictures = array();
			}

			foreach ($pictures as $picture) {
				$progress->advance();
				if ($verbose) {
					$output->write('<info>Processing Picture ID='.$picture->getId().'...</info>');
				}
				if ($picture->getCenterX100() == 0) {
					$picture->setCenterX100(50);
				}
				if ($picture->getCenterY100() == 0) {
					$picture->setCenterY100(50);
				}
				if ($verbose) {
					$output->writeln('<info>centerX100='.$picture->getCenterX100().' centerY100='.$picture->getCenterY100().'</info><comment> [Done]</comment>');
				}
			}

			if ($forced) {
				$om->flush();
			}

			unset($pictures);

		}

		$progress->finish();

		$output->writeln('<comment>[Finished]</comment>');

		return 0;

	}

}