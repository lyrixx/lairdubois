<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\Join;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Manager\Event\EventManager;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;

/**
 * An event (id 1) of "user", in a month, joined by "user2".
 */
final class EventFixtures extends Fixture implements DependentFixtureInterface {

	private $activityUtils;
	private $eventManager;
	private $factory;
	private $watchableUtils;

	public function __construct(
		ActivityUtils $activityUtils,
		EventManager $eventManager,
		FixtureFactory $factory,
		WatchableUtils $watchableUtils
	) {
		$this->activityUtils = $activityUtils;
		$this->eventManager = $eventManager;
		$this->factory = $factory;
		$this->watchableUtils = $watchableUtils;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager) {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		// As EventController::createAction, with the dates computed by EventType

		$this->factory->actAs($user);

		$event = new Event();
		$event->setTitle('portes ouvertes de l\'atelier');
		$event->addBodyBlock($this->factory->textBlock('Venez découvrir l\'atelier et les machines, **entrée libre**.'));
		$event->addPicture($this->factory->picture($user, 'wood-6.jpg', 'event'));
		$event->setStartDate(new \DateTime('today +1 month'));
		$event->setStartTime(new \DateTime('10:00'));
		$event->setEndDate(new \DateTime('today +1 month'));
		$event->setEndTime(new \DateTime('18:00'));
		$event->setStartAt((clone $event->getStartDate())->setTime(10, 0));
		$event->setEndAt((clone $event->getEndDate())->setTime(18, 0));
		$event->setLocation('Lyon, France');
		$event->setLatitude(45.764043);
		$event->setLongitude(4.835659);
		foreach ($this->factory->tags('portes ouvertes,atelier') as $tag) {
			$event->addTag($tag);
		}
		$this->factory->preprocess($event);
		$event->setUser($user);
		$event->setMainPicture($event->getPictures()->first());
		$user->getMeta()->incrementPrivateEventCount();
		$this->factory->create($event);

		$this->eventManager->publish($event);

		$this->factory->actAs($user2);
		$this->factory->comment($user2, $event, 'J\'y serai !');
		$this->factory->like($user2, $event);
		$this->join($manager, $event);
	}

	/**
	 * As JoinController::createAction, for "user2".
	 */
	private function join(ObjectManager $manager, Event $event): void {
		$user2 = $this->getReference(UserFixtures::USER2);

		$event->incrementJoinCount();

		$join = new Join();
		$join->setEntityType($event->getType());
		$join->setEntityId($event->getId());
		$join->setUser($user2);
		$manager->persist($join);

		$this->activityUtils->createJoinActivity($join, false);

		$event->setChangedAt(new \DateTime());
		$this->factory->dispatch(PublicationListener::PUBLICATION_CHANGED, $event);

		$this->watchableUtils->autoCreateWatch($event, $user2);

		$manager->flush();
	}

}
