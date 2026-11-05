<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\ORM\EntityManagerInterface;
use FOS\ElasticaBundle\Doctrine\Listener;
use Ladb\CoreBundle\Entity\Blog\Post;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Entity\Core\Feedback;
use Ladb\CoreBundle\Entity\Core\Tip;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Entity\Faq\Question as FaqQuestion;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Knowledge\Software;
use Ladb\CoreBundle\Entity\Knowledge\Tool;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Entity\Youtook\Took;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A big set of data on top of the fixtures, to see the site with volume and to measure MariaDB and
 * Elasticsearch: castor app:db:fixtures-volume. Not a fixture on purpose: doctrine:fixtures:load
 * loads every fixture, those of a group included, which would land in the tests and the CI.
 *
 * Replaying the controllers as FixtureFactory does takes 44 ms per content: the contents are
 * written by batches, without the events, and the interactions in SQL (VolumeInteractions).
 */
#[AsCommand(name: 'ladb:fixtures:volume', description: 'Adds a big set of data on top of the fixtures')]
final class VolumeFixturesCommand extends Command {

	private const BATCH_SIZE = 200;

	public function __construct(
		private readonly VolumeContentFactory $contentFactory,
		private readonly VolumeInteractions $interactions,
		private readonly EntityManagerInterface $om,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->addOption('count', null, InputOption::VALUE_REQUIRED, 'Items per entity', '10000');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$count = (int) $input->getOption('count');
		if ($count < 1) {
			throw new InvalidOptionException('The option --count must be a positive integer.');
		}

		$this->disableIndexing();

		$start = microtime(true);
		$contents = $this->contentFactory;
		$contents->prepare($count);

		// The likes of a content come from different accounts: at least as many as the likes
		$userCount = max($count, VolumeInteractions::MAX_PER_CONTENT);
		$firstUserId = $this->load($output, User::class, $userCount, $contents->user(...));
		$contents->useUsers($firstUserId, $userCount);

		$firstCreationId = $this->load($output, Creation::class, $count, $contents->creation(...));
		$firstIds = array(
			Creation::class => $firstCreationId,
			Workshop::class => $this->load($output, Workshop::class, $count, $contents->workshop(...)),
			Plan::class => $this->load($output, Plan::class, $count, $contents->plan(...)),
			Howto::class => $this->load($output, Howto::class, $count, $contents->howto(...)),
			Find::class => $this->load($output, Find::class, $count, $contents->find(...)),
			Event::class => $this->load($output, Event::class, $count, $contents->event(...)),
			Offer::class => $this->load($output, Offer::class, $count, $contents->offer(...)),
			Question::class => $this->load($output, Question::class, $count, $contents->question(...), $contents->answer(...)),
			Workflow::class => $this->load($output, Workflow::class, $count, $contents->workflow(...)),
			Collection::class => $this->load($output, Collection::class, $count, static fn (int $i): Collection => $contents->collection($i, $firstCreationId)),
			Graphic::class => $this->load($output, Graphic::class, $count, $contents->graphic(...)),
			Post::class => $this->load($output, Post::class, $count, $contents->post(...)),
			FaqQuestion::class => $this->load($output, FaqQuestion::class, $count, $contents->faqQuestion(...)),
			Tip::class => $this->load($output, Tip::class, $count, $contents->tip(...)),
			Took::class => $this->load($output, Took::class, $count, $contents->took(...)),
			Wood::class => $this->load($output, Wood::class, $count, $contents->wood(...), $contents->woodValues(...)),
			Provider::class => $this->load($output, Provider::class, $count, $contents->provider(...), $contents->providerValues(...)),
			School::class => $this->load($output, School::class, $count, $contents->school(...), $contents->schoolValues(...)),
			Book::class => $this->load($output, Book::class, $count, $contents->book(...), $contents->bookValues(...)),
			Software::class => $this->load($output, Software::class, $count, $contents->software(...), $contents->softwareValues(...)),
			Tool::class => $this->load($output, Tool::class, $count, $contents->tool(...), $contents->toolValues(...)),
		);
		$this->load($output, Feedback::class, $count, static fn (int $i): Feedback => $contents->feedback($i, $firstCreationId + $i - 1));
		$this->load($output, Thread::class, $count, $contents->thread(...));

		$output->writeln(sprintf('Contents: %.1f s', microtime(true) - $start));

		$this->interactions->create($firstIds, $firstUserId, $userCount);

		$output->writeln(sprintf('<info>Done in %.1f s, peak memory %d MB.</info>', microtime(true) - $start, memory_get_peak_usage(true) / 1048576));

		return Command::SUCCESS;
	}

	/**
	 * The ids of a class are contiguous: nothing else writes in its tables during the load.
	 *
	 * @param class-string $class
	 * @param callable(int): object $build
	 * @param null|callable(object): void $complete what needs the id of the entity
	 * @return int the id of the first entity
	 */
	private function load(OutputInterface $output, string $class, int $count, callable $build, ?callable $complete = null): int {
		$start = microtime(true);
		$firstId = null;
		for ($i = 1; $i <= $count; $i += self::BATCH_SIZE) {
			$batch = array();
			for ($j = $i; $j < $i + self::BATCH_SIZE && $j <= $count; $j++) {
				$entity = $build($j);
				$this->om->persist($entity);
				$batch[] = $entity;
			}
			$this->om->flush();
			$firstId ??= $batch[0]->getId();

			if (null !== $complete) {
				foreach ($batch as $entity) {
					$complete($entity);
				}
				$this->om->flush();
			}
			$this->om->clear();
		}
		$output->writeln(sprintf('%s: %d in %.1f s, %d MB', $class, $count, microtime(true) - $start, memory_get_usage(true) / 1048576));

		return $firstId;
	}

	/**
	 * The listeners of FOSElasticaBundle would index each account, tag and input as it is created:
	 * fos:elastica:populate does it after the load, in one go.
	 */
	private function disableIndexing(): void {
		$eventManager = $this->om->getEventManager();
		foreach ($eventManager->getAllListeners() as $event => $listeners) {
			foreach ($listeners as $listener) {
				if ($listener instanceof Listener) {
					$eventManager->removeEventListener($event, $listener);
				}
			}
		}
	}

}
