<?php

namespace Ladb\CoreBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;

#[AsCommand(name: 'ladb:generate:bodyblockpicturecount', description: 'Generate bodyblockpicturecount')]
class GenerateBodyBlockPictureCountCommand extends Command {

	private $blockBodiedUtils;
	private $om;

	public function __construct(BlockBodiedUtils $blockBodiedUtils, EntityManagerInterface $om) {
		$this->blockBodiedUtils = $blockBodiedUtils;
		$this->om = $om;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:generate:bodyblockpicturecount</info> command generate bodyblockpicturecount
EOT
			);
	}

	private function _process($entityClass, $em, OutputInterface $output, $blockBodiedUtils) {

		$output->write('<info>Retrieve '.$entityClass.'...</info>');

		$queryBuilder = $em->createQueryBuilder();
		$queryBuilder
			->select(array( 'e' ))
			->from($entityClass, 'e')
		;

		try {
			$entities = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$entities = array();
		}

		$output->writeln('<comment> ['.count($entities).' '.$entityClass.']</comment>');

		$entityCount = 0;
		foreach ($entities as $entity) {
			$blockBodiedUtils->preprocessBlocks($entity);
			$entityCount++;
		}
		unset($entities);

		return $entityCount;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');

		$om = $this->om;
		$blockBodiedUtils = $this->blockBodiedUtils;

		$entityCount = 0;

		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Blog\Post::class, $om, $output, $blockBodiedUtils);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Faq\Question::class, $om, $output, $blockBodiedUtils);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Howto\Article::class, $om, $output, $blockBodiedUtils);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Creation::class, $om, $output, $blockBodiedUtils);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Workshop::class, $om, $output, $blockBodiedUtils);

		if ($forced) {
			$om->flush();
			$output->writeln('<info>'.$entityCount.' generated</info>');
		} else {
			$output->writeln('<info>'.$entityCount.' to generate</info>');
		}

		return 0;

	}

}