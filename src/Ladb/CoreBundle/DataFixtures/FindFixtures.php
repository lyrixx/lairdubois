<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Find\Content\Gallery;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Manager\Find\FindManager;
use Ladb\CoreBundle\Utils\FindUtils;

/**
 * A find (id 1) of "user": a gallery, the other kinds (website, video) fetch their picture online.
 */
final class FindFixtures extends Fixture implements DependentFixtureInterface {

	private $factory;
	private $findManager;
	private $findUtils;

	public function __construct(
		FixtureFactory $factory,
		FindManager $findManager,
		FindUtils $findUtils
	) {
		$this->factory = $factory;
		$this->findManager = $findManager;
		$this->findUtils = $findUtils;
	}

	public function getDependencies(): array {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$user = $this->getReference(UserFixtures::USER, User::class);
		$user2 = $this->getReference(UserFixtures::USER2, User::class);

		// As FindController::createAction

		$this->factory->actAs($user);

		$gallery = new Gallery();
		$gallery->addPicture($this->factory->picture($user, 'wood-5.jpg', 'find'));
		$gallery->setLocation('Annecy, France');
		$gallery->setLatitude(45.899247);
		$gallery->setLongitude(6.129384);

		$find = new Find();
		$find->setTitle('un banc sculpté au bord du lac');
		$find->addBodyBlock($this->factory->textBlock('Repéré pendant une balade, tout en **châtaignier**.'));
		$find->setContentType(Find::CONTENT_TYPE_GALLERY);
		$find->setContent($gallery);
		$find->setKind(Find::KIND_GALLERY);
		foreach ($this->factory->tags('banc,sculpture') as $tag) {
			$find->addTag($tag);
		}
		$this->factory->preprocess($find);
		$this->findUtils->generateMainPicture($find);
		$find->setUser($user);
		$user->getMeta()->incrementPrivateFindCount();
		$this->factory->create($find);

		$this->findManager->publish($find);

		$this->factory->actAs($user2);
		$this->factory->comment($user2, $find, 'Magnifique trouvaille.');
		$this->factory->like($user2, $find);
	}

}
