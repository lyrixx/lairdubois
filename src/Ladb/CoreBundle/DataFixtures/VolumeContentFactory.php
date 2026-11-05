<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Entity\AbstractAuthoredPublication;
use Ladb\CoreBundle\Entity\AbstractPublication;
use Ladb\CoreBundle\Entity\Blog\Post;
use Ladb\CoreBundle\Entity\Collection\Collection;
use Ladb\CoreBundle\Entity\Collection\Entry;
use Ladb\CoreBundle\Entity\Core\Biography;
use Ladb\CoreBundle\Entity\Core\Feedback;
use Ladb\CoreBundle\Entity\Core\License;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Entity\Core\Registration;
use Ladb\CoreBundle\Entity\Core\Resource;
use Ladb\CoreBundle\Entity\Core\Tag;
use Ladb\CoreBundle\Entity\Core\Tip;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Event\Event;
use Ladb\CoreBundle\Entity\Faq\Question as FaqQuestion;
use Ladb\CoreBundle\Entity\Find\Content\Gallery as FindGallery;
use Ladb\CoreBundle\Entity\Find\Find;
use Ladb\CoreBundle\Entity\Howto\Article;
use Ladb\CoreBundle\Entity\Howto\Howto;
use Ladb\CoreBundle\Entity\Input\Finish;
use Ladb\CoreBundle\Entity\Input\Hardware;
use Ladb\CoreBundle\Entity\Input\Tool as InputTool;
use Ladb\CoreBundle\Entity\Input\Wood as InputWood;
use Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge;
use Ladb\CoreBundle\Entity\Knowledge\Book;
use Ladb\CoreBundle\Entity\Knowledge\Provider;
use Ladb\CoreBundle\Entity\Knowledge\School;
use Ladb\CoreBundle\Entity\Knowledge\Software;
use Ladb\CoreBundle\Entity\Knowledge\Tool;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Ladb\CoreBundle\Entity\Knowledge\Value\BookIdentity;
use Ladb\CoreBundle\Entity\Knowledge\Value\Picture as PictureValue;
use Ladb\CoreBundle\Entity\Knowledge\Value\Sign;
use Ladb\CoreBundle\Entity\Knowledge\Value\SoftwareIdentity;
use Ladb\CoreBundle\Entity\Knowledge\Value\Text as TextValue;
use Ladb\CoreBundle\Entity\Knowledge\Wood;
use Ladb\CoreBundle\Entity\Message\Thread;
use Ladb\CoreBundle\Entity\Offer\Offer;
use Ladb\CoreBundle\Entity\Promotion\Graphic;
use Ladb\CoreBundle\Entity\Qa\Answer;
use Ladb\CoreBundle\Entity\Qa\Question;
use Ladb\CoreBundle\Entity\Wonder\Creation;
use Ladb\CoreBundle\Entity\Wonder\Plan;
use Ladb\CoreBundle\Entity\Wonder\Workshop;
use Ladb\CoreBundle\Entity\Workflow\Label;
use Ladb\CoreBundle\Entity\Workflow\Part;
use Ladb\CoreBundle\Entity\Workflow\Task;
use Ladb\CoreBundle\Entity\Workflow\Workflow;
use Ladb\CoreBundle\Entity\Youtook\Took;
use Ladb\CoreBundle\Manager\Core\UserManager;
use Ladb\CoreBundle\Manager\Qa\QuestionManager;
use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Ladb\CoreBundle\Model\BodiedInterface;
use Ladb\CoreBundle\Model\DraftableInterface;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Model\LicensedInterface;
use Ladb\CoreBundle\Model\LocalisableInterface;
use Ladb\CoreBundle\Model\MultiPicturedInterface;
use Ladb\CoreBundle\Model\PicturedInterface;
use Ladb\CoreBundle\Model\TaggableInterface;
use Ladb\CoreBundle\Model\TitledInterface;
use Ladb\CoreBundle\Utils\KnowledgeUtils;
use Ladb\CoreBundle\Utils\MessageUtils;
use Ladb\CoreBundle\Utils\PlanUtils;
use Ladb\CoreBundle\Utils\VideoHostingUtils;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Builds the contents of the volume fixtures, without the events of the controllers. Picks its
 * authors, tags and inputs by id: VolumeFixturesCommand clears the entity manager between two
 * batches. The ids of the accounts are contiguous, in one load.
 */
final class VolumeContentFactory {

	private const ADMIN_ID = 1;
	private const PASSWORD = 'volume';

	private const PICTURES = array( 'wood-1.jpg', 'wood-2.jpg', 'wood-3.jpg', 'wood-4.jpg', 'wood-5.jpg', 'wood-6.jpg' );
	private const AVATARS = array( 'avatar-user.png', 'avatar-user2.png', 'avatar-admin.png' );

	// The dates of a type go from this many days ago to now, in the order of its ids
	private const SPREAD_IN_DAYS = 1825;

	private const FIRST_NAMES = array( 'Jeanne', 'Paul', 'Marie', 'Louis', 'Claire', 'Hugo', 'Alice', 'Marc', 'Léa', 'Pierre', 'Emma', 'Yves', 'Chloé', 'Rémi', 'Inès', 'Théo' );
	private const LAST_NAMES = array( 'Martin', 'Bernard', 'Dubois', 'Durand', 'Lefebvre', 'Moreau', 'Laurent', 'Simon', 'Michel', 'Garcia', 'Roux', 'Fournier', 'Girard', 'Bonnet', 'Lambert', 'Fontaine' );

	/** @var list<array{string, float, float}> */
	private const LOCATIONS = array(
		array( 'Lyon, France', 45.764043, 4.835659 ),
		array( 'Nantes, France', 47.218371, -1.553621 ),
		array( 'Annecy, France', 45.899247, 6.129384 ),
		array( 'Bordeaux, France', 44.837789, -0.57918 ),
		array( 'Lille, France', 50.62925, 3.057256 ),
		array( 'Strasbourg, France', 48.573405, 7.752111 ),
		array( 'Toulouse, France', 43.604652, 1.444209 ),
		array( 'Rennes, France', 48.117266, -1.677793 ),
	);

	private const OBJECTS = array( 'table basse', 'bibliothèque', 'établi', 'commode', 'buffet', 'chaise', 'tabouret', 'bureau', 'lit', 'étagère', 'coffre', 'banc', 'desserte', 'armoire', 'horloge', 'plateau', 'luge', 'guéridon', 'console', 'vaisselier' );
	private const WOODS = array( 'chêne', 'noyer', 'frêne', 'hêtre', 'merisier', 'érable', 'châtaignier', 'douglas', 'mélèze', 'orme', 'poirier', 'pin' );
	private const INPUT_TOOLS = array( 'Rabot', 'Scie à ruban', 'Ciseau à bois', 'Toupie', 'Défonceuse', 'Dégauchisseuse' );
	private const FINISHES = array( 'Huile dure', 'Cire', 'Vernis', 'Lasure', 'Gomme-laque' );
	private const HARDWARES = array( 'Vis', 'Charnière', 'Coulisse', 'Équerre', 'Tourillon' );

	private const SENTENCES = array(
		'Le plateau est en bois massif, raboté à la main puis poncé au grain 240.',
		'Les assemblages sont à tenons et mortaises, chevillés sans aucune vis apparente.',
		'J\'ai débité les planches à la scie à ruban avant de les dégauchir.',
		'La finition est une huile dure, passée en deux couches à un jour d\'intervalle.',
		'Le séchage a duré plusieurs mois à l\'abri, sous le hangar de l\'atelier.',
		'Les chants sont adoucis à la toupie avec une fraise quart de rond.',
		'Comptez un bon week-end pour le montage à blanc et le collage.',
		'Le bois vient d\'une scierie locale, acheté en plots et séché à l\'air.',
		'Le piétement est démontable, ce qui facilite le transport.',
		'Une petite **astuce** : tracer au trusquin plutôt qu\'au crayon pour plus de précision.',
		'Les dimensions finales sont de 120 cm par 60 cm, pour une hauteur de 45 cm.',
		'Le résultat est solide, et le veinage ressort très bien à la lumière.',
	);

	private int $count = 0;
	private int $firstUserId = 0;
	private int $userCount = 0;
	private string $passwordHash = '';

	/** @var list<int> */
	private array $tagIds = array();
	/** @var array<class-string, list<int>> */
	private array $inputIds = array();

	public function __construct(
		private readonly FixtureFactory $factory,
		private readonly KnowledgeUtils $knowledgeUtils,
		private readonly MessageUtils $messageUtils,
		private readonly EntityManagerInterface $om,
		private readonly PasswordHasherFactoryInterface $passwordHasherFactory,
		private readonly PlanUtils $planUtils,
		private readonly QuestionManager $questionManager,
		private readonly UserManager $userManager,
	) {
	}

	/**
	 * The password hash, one for all the accounts: 0.4 s each otherwise. The tags, one for 10
	 * contents, and the inputs of the creations.
	 */
	public function prepare(int $count): void {
		$this->count = $count;
		$this->passwordHash = $this->passwordHasherFactory->getPasswordHasher(User::class)->hash(self::PASSWORD);

		$tags = array();
		for ($i = 1; $i <= intdiv($count, 10) + 1; $i++) {
			$tags[] = self::OBJECTS[$i % count(self::OBJECTS)].' '.$i;
		}
		$this->tagIds = $this->ids($this->factory->tags(implode(',', $tags)));
		$this->inputIds = array(
			Finish::class => $this->ids($this->factory->finishes(implode(',', self::FINISHES))),
			Hardware::class => $this->ids($this->factory->hardwares(implode(',', self::HARDWARES))),
			InputTool::class => $this->ids($this->factory->tools(implode(',', self::INPUT_TOOLS))),
			InputWood::class => $this->ids($this->factory->woods(implode(',', array_map(ucfirst(...), self::WOODS)))),
		);
		$this->om->clear();
	}

	/**
	 * The authors of what follows.
	 */
	public function useUsers(int $firstUserId, int $userCount): void {
		$this->firstUserId = $firstUserId;
		$this->userCount = $userCount;
	}

	// Accounts /////

	public function user(int $i): User {
		$firstName = $this->pick(self::FIRST_NAMES);
		$lastName = $this->pick(self::LAST_NAMES);
		$location = $this->pick(self::LOCATIONS);

		$user = $this->userManager->createUser();
		$user->setCreatedAt($this->date($i));
		$user->setUsername('membre'.$i);
		$user->setDisplayname($firstName.' '.$lastName.' '.$i);
		$user->setFullname($firstName.' '.$lastName);
		$user->setEmail('membre'.$i.'@lairdubois.test');
		$user->setEmailConfirmed(true);
		$user->setPassword($this->passwordHash);
		$user->setEnabled(true);
		$user->setAccountType(0 === $i % 5 ? User::ACCOUNT_TYPE_PRO : User::ACCOUNT_TYPE_HOBBYIST);
		$user->setLocation($location[0]);
		$user->setLatitude($location[1]);
		$user->setLongitude($location[2]);
		$user->setAvatar($this->factory->picture($user, $this->pick(self::AVATARS), 'volume-'.$i % count(self::AVATARS)));

		$biography = new Biography();
		$biography->setBody($this->body());
		$this->factory->preprocess($biography);
		$user->getMeta()->setBiography($biography);

		$this->userManager->updateUser($user, false);

		$registration = new Registration();
		$registration->setUser($user);
		$registration->setClientIp4('127.0.0.1');
		$this->om->persist($registration);

		return $user;
	}

	// Wonders /////

	public function creation(int $i): Creation {
		$creation = new Creation();
		foreach ($this->inputs(InputWood::class, 2) as $wood) {
			$creation->addWood($wood);
		}
		foreach ($this->inputs(InputTool::class, 2) as $tool) {
			$creation->addTool($tool);
		}
		foreach ($this->inputs(Finish::class, 1) as $finish) {
			$creation->addFinish($finish);
		}
		foreach ($this->inputs(Hardware::class, 1) as $hardware) {
			$creation->addHardware($hardware);
		}
		return $this->publication($creation, $i);
	}

	public function workshop(int $i): Workshop {
		$workshop = new Workshop();
		$workshop->setArea(10 + $i % 90);
		return $this->publication($workshop, $i);
	}

	public function plan(int $i): Plan {
		$plan = $this->publication(new Plan(), $i);
		$plan->addResource($this->resource($plan->getUser(), $plan->getMainPicture()));
		$this->planUtils->generateKinds($plan);
		return $plan;
	}

	// Others /////

	public function howto(int $i): Howto {
		$howto = $this->publication(new Howto(), $i);

		$article = new Article();
		$article->setHowto($howto);
		$article->setTitle($this->title($i));
		$article->addBodyBlock($this->factory->textBlock($this->body()));
		$article->setCreatedAt($howto->getCreatedAt());
		$article->setChangedAt($howto->getCreatedAt());
		$this->factory->preprocess($article);
		$article->setSortIndex(1);
		$article->setIsDraft(false);
		$howto->addArticle($article);
		$howto->incrementPublishedArticleCount();
		$this->om->persist($article);

		return $howto;
	}

	public function find(int $i): Find {
		$user = $this->author();

		$gallery = new FindGallery();
		$gallery->addPicture($this->picture($user));
		$location = $this->pick(self::LOCATIONS);
		$gallery->setLocation($location[0]);
		$gallery->setLatitude($location[1]);
		$gallery->setLongitude($location[2]);

		$find = new Find();
		$find->setContentType(Find::CONTENT_TYPE_GALLERY);
		$find->setContent($gallery);
		$find->setKind(Find::KIND_GALLERY);
		$find->setMainPicture($gallery->getPictures()->first());
		$find->setUser($user);

		return $this->publication($find, $i);
	}

	public function event(int $i): Event {
		$start = (new \DateTimeImmutable('today'))->modify(sprintf('%+d days', $i % 720 - 360));

		$event = new Event();
		$event->setStartDate(\DateTime::createFromImmutable($start));
		$event->setStartTime(new \DateTime('10:00'));
		$event->setEndDate(\DateTime::createFromImmutable($start));
		$event->setEndTime(new \DateTime('18:00'));
		$event->setStartAt(\DateTime::createFromImmutable($start->setTime(10, 0)));
		$event->setEndAt(\DateTime::createFromImmutable($start->setTime(18, 0)));
		return $this->publication($event, $i);
	}

	public function offer(int $i): Offer {
		$price = 10 + $i % 990;

		$offer = new Offer();
		$offer->setKind(0 === $i % 4 ? Offer::KIND_REQUEST : Offer::KIND_OFFER);
		$offer->setCategory(Offer::CATEGORY_TOOL);
		$offer->setRawPrice($price);
		$offer->setCurrency('EUR');
		$offer->setPrice($price.' €');
		return $this->publication($offer, $i);
	}

	public function question(int $i): Question {
		return $this->publication(new Question(), $i);
	}

	/**
	 * As AnswerController::createAction: the answer needs the id of its question.
	 */
	public function answer(Question $question): void {
		$answer = new Answer();
		$answer->setQuestion($question);
		$answer->setUser($this->author());
		$answer->addBodyBlock($this->factory->textBlock($this->body()));
		$this->factory->preprocess($answer);
		$answer->setParentEntity($question);
		$answer->setParentEntityField('bestAnswer');
		$answer->setUpdatedAt($question->getCreatedAt());
		$question->addAnswer($answer);
		$question->incrementAnswerCount();
		$this->om->persist($answer);
		$this->questionManager->computeAnswerCounters($question);
	}

	public function workflow(int $i): Workflow {
		$workflow = $this->publication(new Workflow(), $i);

		$label = new Label();
		$label->setName('Débit');
		$label->setColor('#1abc9c');
		$workflow->addLabel($label);

		$part = new Part();
		$part->setNumber('A');
		$part->setName('Plateau');
		$part->setCount(1);
		$workflow->addPart($part);

		$debit = new Task();
		$debit->setTitle('Débiter les planches');
		$debit->setStatus(Task::STATUS_WORKABLE);
		$debit->setPositionLeft(20);
		$debit->setPositionTop(20);
		$workflow->addTask($debit);
		$debit->addLabel($label);
		$debit->addPart($part);
		$debit->setPartCount(1);
		$workflow->incrementTaskCount();

		$finish = new Task();
		$finish->setTitle('Poncer et huiler');
		$finish->setStatus(Task::STATUS_PENDING);
		$finish->setPositionLeft(20);
		$finish->setPositionTop(120);
		$debit->addTargetTask($finish);
		$workflow->addTask($finish);
		$workflow->incrementTaskCount();

		return $workflow;
	}

	/**
	 * @param int $firstCreationId the entries are creations of the volume fixtures
	 */
	public function collection(int $i, int $firstCreationId): Collection {
		$collection = $this->publication(new Collection(), $i);
		for ($offset = 0; $offset < min(3, $this->count); $offset++) {
			$entry = new Entry();
			$entry->setEntityType(Creation::TYPE);
			$entry->setEntityId($firstCreationId + ($i + $offset) % $this->count);
			$collection->addEntry($entry);
			$collection->incrementEntryCount();
			$collection->incrementEntryTypeCounters(Creation::TYPE);
		}
		return $collection;
	}

	public function graphic(int $i): Graphic {
		$graphic = $this->publication(new Graphic(), $i);
		$graphic->setLicense(new License());
		$graphic->setResource($this->resource($graphic->getUser(), $graphic->getMainPicture()));
		return $graphic;
	}

	public function post(int $i): Post {
		$post = new Post();
		$post->setUser($this->om->getReference(User::class, self::ADMIN_ID));
		return $this->publication($post, $i);
	}

	public function faqQuestion(int $i): FaqQuestion {
		$question = new FaqQuestion();
		$question->setIcon('creation');
		$question->setUser($this->om->getReference(User::class, self::ADMIN_ID));
		return $this->publication($question, $i);
	}

	public function tip(int $i): Tip {
		$tip = new Tip();
		$tip->setUrl('https://www.example.com/astuces/'.$i);
		return $this->publication($tip, $i);
	}

	public function took(int $i): Took {
		$took = new Took();
		$took->setUrl('https://www.youtube.com/watch?v=aqz-KE-bpKQ');
		$took->setKind(VideoHostingUtils::KIND_YOUTUBE);
		$took->setEmbedIdentifier('aqz-KE-bpKQ');
		$took->setThumbnailUrl('https://i.ytimg.com/vi/aqz-KE-bpKQ/hqdefault.jpg');
		$took->setChannelId('UCfixtures');
		$took->setChannelThumbnailUrl('https://i.ytimg.com/vi/aqz-KE-bpKQ/default.jpg');
		$took->setChannelTitle('L\'Air du Bois');
		return $this->publication($took, $i);
	}

	/**
	 * As FeedbackController::createAction, on a creation of the volume fixtures.
	 */
	public function feedback(int $i, int $creationId): Feedback {
		$feedback = new Feedback();
		$feedback->setEntityType(Creation::TYPE);
		$feedback->setEntityId($creationId);
		$feedback->setUser($this->author());
		$feedback->setTitle('Un an plus tard');
		$feedback->addBodyBlock($this->factory->textBlock($this->body()));
		$this->factory->preprocess($feedback);
		return $feedback;
	}

	/**
	 * A thread of one message, between two accounts.
	 */
	public function thread(int $i): Thread {
		$sender = $this->author();
		$recipient = $this->om->getReference(User::class, $this->firstUserId + ($sender->getId() - $this->firstUserId + 1) % $this->userCount);
		return $this->messageUtils->composeThread($sender, array( $recipient ), $this->title($i), $this->body(), null, false, false);
	}

	// Knowledges /////

	public function wood(int $i): Wood {
		$wood = new Wood();
		$wood->setName($this->title($i));
		return $this->publication($wood, $i);
	}

	public function woodValues(Wood $wood): void {
		$name = $this->textValue($wood->getName());
		$grain = $this->pictureValue();
		$wood->addNameValue($name);
		$wood->addGrainValue($grain);
		$this->proposeValues($wood, array( Wood::FIELD_NAME => $name, Wood::FIELD_GRAIN => $grain ));
	}

	public function provider(int $i): Provider {
		$provider = new Provider();
		$provider->setSign($this->title($i).',Annecy');
		$provider->setBrand($this->title($i));
		$provider->setStore('Annecy');
		return $this->publication($provider, $i);
	}

	public function providerValues(Provider $provider): void {
		$sign = new Sign();
		$sign->setBrand($provider->getBrand());
		$sign->setStore($provider->getStore());
		$sign->setIsAffiliate(false);
		$sign->setData($provider->getSign());
		$logo = $this->pictureValue();
		$provider->addSignValue($sign);
		$provider->addLogoValue($logo);
		$this->proposeValues($provider, array( Provider::FIELD_SIGN => $sign, Provider::FIELD_LOGO => $logo ));
	}

	public function school(int $i): School {
		$school = new School();
		$school->setName($this->title($i));
		return $this->publication($school, $i);
	}

	public function schoolValues(School $school): void {
		$name = $this->textValue($school->getName());
		$logo = $this->pictureValue();
		$school->addNameValue($name);
		$school->addLogoValue($logo);
		$this->proposeValues($school, array( School::FIELD_NAME => $name, School::FIELD_LOGO => $logo ));
	}

	public function book(int $i): Book {
		return $this->publication(new Book(), $i);
	}

	public function bookValues(Book $book): void {
		$identity = new BookIdentity();
		$identity->setWork($book->getTitle());
		$identity->setIsVolume(false);
		$identity->setData($identity->getWork());
		$cover = $this->pictureValue();
		$book->addIdentityValue($identity);
		$book->addCoverValue($cover);
		$this->proposeValues($book, array( Book::FIELD_IDENTITY => $identity, Book::FIELD_COVER => $cover ));
	}

	public function software(int $i): Software {
		return $this->publication(new Software(), $i);
	}

	public function softwareValues(Software $software): void {
		$identity = new SoftwareIdentity();
		$identity->setName($software->getTitle());
		$identity->setIsAddOn(false);
		$identity->setData($identity->getName());
		$icon = $this->pictureValue();
		$software->addIdentityValue($identity);
		$software->addIconValue($icon);
		$this->proposeValues($software, array( Software::FIELD_IDENTITY => $identity, Software::FIELD_ICON => $icon ));
	}

	public function tool(int $i): Tool {
		return $this->publication(new Tool(), $i);
	}

	public function toolValues(Tool $tool): void {
		$name = $this->textValue($tool->getTitle());
		$photo = $this->pictureValue();
		$tool->addNameValue($name);
		$tool->addPhotoValue($photo);
		$this->proposeValues($tool, array( Tool::FIELD_NAME => $name, Tool::FIELD_PHOTO => $photo ));
	}

	// Common /////

	/**
	 * What the publications have in common, from the interfaces they implement: what the create
	 * action, its form and the publish action of a controller write.
	 *
	 * @template T of AbstractPublication
	 * @param T $publication
	 * @return T
	 */
	private function publication(AbstractPublication $publication, int $i): AbstractPublication {
		$user = $this->author();
		if ($publication instanceof AbstractAuthoredPublication) {
			$user = $publication->getUser() ?? $user;
			$publication->setUser($user);
		}

		if ($publication instanceof TitledInterface && is_null($publication->getTitle())) {
			$publication->setTitle($this->title($i));
		}
		if ($publication instanceof BlockBodiedInterface) {
			$publication->addBodyBlock($this->factory->textBlock($this->body()));
			if (!$publication instanceof MultiPicturedInterface && !$publication instanceof Find) {
				$picture = $this->picture($user);
				$publication->addBodyBlock($this->factory->galleryBlock(array( $picture )));
				$publication->setMainPicture($picture);
			}
		} else if ($publication instanceof BodiedInterface) {
			$publication->setBody($this->body());
		}
		if ($publication instanceof MultiPicturedInterface) {
			$publication->addPicture($this->picture($user));
			$publication->addPicture($this->picture($user));
			$publication->setMainPicture($publication->getPictures()->first());
		} else if ($publication instanceof PicturedInterface && !$publication instanceof AbstractKnowledge && is_null($publication->getMainPicture())) {
			$publication->setMainPicture($this->picture($user));
		}
		if ($publication instanceof LicensedInterface) {
			$publication->setLicense(new License());
		}
		if ($publication instanceof TaggableInterface) {
			foreach ($this->references(Tag::class, $this->tagIds, 3) as $tag) {
				$publication->addTag($tag);
			}
		}
		if ($publication instanceof LocalisableInterface) {
			$location = $this->pick(self::LOCATIONS);
			$publication->setLocation($location[0]);
			$publication->setLatitude($location[1]);
			$publication->setLongitude($location[2]);
		}
		$this->factory->preprocess($publication);

		$date = $this->date($i);
		$publication->setCreatedAt($date);
		$publication->setChangedAt($date);
		if ($publication instanceof HiddableInterface) {
			$publication->setVisibility(HiddableInterface::VISIBILITY_PUBLIC);
		}
		if ($publication instanceof DraftableInterface) {
			$publication->setIsDraft(false);
		}

		return $publication;
	}

	/**
	 * @param object[] $entities
	 * @return list<int>
	 */
	private function ids(array $entities): array {
		foreach ($entities as $entity) {
			$this->om->persist($entity);
		}
		$this->om->flush();

		return array_map(static fn (object $entity): int => $entity->getId(), array_values($entities));
	}

	private function author(): User {
		return $this->om->getReference(User::class, $this->firstUserId + mt_rand(0, $this->userCount - 1));
	}

	/**
	 * A row in tbl_core_picture on one of the files of the fixtures, under its stable name:
	 * uploads/ does not grow.
	 */
	private function picture(User $user): Picture {
		$file = $this->pick(self::PICTURES);
		return $this->factory->picture($user, $file, 'volume-'.pathinfo($file, PATHINFO_FILENAME));
	}

	private function resource(User $user, Picture $thumbnail): Resource {
		return $this->factory->resource($user, 'plan.svg', 'volume', Resource::KIND_SVG, $thumbnail);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @return list<T>
	 */
	private function inputs(string $class, int $count): array {
		return $this->references($class, $this->inputIds[$class], $count);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $class
	 * @param list<int> $ids
	 * @return list<T>
	 */
	private function references(string $class, array $ids, int $count): array {
		$keys = (array) array_rand($ids, min($count, count($ids)));
		return array_map(fn (int $key): object => $this->om->getReference($class, $ids[$key]), $keys);
	}

	private function textValue(string $data): TextValue {
		$value = new TextValue();
		$value->setData($data);
		return $value;
	}

	private function pictureValue(): PictureValue {
		$value = new PictureValue();
		$value->setData($this->picture($this->author()));
		return $value;
	}

	/**
	 * As FixtureFactory::proposeValue(), without the event and the activity: the knowledge is
	 * updated by KnowledgeUtils, which the listener calls. A value needs the id of its knowledge.
	 *
	 * @param array<string, BaseValue> $values the values added to the knowledge, by field
	 */
	private function proposeValues(AbstractKnowledge $knowledge, array $values): void {
		$knowledge->incrementContributorCount();
		foreach ($values as $field => $value) {
			$value->setParentEntity($knowledge);
			$value->setParentEntityField($field);
			$value->setUser($this->author());
			$this->knowledgeUtils->updateKnowledgeField($knowledge, $field);
		}
	}

	private function title(int $i): string {
		return sprintf('%s en %s n° %d', $this->pick(self::OBJECTS), $this->pick(self::WOODS), $i);
	}

	/**
	 * Two paragraphs of 2 to 4 sentences.
	 */
	private function body(): string {
		$paragraphs = array();
		for ($i = 0; $i < 2; $i++) {
			$sentences = array_intersect_key(self::SENTENCES, array_flip((array) array_rand(self::SENTENCES, mt_rand(2, 4))));
			$paragraphs[] = implode(' ', $sentences);
		}
		return implode("\n\n", $paragraphs);
	}

	private function date(int $i): \DateTime {
		$daysAgo = intdiv(self::SPREAD_IN_DAYS * max(0, $this->count - $i), $this->count);
		return new \DateTime(sprintf('-%d days', $daysAgo));
	}

	/**
	 * @template T
	 * @param array<T> $items
	 * @return T
	 */
	private function pick(array $items): mixed {
		return $items[array_rand($items)];
	}

}
