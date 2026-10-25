<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Entity\Message\Thread;

class ResetUnreadMessageCountCommand extends Command {

	protected static $defaultName = 'ladb:reset:unreadmessagecount';

	private $om;

	public function __construct(EntityManagerInterface $om) {
		$this->om = $om;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setDescription('Reset unreadMessageCount')
			->setHelp(<<<EOT
The <info>ladb:reset:unreadmessagecount</info> command reset unreadMessageCount
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {

		$verbose = $input->getOption('verbose');
		$forced = $input->getOption('force');

		$om = $this->om;
		$threadRepository = $om->getRepository(Thread::CLASS_NAME);

		// Count users /////

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array('count(u.id)'))
			->from('LadbCoreBundle:Core\User', 'u');

		try {
			$userCount = $queryBuilder->getQuery()->getSingleScalarResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$userCount = 0;
		}

		$output->writeln('<comment> ['.$userCount.' users]</comment>');

		$progress = new ProgressBar($output, $userCount);
		$progress->start();

		$batchSize = 1000;
		$batchCount = $userCount / $batchSize;

		for ($batchIndex = 0; $batchIndex <= $batchCount; $batchIndex++) {

			// Extract users /////

			$queryBuilder = $om->createQueryBuilder();
			$queryBuilder
				->select(array('u'))
				->from('LadbCoreBundle:Core\User', 'u')
				->setFirstResult($batchIndex * $batchSize)
				->setMaxResults($batchSize);

			try {
				$users = $queryBuilder->getQuery()->getResult();
			} catch (\Doctrine\ORM\NoResultException $e) {
				$users = array();
			}

			// A team does not read its threads: its members do
			$unreadMessageCounts = $threadRepository->countUnreadMessageByUsers(array_filter($users, function ($user) { return !$user->getIsTeam(); }));

			foreach ($users as $user) {
				$progress->advance();

				if ($verbose) {
					$output->write('<info>Processing User username='.$user->getUsername().'...</info>');
				}

				$unreadMessageCount = $unreadMessageCounts[$user->getId()] ?? 0;

				// Set unread message count
				$user->getMeta()->setUnreadMessageCount($unreadMessageCount);

				if ($verbose) {
					$output->writeln('<fg=cyan> ['.$unreadMessageCount.' messages]</fg=cyan>');
				}

			}

			if ($forced) {
				$om->flush();
			}

			unset($users);

		}

		$progress->finish();

		$output->writeln('<comment>[Finished]</comment>');

	}

}