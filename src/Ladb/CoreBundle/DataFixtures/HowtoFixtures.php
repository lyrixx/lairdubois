<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\License;
use Ladb\CoreBundle\Entity\Howto\Article;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Manager\Howto\HowtoManager;

/**
 * A howto (id 1) of "user", with one published article (id 1).
 */
final class HowtoFixtures extends Fixture implements DependentFixtureInterface {

	public const HOWTO = 'howto';

	private $factory;
	private $howtoManager;

	public function __construct(
		FixtureFactory $factory,
		HowtoManager $howtoManager
	) {
		$this->factory = $factory;
		$this->howtoManager = $howtoManager;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager) {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		// As HowtoController::createAction

		$this->factory->actAs($user);

		$howto = new Howto();
		$howto->setTitle('assembler à queues d\'aronde');
		$howto->setBody('Les étapes pour tracer, scier et ajuster un assemblage à **queues d\'aronde**.');
		$howto->setMainPicture($this->factory->picture($user, 'wood-3.jpg', 'howto'));
		$howto->setLicense(new License());
		foreach ($this->factory->tags('assemblage,queue d\'aronde') as $tag) {
			$howto->addTag($tag);
		}
		$this->factory->preprocess($howto);
		$howto->setUser($user);
		$user->getMeta()->incrementPrivateHowtoCount();
		$this->factory->create($howto);

		// As ArticleController::createAction: the articles of a draft howto are published with it

		$article = new Article();
		$article->setHowto($howto);
		$article->setTitle('tracer les queues');
		$article->addBodyBlock($this->factory->textBlock('Tracer au trusquin la profondeur, puis les queues à la fausse équerre.'));
		$article->addBodyBlock($this->factory->galleryBlock(array( $this->factory->picture($user, 'wood-4.jpg', 'howto-article') )));
		$this->factory->preprocess($article);
		$article->setSortIndex($howto->getArticleMaxSortIndex() + 1);
		$howto->addArticle($article);
		$article->setIsDraft(false);
		$howto->incrementPublishedArticleCount();
		$manager->persist($article);
		$manager->flush();

		$this->howtoManager->publish($howto);

		$this->factory->actAs($user2);
		$this->factory->comment($user2, $howto, 'Très clair, merci !');
		$this->factory->like($user2, $howto);

		$this->addReference(self::HOWTO, $howto);
	}

}
