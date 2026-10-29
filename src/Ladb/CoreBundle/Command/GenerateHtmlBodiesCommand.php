<?php

namespace Ladb\CoreBundle\Command;

use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Ladb\CoreBundle\Model\BodiedInterface;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;

#[AsCommand(name: 'ladb:generate:htmlbodies', description: 'Generate htmlbodies')]
class GenerateHtmlBodiesCommand extends Command {

	private $fieldPreprocessorUtils;
	private $om;

	public function __construct(FieldPreprocessorUtils $fieldPreprocessorUtils, EntityManagerInterface $om) {
		$this->fieldPreprocessorUtils = $fieldPreprocessorUtils;
		$this->om = $om;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:generate:htmlbodies</info> command generate htmlbodies
EOT
			);
	}

	private function _process($entityClass, $em, OutputInterface $output, $filedProcessoUtils, $om, $forced) {

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
			$filedProcessoUtils->preprocessFields($entity);
			$entityCount++;
		}

		if ($forced) {
			$om->flush();
		}
		unset($entities);

		return $entityCount;
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');

		$om = $this->om;
		$filedProcessoUtils = $this->fieldPreprocessorUtils;

		$entityCount = 0;

		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Message\Message::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Core\Comment::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Creation::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Workshop::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Wonder\Plan::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Blog\Post::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Faq\Question::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Promotion\Graphic::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Find\Find::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Howto\Howto::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Howto\Article::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Workflow\Workflow::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Qa\Question::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Qa\Answer::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Event\Event::class, $om, $output, $filedProcessoUtils, $om, $forced);
		$entityCount += $this->_process(\Ladb\CoreBundle\Entity\Offer\Offer::class, $om, $output, $filedProcessoUtils, $om, $forced);

		if ($forced) {
			$output->writeln('<info>'.$entityCount.' generated</info>');
		} else {
			$output->writeln('<info>'.$entityCount.' to generate</info>');
		}

		return 0;

	}

}