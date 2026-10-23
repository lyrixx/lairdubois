<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\License;
use Ladb\CoreBundle\Entity\Core\Resource;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Ladb\CoreBundle\Manager\Promotion\GraphicManager;
use Ladb\CoreBundle\Utils\GraphicUtils;

/**
 * A graphic (id 1) of "user".
 */
final class PromotionFixtures extends Fixture implements DependentFixtureInterface {

	private $factory;
	private $graphicManager;
	private $graphicUtils;

	public function __construct(
		FixtureFactory $factory,
		GraphicManager $graphicManager,
		GraphicUtils $graphicUtils
	) {
		$this->factory = $factory;
		$this->graphicManager = $graphicManager;
		$this->graphicUtils = $graphicUtils;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager) {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		// As GraphicController::createAction

		$this->factory->actAs($user);

		$graphic = new Graphic();
		$graphic->setTitle('affiche de l\'atelier');
		$graphic->setBody('L\'affiche des portes ouvertes, libre de droits.');
		$graphic->setResource($this->factory->resource($user, 'plan.svg', 'graphic', Resource::KIND_SVG, $this->factory->picture($user, 'wood-2.jpg', 'graphic')));
		$graphic->setLicense(new License());
		foreach ($this->factory->tags('affiche') as $tag) {
			$graphic->addTag($tag);
		}
		$this->factory->preprocess($graphic);
		$graphic->setUser($user);
		$graphic->setMainPicture($graphic->getResource()->getThumbnail());
		$user->getMeta()->incrementPrivateGraphicCount();
		$this->factory->create($graphic);
		$this->graphicUtils->createZipArchive($graphic);
		$manager->flush();

		$this->graphicManager->publish($graphic);

		$this->factory->actAs($user2);
		$this->factory->comment($user2, $graphic, 'Je la partage !');
		$this->factory->like($user2, $graphic);
	}

}
