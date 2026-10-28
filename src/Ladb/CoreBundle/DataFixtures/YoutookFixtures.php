<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Youtook\Took;
use Ladb\CoreBundle\Utils\VideoHostingUtils;

/**
 * A took (id 1) of "user", with the data NewTookType fetches from YouTube written by hand.
 */
final class YoutookFixtures extends Fixture implements DependentFixtureInterface {

	private $factory;

	public function __construct(
		FixtureFactory $factory
	) {
		$this->factory = $factory;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager): void {
		$user = $this->getReference(UserFixtures::USER);

		// As YoutookController::createAction

		$this->factory->actAs($user);

		$took = new Took();
		$took->setUrl('https://www.youtube.com/watch?v=aqz-KE-bpKQ');
		$took->setKind(VideoHostingUtils::KIND_YOUTUBE);
		$took->setEmbedIdentifier('aqz-KE-bpKQ');
		$took->setThumbnailUrl('https://i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg');
		$took->setMainPicture($this->factory->picture($user, 'wood-4.jpg', 'took'));
		$took->setTitle('Fabriquer un maillet en frêne');
		$took->setBody('Une vidéo pas à pas pour fabriquer son maillet.');
		$took->setChannelId('UCfixtures');
		$took->setChannelThumbnailUrl('https://i.ytimg.com/vi/aqz-KE-bpKQ/default.jpg');
		$took->setChannelTitle('L\'Air du Bois');
		$this->factory->preprocess($took);
		$took->setUser($user);
		$this->factory->create($took);
	}

}
