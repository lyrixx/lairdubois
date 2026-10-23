<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Knowledge\Software;
use Ladb\CoreBundle\Entity\Knowledge\Tool;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Ladb\CoreBundle\Entity\Knowledge\Value\BookIdentity;
use Ladb\CoreBundle\Entity\Knowledge\Value\Picture;
use Ladb\CoreBundle\Entity\Knowledge\Value\Sign;
use Ladb\CoreBundle\Entity\Knowledge\Value\SoftwareIdentity;
use Ladb\CoreBundle\Entity\Knowledge\Value\Text;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;

/**
 * One knowledge of each type (id 1), created by "user" with its mandatory values, as the
 * create actions of the knowledge controllers do. "user2" comments, likes and reviews them.
 */
final class KnowledgeFixtures extends Fixture implements DependentFixtureInterface {

	public const WOOD = 'knowledge-wood';
	public const PROVIDER = 'knowledge-provider';
	public const SCHOOL = 'knowledge-school';
	public const BOOK = 'knowledge-book';
	public const SOFTWARE = 'knowledge-software';
	public const TOOL = 'knowledge-tool';

	private $activityUtils;
	private $factory;
	private $watchableUtils;

	public function __construct(
		ActivityUtils $activityUtils,
		FixtureFactory $factory,
		WatchableUtils $watchableUtils
	) {
		$this->activityUtils = $activityUtils;
		$this->factory = $factory;
		$this->watchableUtils = $watchableUtils;
	}

	public function getDependencies() {
		return array( UserFixtures::class );
	}

	public function load(ObjectManager $manager) {
		$user = $this->getReference(UserFixtures::USER);
		$user2 = $this->getReference(UserFixtures::USER2);

		$this->factory->actAs($user);

		// Wood

		$name = $this->text('Chêne');
		$grain = $this->picture($user, 'wood-1.jpg', 'wood-grain');
		$wood = new Wood();
		$wood->setName($name->getData());
		$this->persistKnowledge($manager, $wood);
		$wood->addNameValue($name);
		$wood->addGrainValue($grain);
		$this->publishKnowledge($manager, $wood, $user, array( Wood::FIELD_NAME => $name, Wood::FIELD_GRAIN => $grain ));

		// Provider

		$sign = new Sign();
		$sign->setBrand('Scierie du Lac');
		$sign->setStore('Annecy');
		$sign->setIsAffiliate(false);
		$sign->setData($sign->getBrand().','.$sign->getStore());
		$logo = $this->picture($user, 'wood-2.jpg', 'provider-logo');
		$provider = new Provider();
		$provider->setSign($sign->getData());
		$provider->setBrand($sign->getBrand());
		$provider->setStore($sign->getStore());
		$this->persistKnowledge($manager, $provider);
		$provider->addSignValue($sign);
		$provider->addLogoValue($logo);
		$this->publishKnowledge($manager, $provider, $user, array( Provider::FIELD_SIGN => $sign, Provider::FIELD_LOGO => $logo ));

		// School

		$name = $this->text('École du Bois');
		$logo = $this->picture($user, 'wood-3.jpg', 'school-logo');
		$school = new School();
		$school->setName($name->getData());
		$this->persistKnowledge($manager, $school);
		$school->addNameValue($name);
		$school->addLogoValue($logo);
		$this->publishKnowledge($manager, $school, $user, array( School::FIELD_NAME => $name, School::FIELD_LOGO => $logo ));

		// Book

		$identity = new BookIdentity();
		$identity->setWork('Le Manuel du menuisier');
		$identity->setIsVolume(false);
		$identity->setData($identity->getWork());
		$cover = $this->picture($user, 'wood-4.jpg', 'book-cover');
		$book = new Book();
		$book->setTitle($identity->getData());
		$this->persistKnowledge($manager, $book);
		$book->addIdentityValue($identity);
		$book->addCoverValue($cover);
		$this->publishKnowledge($manager, $book, $user, array( Book::FIELD_IDENTITY => $identity, Book::FIELD_COVER => $cover ));

		// Software

		$identity = new SoftwareIdentity();
		$identity->setName('OpenCutList');
		$identity->setIsAddOn(false);
		$identity->setData($identity->getName());
		$icon = $this->picture($user, 'wood-5.jpg', 'software-icon');
		$software = new Software();
		$software->setTitle($identity->getData());
		$this->persistKnowledge($manager, $software);
		$software->addIdentityValue($identity);
		$software->addIconValue($icon);
		$this->publishKnowledge($manager, $software, $user, array( Software::FIELD_IDENTITY => $identity, Software::FIELD_ICON => $icon ));

		// Tool

		$name = $this->text('Rabot');
		$photo = $this->picture($user, 'wood-6.jpg', 'tool-photo');
		$tool = new Tool();
		$tool->setTitle($name->getData());
		$this->persistKnowledge($manager, $tool);
		$tool->addNameValue($name);
		$tool->addPhotoValue($photo);
		$this->publishKnowledge($manager, $tool, $user, array( Tool::FIELD_NAME => $name, Tool::FIELD_PHOTO => $photo ));

		// Interactions of "user2"

		$this->factory->actAs($user2);
		foreach (array( $wood, $provider, $school, $book, $software, $tool ) as $knowledge) {
			$this->factory->comment($user2, $knowledge, 'Merci pour cette fiche, très utile !');
			$this->factory->like($user2, $knowledge);
		}
		$this->factory->review($user2, $book, 'Une référence', 'Le livre à avoir dans son atelier.', 5);
		$this->factory->review($user2, $software, 'Indispensable', 'Pour préparer les débits de bois.', 4);
		$this->testify($manager, $user2, $school);

		$manager->flush();

		$this->addReference(self::WOOD, $wood);
		$this->addReference(self::PROVIDER, $provider);
		$this->addReference(self::SCHOOL, $school);
		$this->addReference(self::BOOK, $book);
		$this->addReference(self::SOFTWARE, $software);
		$this->addReference(self::TOOL, $tool);
	}

	/**
	 * The values need the id of the knowledge: persist it before adding them.
	 */
	private function persistKnowledge(ObjectManager $manager, AbstractKnowledge $knowledge): void {
		$knowledge->incrementContributorCount();

		$manager->persist($knowledge);
		$manager->flush();
	}

	/**
	 * @param array<string, BaseValue> $values the values added to the knowledge, by field
	 */
	private function publishKnowledge(ObjectManager $manager, AbstractKnowledge $knowledge, User $user, array $values): void {
		foreach ($values as $field => $value) {
			$this->factory->proposeValue($knowledge, $field, $value, $user);
		}

		$this->factory->dispatch(PublicationListener::PUBLICATION_CREATED, $knowledge);
		$manager->flush();
		$this->factory->dispatch(PublicationListener::PUBLICATION_PUBLISHED, $knowledge);
	}

	private function text(string $data): Text {
		$value = new Text();
		$value->setData($data);
		return $value;
	}

	private function picture(User $user, string $file, string $name): Picture {
		$value = new Picture();
		$value->setData($this->factory->picture($user, $file, $name));
		return $value;
	}

	/**
	 * As TestimonialController::createAction.
	 */
	private function testify(ObjectManager $manager, User $user, School $school): void {
		$testimonial = new School\Testimonial();
		$testimonial->setSchool($school);
		$testimonial->setUser($user);
		$testimonial->setFromYear(2018);
		$testimonial->setToYear(2020);
		$testimonial->setDiploma('CAP Menuisier');
		$testimonial->setBody('Une formation exigeante, avec de très bons formateurs.');
		$this->factory->preprocess($testimonial);

		$school->addTestimonial($testimonial);
		$school->incrementTestimonialCount();
		$user->getMeta()->incrementTestimonialCount();

		$manager->persist($testimonial);

		$this->activityUtils->createTestifyActivity($testimonial, false);

		$this->factory->dispatch(PublicationListener::PUBLICATION_CHANGED, $school);

		$this->watchableUtils->autoCreateWatch($school, $user);

		$manager->flush();
	}

}
