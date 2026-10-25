<?php

namespace Ladb\CoreBundle\Command;

use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Entity\Core\Block\Text;

class MigrateFindsCommand extends Command {

	protected static $defaultName = 'ladb:migrate:finds';

	private $fieldPreprocessorUtils;
	private $om;

	public function __construct(FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om) {
		$this->fieldPreprocessorUtils = $fieldPreprocessorUtils;
		$this->om = $om;
		parent::__construct();
	}

	private $toTransferCommentables = array();
	private $toTransferVotables = array();

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setDescription('Migrate find body to body blocks')
			->setHelp(<<<EOT
The <info>ladb:migrate:providers</info> command migrate find body to body blocks
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {

		$forced = $input->getOption('force');

		$om = $this->om;
		$fieldPreprocessorUtils = $this->fieldPreprocessorUtils;

		// Retrieve Finds

		$output->write('<info>Retrieve finds...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'f' ))
			->from('LadbCoreBundle:Find\Find', 'f')
		;

		try {
			$finds = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$finds = array();
		}

		$output->writeln('<comment> ['.count($finds).' finds]</comment>');

		foreach ($finds as $find) {

			$output->writeln('<info>Processing <fg=cyan>'.$find->getTitle().'</fg=cyan> ...</info>');

			$body = $find->getBody();

			$block = new Text();
			$block->setBody($body);
			$fieldPreprocessorUtils->preprocessFields($block);

			$find->resetBodyBlocks();
			$find->addBodyBlock($block);


			$output->writeln('<comment>[Done]</comment>');

		}

		if ($forced) {
			$om->flush();
		}

	}

}