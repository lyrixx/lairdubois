<?php

namespace Ladb\CoreBundle\Command;

use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Ladb\CoreBundle\Manager\Wonder\CreationManager;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Model\MultiPicturedInterface;
use Ladb\CoreBundle\Model\PublicationInterface;
use Ladb\CoreBundle\Utils\TypableUtils;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeOwnerCommand extends Command {

	protected static $defaultName = 'ladb:change:owner';

	private $om;
	private $typableUtils;
	private $userManager;

	public function __construct(
		EntityManagerInterface $om,
		TypableUtils $typableUtils,
		UserManager $userManager
	) {
		$this->om = $om;
		$this->typableUtils = $typableUtils;
		$this->userManager = $userManager;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('target-username', null, InputOption::VALUE_REQUIRED, 'Target username')
			->addOption('entity-type', null, InputOption::VALUE_REQUIRED, 'Entity type')
			->addOption('entity-id', null, InputOption::VALUE_REQUIRED, 'Entity ID')
			->addOption('force', null, InputOption::VALUE_NONE, 'Force removing')
			->setDescription('Change owner')
			->setHelp(<<<EOT
The <info>ladb:change:owner</info> command change owner
EOT
		);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');
		$targetUsername = $input->getOption('target-username');
		$entityType = $input->getOption('entity-type');
		$entityId = $input->getOption('entity-id');

		$om = $this->om;

		// Retrieve target user ////

		$userManager = $this->userManager;
		$targetUser = $userManager->findUserByUsername($targetUsername);

		if (is_null($targetUser)) {
			$output->writeln('<error>Unknow unsername='.$targetUsername.'</error>', 0);
			return 0;
		}

		// Retrieve creation ////

		$typableUtils = $this->typableUtils;
		$typable = $typableUtils->findTypable($entityType, $entityId);

		$output->write('<info>Changing Owner of typable="'.$typable->getTitle().'" to targetUser='.$targetUser->getDisplayName().'...</info>');

		$manager = $typableUtils->getManagerByType($entityType);
		if (!is_null($manager)) {
			$manager->changeOwner($typable, $targetUser, false);
		}

		if ($forced) {
			$om->flush();
			$output->writeln('<fg=cyan>[Done]</fg=cyan>');
		} else {
			$output->writeln('<fg=cyan>[Fake]</fg=cyan>');
		}

		return 0;

	}

}