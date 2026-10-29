<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Utils\MailerUtils;

#[AsCommand(name: 'ladb:mailing:weeknews', description: 'Send week news')]
class MailingWeekNewsCommand extends Command {

	private $mailerUtils;
	private $om;

	public function __construct(MailerUtils $mailerUtils, EntityManagerInterface $om) {
		$this->mailerUtils = $mailerUtils;
		$this->om = $om;
		parent::__construct();
	}

	use LockableTrait;

	protected function configure(): void {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force sending')
			->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of users')
			->setHelp(<<<EOT
The <info>ladb:mailing:newsletter</info> send week news
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		if (!$this->lock()) {
			$output->writeln('The command is already running in another process.');
			return 0;
		}

		$forced = $input->getOption('force');
		$verbose = $input->getOption('verbose');
		$limit = $input->getOption('limit');

		$om = $this->om;
		$mailerUtils = $this->mailerUtils;

		$now = new \DateTime();
		$date = $now->sub(new \DateInterval('P7D'));	// 7 days

		// Retrieve creations

		$output->write('<info>Retrieving new creations...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'c', 'u', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Wonder\Creation::class, 'c')
			->innerJoin('c.user', 'u')
			->innerJoin('c.mainPicture', 'mp')
			->where('c.isDraft = false')
			->andWhere('c.createdAt > :date')
			->orderBy('c.likeCount', 'DESC')
			->addOrderBy('c.commentCount', 'DESC')
			->addOrderBy('c.viewCount', 'DESC')
			->addOrderBy('c.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$creations = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$creations = array();
		}

		$output->writeln('<comment> ['.count($creations).' creations]</comment>');

		// Retrieve questions

		$output->write('<info>Retrieving new questions...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'q', 'u' ))
			->from(\Ladb\CoreBundle\Entity\Qa\Question::class, 'q')
			->innerJoin('q.user', 'u')
			->where('q.isDraft = false')
			->andWhere('q.createdAt > :date')
			->orderBy('q.answerCount', 'ASC')
			->addOrderBy('q.likeCount', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$questions = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$questions = array();
		}

		$output->writeln('<comment> ['.count($questions).' questions]</comment>');

		// Retrieve plans

		$output->write('<info>Retrieving new plans...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'p', 'u', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Wonder\Plan::class, 'p')
			->innerJoin('p.user', 'u')
			->innerJoin('p.mainPicture', 'mp')
			->where('p.isDraft = false')
			->andWhere('p.createdAt > :date')
			->orderBy('p.likeCount', 'DESC')
			->addOrderBy('p.commentCount', 'DESC')
			->addOrderBy('p.viewCount', 'DESC')
			->addOrderBy('p.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$plans = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$plans = array();
		}

		$output->writeln('<comment> ['.count($plans).' plans]</comment>');

		// Retrieve workshops

		$output->write('<info>Retrieving new workshops...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'w', 'u', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Wonder\Workshop::class, 'w')
			->innerJoin('w.user', 'u')
			->innerJoin('w.mainPicture', 'mp')
			->where('w.isDraft = false')
			->andWhere('w.createdAt > :date')
			->orderBy('w.likeCount', 'DESC')
			->addOrderBy('w.commentCount', 'DESC')
			->addOrderBy('w.viewCount', 'DESC')
			->addOrderBy('w.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$workshops = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$workshops = array();
		}

		$output->writeln('<comment> ['.count($workshops).' workshops]</comment>');

		// Retrieve howtos

		$output->write('<info>Retrieving new howtos...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'h', 'u', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Howto\Howto::class, 'h')
			->innerJoin('h.user', 'u')
			->leftJoin('h.mainPicture', 'mp')
			->where('h.isDraft = false')
			->andWhere('h.createdAt > :date')
			->orderBy('h.likeCount', 'DESC')
			->addOrderBy('h.commentCount', 'DESC')
			->addOrderBy('h.viewCount', 'DESC')
			->addOrderBy('h.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$howtos = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$howtos = array();
		}

		$output->writeln('<comment> ['.count($howtos).' howtos]</comment>');

		// Retrieve howtos articles

		$output->write('<info>Retrieving new howtos articles...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'a', 'h', 'u' ))
			->from(\Ladb\CoreBundle\Entity\Howto\Article::class, 'a')
			->innerJoin('a.howto', 'h')
			->innerJoin('h.user', 'u')
			->where('a.isDraft = false')
			->andWhere('h.isDraft = false')
			->andWhere('a.createdAt > :date')
			->orderBy('a.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$articles = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$articles = array();
		}

		$howtoArticles = array();
		foreach ($articles as $article) {
			$howtoId = $article->getHowto()->getId();
			$exclude = false;
			foreach ($howtos as $howto) {
				if ($howto->getId() == $howtoId) {
					$exclude = true;
					break;
				}
			}
			if (!$exclude) {
				$howtoArticles[] = $article;
			}
		}

		$output->writeln('<comment> ['.count($howtoArticles).' howto articles]</comment>');

		// Retrieve finds

		$output->write('<info>Retrieving new finds...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'f', 'u' ))
			->from(\Ladb\CoreBundle\Entity\Find\Find::class, 'f')
			->innerJoin('f.user', 'u')
			->where('f.isDraft = false')
			->andWhere('f.createdAt > :date')
			->orderBy('f.likeCount', 'DESC')
			->addOrderBy('f.commentCount', 'DESC')
			->addOrderBy('f.viewCount', 'DESC')
			->addOrderBy('f.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$finds = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$finds = array();
		}

		$output->writeln('<comment> ['.count($finds).' finds]</comment>');

		// Retrieve posts

		$output->write('<info>Retrieving new posts...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'p', 'u', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Blog\Post::class, 'p')
			->innerJoin('p.user', 'u')
			->leftJoin('p.mainPicture', 'mp')
			->where('p.isDraft = false')
			->andWhere('p.createdAt > :date')
			->orderBy('p.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$posts = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$posts = array();
		}

		$output->writeln('<comment> ['.count($posts).' posts]</comment>');

		// Retrieve woods

		$output->write('<info>Retrieving new woods...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'w', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Knowledge\Wood::class, 'w')
			->innerJoin('w.mainPicture', 'mp')
			->where('w.nameRejected = false')
			->andWhere('w.grainRejected = false')
			->andWhere('w.createdAt > :date')
			->orderBy('w.likeCount', 'DESC')
			->addOrderBy('w.commentCount', 'DESC')
			->addOrderBy('w.viewCount', 'DESC')
			->addOrderBy('w.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$woods = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$woods = array();
		}

		$output->writeln('<comment> ['.count($woods).' woods]</comment>');

		// Retrieve providers

		$output->write('<info>Retrieving new providers...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'p', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Knowledge\Provider::class, 'p')
			->innerJoin('p.mainPicture', 'mp')
			->where('p.signRejected = false')
			->andWhere('p.logoRejected = false')
			->andWhere('p.createdAt > :date')
			->orderBy('p.likeCount', 'DESC')
			->addOrderBy('p.commentCount', 'DESC')
			->addOrderBy('p.viewCount', 'DESC')
			->addOrderBy('p.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$providers = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$providers = array();
		}

		$output->writeln('<comment> ['.count($providers).' providers]</comment>');

		// Retrieve schools

		$output->write('<info>Retrieving new schools...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 's', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Knowledge\School::class, 's')
			->innerJoin('s.mainPicture', 'mp')
			->where('s.nameRejected = false')
			->andWhere('s.logoRejected = false')
			->andWhere('s.createdAt > :date')
			->orderBy('s.likeCount', 'DESC')
			->addOrderBy('s.commentCount', 'DESC')
			->addOrderBy('s.viewCount', 'DESC')
			->addOrderBy('s.createdAt', 'DESC')
			->setParameter('date', $date)
		;

		try {
			$schools = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$schools = array();
		}

		$output->writeln('<comment> ['.count($schools).' schools]</comment>');

		// Sending ...

		if (count($creations) > 0 || count($questions) > 0 || count($plans) > 0 || count($workshops) > 0 || count($howtos) > 0 || count($howtoArticles) > 0 || count($finds) > 0 || count($posts) > 0 || count($woods) > 0 || count($providers) > 0 || count($schools) > 0) {

			// Count users /////

			$queryBuilder = $om->createQueryBuilder();
			$queryBuilder
				->select(array( 'count(u.id)' ))
				->from(\Ladb\CoreBundle\Entity\Core\User::class, 'u')
				->innerJoin('u.meta', 'm')
				->where('u.enabled = true')
				->andWhere('u.emailConfirmed = true')
				->andWhere('m.weekNewsEmailEnabled = true')
			;

			try {
				$userCount = $queryBuilder->getQuery()->getSingleScalarResult();
			} catch (\Doctrine\ORM\NoResultException $e) {
				$userCount = 0;
			}

			if ($limit > 0) {
				$userCount = min($userCount, $limit);
			}

			$progressBar = new ProgressBar($output, $userCount);
			$progressBar->start();

			$batchSize = min(100, $userCount);
			$batchCount = ceil($userCount / $batchSize);

			for ($batchIndex = 0; $batchIndex < $batchCount; $batchIndex++) {

				// Extract users /////

				$queryBuilder = $om->createQueryBuilder();
				$queryBuilder
					->select(array( 'u', 'm' ))
					->from(\Ladb\CoreBundle\Entity\Core\User::class, 'u')
					->innerJoin('u.meta', 'm')
					->where('u.enabled = true')
					->andWhere('u.isTeam = false')
					->andWhere('u.emailConfirmed = true')
					->andWhere('m.weekNewsEmailEnabled = true')
					->setFirstResult($batchIndex * $batchSize)
					->setMaxResults($batchSize)
				;

				try {
					$users = $queryBuilder->getQuery()->getResult();
				} catch (\Doctrine\ORM\NoResultException $e) {
					$users = array();
				}

				foreach ($users as $user) {
					$progressBar->advance();
					if ($verbose) {
						$output->write('<info>Sending week news to '.$user->getDisplayname().'...</info>');
					}
					if ($forced) {
						try {
							$mailerUtils->sendWeekNewsEmailMessage($user, $creations, $questions, $plans, $workshops, $howtos, $howtoArticles, $finds, $posts, $woods, $providers, $schools);
						} catch (\Exception $e) {
							$output->writeln('<error>'.$e->getMessage().'</error>');
						}
						if ($verbose) {
							$output->writeln('<fg=cyan>[Done]</fg=cyan>');
						}
					} else {
						if ($verbose) {
							$output->writeln('<fg=cyan>[Fake]</fg=cyan>');
						}
					}
					// Free memory
					$om->detach($user);
				}

				unset($users);
			}

			$progressBar->finish();

			$output->writeln('<comment>[Finished]</comment>');

		}

		// Otherwise, only when the command is destroyed: a run in the same process (tests) would
		// find it locked
		$this->release();

		return 0;

	}

}