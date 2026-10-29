<?php

namespace Ladb\CoreBundle\Command;

use DirkGroenen\Pinterest\Pinterest;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Wonder\Creation;
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

#[AsCommand(name: 'ladb:cron:workflow:thumbnails', description: 'Update workflow thumbnails')]
class CronWorkflowThumbnailCommand extends Command {

	private $om;
	private $router;
	private $webScreenshotUtils;

	public function __construct(
		EntityManagerInterface $om,
		UrlGeneratorInterface $router,
		WebScreenshotUtils $webScreenshotUtils
	) {
		$this->om = $om;
		$this->router = $router;
		$this->webScreenshotUtils = $webScreenshotUtils;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:cron:workflow:thumbnails</info> update workflow thumbnails
EOT
			);
	}

	/////

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');
		$verbose = $input->getOption('verbose');

		$om = $this->om;
		$webScreenshotUtils = $this->webScreenshotUtils;
		$router = $this->router;

		// Retrieve workflows

		if ($verbose) {
			$output->write('<info>Retriving workflows...</info>');
		}

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'w', 'mp' ))
			->from(\Ladb\CoreBundle\Entity\Workflow\Workflow::class, 'w')
			->leftJoin('w.mainPicture', 'mp')
			->where('w.mainPicture IS NULL')
			->orWhere('w.updatedAt > mp.createdAt')
		;

		try {
			$workflows = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$workflows = array();
		}

		if ($verbose) {
			$output->writeln('<comment> ['.count($workflows).' workflows]</comment>');
		}

		foreach ($workflows as $workflow) {

			$url = $router->generate('core_workflow_internal_diagram', array( 'id' => $workflow->getId() ), UrlGeneratorInterface::ABSOLUTE_URL);

			if ($verbose) {
				$output->write('<info> Capturing ['.$url.'] ...</info>');
			}

			$mainPicture = $webScreenshotUtils->captureToPicture($url, 600, 600, 600, 600, 3);
			$workflow->setMainPicture($mainPicture);

			if ($verbose) {
				if ($forced) {
					$output->writeln('<fg=cyan> [OK]</fg=cyan>');
				} else {
					$output->writeln('<fg=cyan> [FAKE]</fg=cyan>');
				}
			}

		}

		if ($forced) {
			$om->flush();
		}

		return 0;

	}
}