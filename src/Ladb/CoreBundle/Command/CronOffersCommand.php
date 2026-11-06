<?php

namespace Ladb\CoreBundle\Command;

use DirkGroenen\Pinterest\Pinterest;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Manager\Offer\OfferManager;
use Ladb\CoreBundle\Model\StripableInterface;
use Ladb\CoreBundle\Utils\WebScreenshotUtils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Facebook\FacebookSession;
use Ladb\CoreBundle\Entity\Core\Spotlight;
use Ladb\CoreBundle\Utils\MailerUtils;
use Ladb\CoreBundle\Utils\TypableUtils;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Router;

#[AsCommand(name: 'ladb:cron:offers', description: 'Check offers lifetime')]
class CronOffersCommand extends Command {

	private $mailerUtils;
	private $offerManager;
	private $om;

	public function __construct(
		MailerUtils $mailerUtils,
		OfferManager $offerManager,
		EntityManagerInterface $om
	) {
		$this->mailerUtils = $mailerUtils;
		$this->offerManager = $offerManager;
		$this->om = $om;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:cron:offers</info> check offers lifetime
EOT
			);
	}

	/////

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');
		$verbose = $input->getOption('verbose');

		$om = $this->om;
		$offerManager = $this->offerManager;
		$mailerUtils = $this->mailerUtils;

		// Retrieve OUTDATED offers

		if ($verbose) {
			$output->write('<info>Retriving outdated offers...</info>');
		}

		$retrieveDate = (new \DateTime())->modify('-'.Offer::FULL_LIFETIME);

		// Only the ids, each offer is read in its turn: every flush of the managers and of the
		// listeners walks through all that the entity manager keeps
		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'o.id' ))
			->from(Offer::class, 'o')
			->where('o.publishCount > 0')
			->andWhere('o.changedAt < :retrieveDate')
            ->orderBy('o.changedAt', \SortDirection::Ascending)
			->setParameter('retrieveDate', $retrieveDate)
		;

		try {
			$oudatedOfferIds = $queryBuilder->getQuery()->getSingleColumnResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$oudatedOfferIds = array();
		}

		if ($verbose) {
			$output->writeln('<comment> ['.count($oudatedOfferIds).' outdated offers]</comment>');
		}

		foreach ($oudatedOfferIds as $offerId) {

			if ($verbose) {
				$output->write('Deleting Offer id='.$offerId);
			}

			// Null when deleted since the query
			$offer = $forced ? $om->find(Offer::class, $offerId) : null;
			if (!is_null($offer)) {

				// Delete Offer
				$offerManager->delete($offer, false);

				$om->clear();
			}

			if ($verbose) {
				if ($forced) {
					$output->writeln('<fg=cyan> [OK]</fg=cyan>');
				} else {
					$output->writeln('<fg=cyan> [FAKE]</fg=cyan>');
				}
			}

		}

		// Retrieve EXPIRED offers

		if ($verbose) {
			$output->write('<info>Retriving expired offers...</info>');
		}

		$retrieveDate = (new \DateTime())->modify('-'.Offer::ACTIVE_LIFETIME);

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'o.id' ))
			->from(Offer::class, 'o')
			->where('o.isDraft = false')
			->andWhere('o.changedAt < :retrieveDate')
			->setParameter('retrieveDate', $retrieveDate)
		;

		try {
			$expiredOfferIds = $queryBuilder->getQuery()->getSingleColumnResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$expiredOfferIds = array();
		}

		if ($verbose) {
			$output->writeln('<comment> ['.count($expiredOfferIds).' expired offers]</comment>');
		}

		foreach ($expiredOfferIds as $offerId) {

			if ($verbose) {
				$output->write('Unpublishing Offer id='.$offerId);
			}

			// Null when deleted since the query
			$offer = $forced ? $om->find(Offer::class, $offerId) : null;
			if (!is_null($offer)) {

				// Unpublish offer
				$offerManager->unpublish($offer);

				// Email notification
				$mailerUtils->sendOfferExpiredEmailMessage($offer->getUser(), $offer);

				$om->clear();
			}

			if ($verbose) {
				if ($forced) {
					$output->writeln('<fg=cyan> [OK]</fg=cyan>');
				} else {
					$output->writeln('<fg=cyan> [FAKE]</fg=cyan>');
				}
			}

		}

		return 0;

	}
}