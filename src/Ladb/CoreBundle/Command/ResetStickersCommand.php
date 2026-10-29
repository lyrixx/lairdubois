<?php

namespace Ladb\CoreBundle\Command;

use Ladb\CoreBundle\Utils\EmbeddableUtils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ladb:reset:stickers', description: 'Reset stickers')]
class ResetStickersCommand extends Command {

	private $embeddableUtils;
	private $om;

	public function __construct(EmbeddableUtils $embeddableUtils, EntityManagerInterface $om) {
		$this->embeddableUtils = $embeddableUtils;
		$this->om = $om;
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force reseting')
			->setHelp(<<<EOT
The <info>ladb:reset:stickers</info> command clear all stickers
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$om = $this->om;
		$embaddableUtils = $this->embeddableUtils;

		$resetStickerCount = 0;

		// Check creations /////

		$output->writeln('<info>Checking creations...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'c', 's' ))
			->from(\Ladb\CoreBundle\Entity\Wonder\Creation::class, 'c')
			->innerJoin('c.sticker', 's')
		;

		try {
			$creations = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$creations = array();
		}

		foreach ($creations as $creation) {
			$resetStickerCount++;
			$embaddableUtils->resetSticker($creation);
		}
		unset($creations);

		// Check plans /////

		$output->writeln('<info>Checking plans...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'p', 's' ))
			->from(\Ladb\CoreBundle\Entity\Wonder\Plan::class, 'p')
			->innerJoin('p.sticker', 's')
		;

		try {
			$plans = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$plans = array();
		}

		foreach ($plans as $plan) {
			$resetStickerCount++;
			$embaddableUtils->resetSticker($plan);
		}
		unset($plans);

		// Check workshops /////

		$output->writeln('<info>Checking workshops...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'w', 's' ))
			->from(\Ladb\CoreBundle\Entity\Wonder\Workshop::class, 'w')
			->innerJoin('w.sticker', 's')
		;

		try {
			$workshops = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$workshops = array();
		}

		foreach ($workshops as $workshop) {
			$resetStickerCount++;
			$embaddableUtils->resetSticker($workshop);
		}
		unset($workshops);

		// Check howtos /////

		$output->writeln('<info>Checking howtos...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'h', 's' ))
			->from(\Ladb\CoreBundle\Entity\Howto\Howto::class, 'h')
			->innerJoin('h.sticker', 's')
		;

		try {
			$howtos = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$howtos = array();
		}

		foreach ($howtos as $howto) {
			$resetStickerCount++;
			$embaddableUtils->resetSticker($howto);
		}
		unset($howtos);

		// Check articles /////

		$output->writeln('<info>Checking articles...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'a', 's' ))
			->from(\Ladb\CoreBundle\Entity\Howto\Article::class, 'a')
			->innerJoin('a.sticker', 's')
		;

		try {
			$articles = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$articles = array();
		}

		foreach ($articles as $article) {
			$resetStickerCount++;
			$embaddableUtils->resetSticker($article);
		}
		unset($articles);

		// Cleanup /////

		$forced = $input->getOption('force');

		if ($forced) {
			if ($resetStickerCount > 0) {
				$om->flush();
			}
			$output->writeln('<info>'.$resetStickerCount.' stickers reset</info>');
		} else {
			$output->writeln('<info>'.$resetStickerCount.' stickers to reset</info>');
		}

		return 0;
	}

}