<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Manager\Collection\CollectionManager;
use Ladb\CoreBundle\Utils\CollectionnableUtils;

/**
 * A collection (id 1) of "user", with her creation, plan and howto.
 */
final class CollectionFixtures extends Fixture implements DependentFixtureInterface {

	private $collectionManager;
	private $collectionnableUtils;
	private $factory;

	public function __construct(
		CollectionManager $collectionManager,
		CollectionnableUtils $collectionnableUtils,
		FixtureFactory $factory
	) {
		$this->collectionManager = $collectionManager;
		$this->collectionnableUtils = $collectionnableUtils;
		$this->factory = $factory;
	}

	public function getDependencies() {
		return array( HowtoFixtures::class, UserFixtures::class, WonderFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		// As CollectionController::createAction

		$this->factory->actAs($user);

		$collection = new Collection();
		$collection->setTitle('mes meubles de salon');
		$collection->setBody('Tout ce qu\'il faut pour meubler un salon en **bois massif**.');
		$collection->setMainPicture($this->factory->picture($user, 'wood-1.jpg', 'collection'));
		foreach ($this->factory->tags('salon,meuble') as $tag) {
			$collection->addTag($tag);
		}
		$this->factory->preprocess($collection);
		$collection->setUser($user);
		$user->getMeta()->incrementPrivateCollectionCount();
		$this->factory->create($collection);

		foreach (array( WonderFixtures::CREATION, WonderFixtures::PLAN, HowtoFixtures::HOWTO ) as $reference) {
			$this->collectionnableUtils->createEntry($this->getReference($reference), $collection);
		}
		$manager->flush();

		$this->collectionManager->publish($collection);

		$this->factory->actAs($user2);
		$this->factory->comment($user2, $collection, 'Belle sélection.');
		$this->factory->like($user2, $collection);
	}

}
