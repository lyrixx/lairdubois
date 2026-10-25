<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Entity\Core\Vote;

class GenerateLicenseVersionCommand extends Command {

	protected static $defaultName = 'ladb:generate:licenseversion';

	private $om;

	public function __construct(EntityManagerInterface $om) {
		$this->om = $om;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setDescription('Generate licenseversion')
			->setHelp(<<<EOT
The <info>ladb:generate:licenseversion</info> command generate licenseversion
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {

		$forced = $input->getOption('force');

		$om = $this->om;

		// Retrieve Licenses

		$output->write('<info>Resetting licenses...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'l' ))
			->from('LadbCoreBundle:Core\License', 'l')
		;

		try {
			$licenses = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$licenses = array();
		}

		$output->writeln('<comment> ['.count($licenses).' licenses]</comment>');

		foreach ($licenses as $license) {
			if (empty($license->getVersion())) {
				$license->setVersion('3.0');
			}
		}

		if ($forced) {
			$om->flush();
		}

	}

}