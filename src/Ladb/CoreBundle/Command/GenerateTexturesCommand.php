<?php

namespace Ladb\CoreBundle\Command;

use Ladb\CoreBundle\Utils\TextureUtils;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Utils\TypableUtils;

#[AsCommand(name: 'ladb:generate:textures', description: 'Generate textures')]
class GenerateTexturesCommand extends Command {

	private $om;
	private $textureUtils;

	public function __construct(EntityManagerInterface $om, TextureUtils $textureUtils) {
		$this->om = $om;
		$this->textureUtils = $textureUtils;
		parent::__construct();
	}

	protected function configure() {
		$this
			->addOption('force', null, InputOption::VALUE_NONE, 'Force updating')
			->setHelp(<<<EOT
The <info>ladb:generate:textures</info> command generate textures
EOT
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {

		$forced = $input->getOption('force');

		$om = $this->om;
		$textureUtils = $this->textureUtils;

		// Retrieve Woods

		$output->write('<info>Resetting woods...</info>');

		$queryBuilder = $om->createQueryBuilder();
		$queryBuilder
			->select(array( 'w' ))
			->from(\Ladb\CoreBundle\Entity\Knowledge\Wood::class, 'w')
		;

		try {
			$woods = $queryBuilder->getQuery()->getResult();
		} catch (\Doctrine\ORM\NoResultException $e) {
			$woods = array();
		}

		$output->writeln('<comment> ['.count($woods).' woods]</comment>');

		foreach ($woods as $wood) {

			$output->write('<info>Processing '.$wood->getName().' ...</info>');

			// Remove wood textures
			foreach($wood->getTextures() as $texture) {
				$om->remove($texture);
			}
			$wood->setTextureCount(0);

			// Create grain textures
			foreach ($wood->getGrainValues() as $grainValue) {
				try {
					$textureUtils->createTexture($wood, $grainValue, false);
				} catch (\Exception $e) {
					$output->writeln('<error>'.$e->getMessage().'</error>');
				}
			}

			// Create endgrain textures
			foreach ($wood->getEndgrainValues() as $endgrainValue) {
				try {
					$textureUtils->createTexture($wood, $endgrainValue, false);
				} catch (\Exception $e) {
					$output->writeln('<error>'.$e->getMessage().'</error>');
				}
			}

			$output->writeln('<comment> ['.$wood->getTextureCount().' textures]</comment>');

		}

		if ($forced) {
			$om->flush();
		}

		return 0;

	}

}