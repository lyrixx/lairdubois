<?php

namespace Ladb\CoreBundle\Command;

use Ladb\CoreBundle\Entity\Core\TagUsage;
use Ladb\CoreBundle\Model\TaggableInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ladb:generate:tagusages', description: 'Generate TagUsages')]
class GenerateTagUsagesCommand extends Command {

	private $om;

	public function __construct(EntityManagerInterface $om) {
		$this->om = $om;
		parent::__construct();
	}

	protected function configure() {
		$this
			->setHelp(<<<EOT
The <info>ladb:generate:tagusages</info> command generate tag usages
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$om = $this->om;

		// Check creations /////

		$output->writeln('<info>Checking creations...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'c', 't' ))
			->from('LadbCoreBundle:Wonder\Creation', 'c')
			->leftJoin('c.tags', 't')
		;

		try {
			$creations = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($creations as $creation) {
			$this->_generateTagUsages($creation);
		}

		// Check Plans /////

		$output->writeln('<info>Checking plans...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'p', 't' ))
			->from('LadbCoreBundle:Wonder\Plan', 'p')
			->leftJoin('p.tags', 't')
		;

		try {
			$plans = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($plans as $plan) {
			$this->_generateTagUsages($plan);
		}

		// Check Workshops /////

		$output->writeln('<info>Checking workshops...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'w', 't' ))
			->from('LadbCoreBundle:Wonder\Workshop', 'w')
			->leftJoin('w.tags', 't')
		;

		try {
			$workshops = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($workshops as $workshop) {
			$this->_generateTagUsages($workshop);
		}

		// Check Howtos /////

		$output->writeln('<info>Checking howtos...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'h', 't' ))
			->from('LadbCoreBundle:Howto\Howto', 'h')
			->leftJoin('h.tags', 't')
		;

		try {
			$howtos = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($howtos as $howto) {
			$this->_generateTagUsages($howto);
		}

		// Check Finds /////

		$output->writeln('<info>Checking finds...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'f', 't' ))
			->from('LadbCoreBundle:Find\Find', 'f')
			->leftJoin('f.tags', 't')
		;

		try {
			$finds = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($finds as $find) {
			$this->_generateTagUsages($find);
		}

		// Check Posts /////

		$output->writeln('<info>Checking posts...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'p', 't' ))
			->from('LadbCoreBundle:Blog\Post', 'p')
			->leftJoin('p.tags', 't')
		;

		try {
			$posts = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			return 0;
		}

		foreach ($posts as $post) {
			$this->_generateTagUsages($post);
		}

		return 0;

	}

	private function _generateTagUsages(TaggableInterface $taggable) {
		$om = $this->om;
		$tagUsageRepository = $om->getRepository(TagUsage::CLASS_NAME);
		foreach ($taggable->getTags() as $tag) {
			$tagUsage = $tagUsageRepository->findOneByTagAndEntityType($tag, $taggable->getType());
			if (is_null($tagUsage)) {
				$tagUsage = new TagUsage();
				$tagUsage->setTag($tag);
				$tagUsage->setEntityType($taggable->getType());
				$om->persist($tagUsage);
			}
			$tagUsage->incrementScore();
			$om->flush();
		}
	}

}