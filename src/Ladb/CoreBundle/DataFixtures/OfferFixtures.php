<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Manager\Offer\OfferManager;

/**
 * An offer (id 1) of "user".
 */
final class OfferFixtures extends Fixture implements DependentFixtureInterface {

	private $factory;
	private $offerManager;

	public function __construct(
		FixtureFactory $factory,
		OfferManager $offerManager
	) {
		$this->factory = $factory;
		$this->offerManager = $offerManager;
	}

	public function getDependencies(): array {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$user = $this->getReference(UserFixtures::USER, User::class);
		$user2 = $this->getReference(UserFixtures::USER2, User::class);

		// As OfferController::createAction, with the price formatted by OfferType

		$this->factory->actAs($user);

		$offer = new Offer();
		$offer->setTitle('établi en hêtre');
		$offer->addBodyBlock($this->factory->textBlock('Établi de 2 m avec presse avant, **bon état**.'));
		$offer->addPicture($this->factory->picture($user, 'wood-3.jpg', 'offer'));
		$offer->setKind(Offer::KIND_OFFER);
		$offer->setCategory(Offer::CATEGORY_TOOL);
		$offer->setRawPrice(250);
		$offer->setCurrency('EUR');
		$offer->setPrice('250 €');
		$offer->setLocation('Lyon, France');
		$offer->setLatitude(45.764043);
		$offer->setLongitude(4.835659);
		foreach ($this->factory->tags('établi') as $tag) {
			$offer->addTag($tag);
		}
		$this->factory->preprocess($offer);
		$offer->setUser($user);
		$offer->setMainPicture($offer->getPictures()->first());
		$user->getMeta()->incrementPrivateOfferCount();
		$this->factory->create($offer);

		$this->offerManager->publish($offer);

		$this->factory->actAs($user2);
		$this->factory->comment($user2, $offer, 'Toujours disponible ?');
		$this->factory->like($user2, $offer);
	}

}
