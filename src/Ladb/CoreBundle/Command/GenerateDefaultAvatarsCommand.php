<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Utils\UserUtils;

class GenerateDefaultAvatarsCommand extends Command {

	protected static $defaultName = 'ladb:generate:defaultavatars';

	private $om;
	private $userUtils;

	public function __construct(EntityManagerInterface $om, UserUtils $userUtils) {
		$this->om = $om;
		$this->userUtils = $userUtils;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setDescription('Generate defaultavatars')
			->setHelp(<<<EOT
The <info>ladb:generate:defaultavatars</info> command generate default avatars
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');

		$om = $this->om;
		$userUtils = $this->userUtils;

		// Count users /////

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'count(u.id)' ))
			->from('LadbCoreBundle:Core\User', 'u')
			->where('u.avatar is NULL')
		;

		try {
			$userCount = $queryBuilder->getQuery()->getSingleScalarResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$userCount = 0;
		}

		$output->writeln('<comment> ['.$userCount.' users]</comment>');

		$progress = new ProgressBar($output, $userCount);
		$progress->start();

		$batchSize = 200;
		$batchCount = $userCount / $batchSize;

		for ($batchIndex = 0; $batchIndex <= $batchCount; $batchIndex++) {

			// Extract users /////

			$queryBuilder = $om->createQueryBuilder();
			$queryBuilder
				->select(array( 'u' ))
				->from('LadbCoreBundle:Core\User', 'u')
				->where('u.avatar is NULL')
				->setFirstResult($batchIndex * $batchSize)
				->setMaxResults($batchSize)
			;


			try {
				$users = $queryBuilder->getQuery()->getResult();
			} catch (\Doctrine\ORM\NoResultException $e) {
				$users = array();
			}

			foreach ($users as $user) {
				$userUtils->createDefaultAvatar($user);
				$progress->advance();
			}

			if ($forced) {
				$om->flush();
			}

			unset($users);

		}

		$progress->finish();

		$output->writeln('<comment>[Finished]</comment>');

		return 0;
	}

}