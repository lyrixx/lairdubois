<?php

namespace Ladb\CoreBundle\DataFixtures;

use Doctrine\ORM\EntityManagerInterface;
use Ladb\CoreBundle\Entity\Core\Block\Gallery;
use Ladb\CoreBundle\Entity\Core\Block\Text;
use Ladb\CoreBundle\Entity\Core\Comment;
use Ladb\CoreBundle\Entity\Core\Follower;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Entity\Core\Picture;
use Ladb\CoreBundle\Entity\Core\Resource;
use Ladb\CoreBundle\Entity\Core\Review;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Core\Vote;
use Ladb\CoreBundle\Entity\Knowledge\AbstractKnowledge;
use Ladb\CoreBundle\Entity\Knowledge\Value\BaseValue;
use Ladb\CoreBundle\Event\KnowledgeEvent;
use Ladb\CoreBundle\Event\KnowledgeListener;
use Ladb\CoreBundle\Event\PublicationEvent;
use Ladb\CoreBundle\Event\PublicationListener;
use Ladb\CoreBundle\Event\VotableEvent;
use Ladb\CoreBundle\Event\VotableListener;
use Ladb\CoreBundle\Form\DataTransformer\Input\FinishesToLabelsTransformer;
use Ladb\CoreBundle\Form\DataTransformer\Input\HardwaresToLabelsTransformer;
use Ladb\CoreBundle\Form\DataTransformer\Input\SkillsToLabelsTransformer;
use Ladb\CoreBundle\Form\DataTransformer\Input\ToolsToLabelsTransformer;
use Ladb\CoreBundle\Form\DataTransformer\Input\WoodsToLabelsTransformer;
use Ladb\CoreBundle\Form\DataTransformer\TagsToLabelsTransformer;
use Ladb\CoreBundle\Manager\Core\PictureManager;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Model\BlockBodiedInterface;
use Ladb\CoreBundle\Model\CommentableInterface;
use Ladb\CoreBundle\Model\LikableInterface;
use Ladb\CoreBundle\Model\PublicationInterface;
use Ladb\CoreBundle\Model\ReviewableInterface;
use Ladb\CoreBundle\Model\VotableInterface;
use Ladb\CoreBundle\Model\VotableParentInterface;
use Ladb\CoreBundle\Model\WatchableInterface;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\BlockBodiedUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\FieldPreprocessorUtils;
use Ladb\CoreBundle\Utils\ReviewableUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Builds the fixtures the way the controllers build the real data: same utils, same
 * counters, same events. Keep in sync with the create actions of the controllers.
 */
final class FixtureFactory {

	// Pictures and resources versioned with the fixtures, a few KB each
	private const FILES_DIR = __DIR__.'/../Resources/fixtures';

	private $activityUtils;
	private $blockBodiedUtils;
	private $commentableUtils;
	private $dispatcher;
	private $fieldPreprocessorUtils;
	private $om;
	private $pictureManager;
	private $requestStack;
	private $reviewableUtils;
	private $tokenStorage;
	private $uploadsDir;
	private $watchableUtils;

	public function __construct(
		ActivityUtils $activityUtils,
		BlockBodiedUtils $blockBodiedUtils,
		CommentableUtils $commentableUtils,
		EventDispatcherInterface $dispatcher,
		FieldPreprocessorUtils $fieldPreprocessorUtils,
		EntityManagerInterface $om,
		PictureManager $pictureManager,
		RequestStack $requestStack,
		ReviewableUtils $reviewableUtils,
		TokenStorageInterface $tokenStorage,
		string $uploadsDir,
		WatchableUtils $watchableUtils
	) {
		$this->activityUtils = $activityUtils;
		$this->blockBodiedUtils = $blockBodiedUtils;
		$this->commentableUtils = $commentableUtils;
		$this->dispatcher = $dispatcher;
		$this->fieldPreprocessorUtils = $fieldPreprocessorUtils;
		$this->om = $om;
		$this->pictureManager = $pictureManager;
		$this->requestStack = $requestStack;
		$this->reviewableUtils = $reviewableUtils;
		$this->tokenStorage = $tokenStorage;
		$this->uploadsDir = $uploadsDir;
		$this->watchableUtils = $watchableUtils;
	}

	// Session /////

	/**
	 * Logs the user in, in a request with a session: the utils and the listeners read the
	 * user and the session of the request, as in the controllers.
	 */
	public function actAs(User $user): void {
		if (null === $this->requestStack->getCurrentRequest()) {
			$request = new Request();
			$request->setSession(new Session(new MockArraySessionStorage()));
			$this->requestStack->push($request);
		}
		$this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
	}

	// Files /////

	/**
	 * Copies the placeholders the templates ask Liip Imagine for (empty.png, avatar.png...).
	 */
	public function copyPlaceholders(): void {
		$fs = new Filesystem();
		foreach (glob(self::FILES_DIR.'/*.png') as $file) {
			$fs->copy($file, $this->uploadsDir.'/'.basename($file), true);
		}
	}

	/**
	 * Creates a picture from a versioned file. The name of the copy is stable, it is
	 * overwritten on the next load instead of piling up in uploads/.
	 */
	public function picture(User $user, string $file, string $name): Picture {
		$extension = pathinfo($file, PATHINFO_EXTENSION);

		$picture = new Picture();
		$picture->setUser($user);
		$picture->setMasterPath('fixture-'.$name.'.'.$extension);
		(new Filesystem())->copy(self::FILES_DIR.'/pictures/'.$file, $picture->getAbsoluteMasterPath(), true);
		$this->pictureManager->computeSizes($picture);

		$this->om->persist($picture);

		return $picture;
	}

	/**
	 * Creates a resource (plan file...) from a versioned file, as ResourceUploadHandler does.
	 */
	public function resource(User $user, string $file, string $name, int $kind, ?Picture $thumbnail = null): Resource {
		$extension = pathinfo($file, PATHINFO_EXTENSION);

		$resource = new Resource();
		$resource->setUser($user);
		$resource->setPath('fixture-'.$name.'.'.$extension);
		$resource->setFileName($file);
		$resource->setFileExtension($extension);
		$resource->setKind($kind);
		$resource->setThumbnail($thumbnail);
		(new Filesystem())->copy(self::FILES_DIR.'/resources/'.$file, $resource->getAbsolutePath(), true);
		$resource->setFileSize(filesize($resource->getAbsolutePath()));

		$this->om->persist($resource);

		return $resource;
	}

	// Labels /////

	/**
	 * Tags and inputs, from comma separated labels, as the form transformers create them.
	 */
	public function tags(string $labels): array {
		return (new TagsToLabelsTransformer($this->om))->reverseTransform($labels);
	}

	public function woods(string $labels): array {
		return (new WoodsToLabelsTransformer($this->om))->reverseTransform($labels);
	}

	public function tools(string $labels): array {
		return (new ToolsToLabelsTransformer($this->om))->reverseTransform($labels);
	}

	public function finishes(string $labels): array {
		return (new FinishesToLabelsTransformer($this->om))->reverseTransform($labels);
	}

	public function hardwares(string $labels): array {
		return (new HardwaresToLabelsTransformer($this->om))->reverseTransform($labels);
	}

	public function skills(string $labels): array {
		return (new SkillsToLabelsTransformer($this->om))->reverseTransform($labels);
	}

	// Body blocks /////

	public function textBlock(string $body, int $sortIndex = 0): Text {
		$block = new Text();
		$block->setBody($body);
		$block->setSortIndex($sortIndex);
		return $block;
	}

	/**
	 * @param Picture[] $pictures
	 */
	public function galleryBlock(array $pictures, int $sortIndex = 1): Gallery {
		$block = new Gallery();
		foreach ($pictures as $picture) {
			$block->addPicture($picture);
		}
		$block->setSortIndex($sortIndex);
		return $block;
	}

	// Publications /////

	/**
	 * What the create actions do between the form validation and the persist.
	 */
	public function preprocess(object $entity): void {
		if ($entity instanceof BlockBodiedInterface) {
			$this->blockBodiedUtils->preprocessBlocks($entity);
		}
		$this->fieldPreprocessorUtils->preprocessFields($entity);
	}

	/**
	 * The main picture of the block bodied publications without pictures field (questions...).
	 */
	public function firstPicture(BlockBodiedInterface $entity): ?Picture {
		return $this->blockBodiedUtils->getFirstPicture($entity);
	}

	/**
	 * Persists a new publication and dispatches the created event (tags usage, auto watch, index).
	 */
	public function create(PublicationInterface $publication): void {
		$this->om->persist($publication);
		$this->om->flush();

		$this->dispatcher->dispatch(new PublicationEvent($publication), PublicationListener::PUBLICATION_CREATED);

		// The next action (publish...) gets the publication as loaded from the database by
		// another request: its inverse collections (creations of a plan...) are only set then
		$this->om->refresh($publication);
	}

	public function dispatch(string $eventName, PublicationInterface $publication): void {
		$this->dispatcher->dispatch(new PublicationEvent($publication), $eventName);
	}

	// Knowledge /////

	/**
	 * Proposes a value for a field of a new knowledge, as the create actions of the knowledge
	 * controllers do. The value must already be added to the knowledge (addNameValue()...).
	 */
	public function proposeValue(AbstractKnowledge $knowledge, string $field, BaseValue $value, User $user): void {
		$this->dispatcher->dispatch(new KnowledgeEvent($knowledge, array( 'field' => $field, 'value' => $value )), KnowledgeListener::FIELD_VALUE_ADDED);

		$value->setParentEntity($knowledge);
		$value->setParentEntityField($field);
		$value->setUser($user);

		$user->getMeta()->incrementProposalCount();

		$this->activityUtils->createContributeActivity($value, false);
	}

	// Interactions /////

	/**
	 * As ReviewController::createAction.
	 */
	public function review(User $user, ReviewableInterface $entity, string $title, string $body, int $rating): Review {
		$review = new Review();
		$review->setEntityType($entity->getType());
		$review->setEntityId($entity->getId());
		$review->setUser($user);
		$review->setTitle($title);
		$review->setBody($body);
		$review->setRating($rating);

		$this->fieldPreprocessorUtils->preprocessFields($review);

		$entity->setChangedAt(new \DateTime());
		$entity->setUpdatedAt(new \DateTime());
		$entity->incrementReviewCount();
		$user->getMeta()->incrementReviewCount();

		$this->om->persist($review);
		$this->om->flush();

		$this->reviewableUtils->computeAverageRating($entity);

		$this->activityUtils->createReviewActivity($review, false);

		$this->dispatch(PublicationListener::PUBLICATION_CHANGED, $entity);

		$this->watchableUtils->autoCreateWatch($entity, $user);

		$this->om->flush();

		return $review;
	}

	/**
	 * As VoteController::createAction, without comment.
	 */
	public function vote(User $user, VotableInterface $entity, VotableParentInterface $parentEntity, int $score): Vote {
		$vote = new Vote();
		$vote->setEntityType($entity->getType());
		$vote->setEntityId($entity->getId());
		$vote->setParentEntityType($parentEntity->getType());
		$vote->setParentEntityId($parentEntity->getId());
		$vote->setParentEntityField($entity->getParentEntityField());
		$vote->setUser($user);
		$vote->setScore($score);

		$this->om->persist($vote);

		$entity->incrementVoteScore($score);
		$entity->incrementVoteCount();
		$parentEntity->incrementVoteCount();
		if ($score > 0) {
			$entity->incrementPositiveVoteScore($score);
			$parentEntity->incrementPositiveVoteCount();
			$user->getMeta()->incrementPositiveVoteCount();
		} else {
			$entity->incrementNegativeVoteScore(abs($score));
			$parentEntity->incrementNegativeVoteCount();
			$user->getMeta()->incrementNegativeVoteCount();
		}

		$this->dispatcher->dispatch(new VotableEvent($entity, $parentEntity), VotableListener::VOTE_UPDATED);

		$this->activityUtils->createVoteActivity($vote, false);

		$this->om->flush();

		return $vote;
	}

	/**
	 * As CommentController::createAction.
	 */
	public function comment(User $user, CommentableInterface $entity, string $body, ?Comment $parent = null): Comment {
		$comment = new Comment();
		$comment->setEntityType($entity->getType());
		$comment->setEntityId($entity->getId());
		$comment->setUser($user);
		$comment->setBody($body);
		if (!is_null($parent)) {
			$parent->addChild($comment);
			$parent->incrementChildCount();
		}

		$this->commentableUtils->finalizeNewComment($comment, $entity);

		return $comment;
	}

	/**
	 * As LikeController::createAction, without the webpush notification.
	 */
	public function like(User $user, LikableInterface $entity): Like {
		$entity->incrementLikeCount();

		$like = new Like();
		$like->setEntityType($entity->getType());
		$like->setEntityId($entity->getId());
		$like->setUser($user);

		$user->getMeta()->incrementSentLikeCount();

		if ($entity instanceof AuthoredInterface) {
			$like->setEntityUser($entity->getUser());
			$entity->getUser()->getMeta()->incrementRecievedLikeCount();
		}

		$this->om->persist($like);

		$this->activityUtils->createLikeActivity($like, false);

		if ($entity instanceof WatchableInterface) {
			$this->watchableUtils->autoCreateWatch($entity, $user);
		}

		$this->om->flush();

		return $like;
	}

	/**
	 * As FollowerController::createAction.
	 */
	public function follow(User $user, User $followingUser): Follower {
		$follower = new Follower();
		$follower->setUser($user);
		$follower->setFollowingUser($followingUser);

		$this->om->persist($follower);

		$user->getMeta()->incrementFollowingCount();
		$followingUser->getMeta()->incrementFollowerCount();

		$this->activityUtils->createFollowActivity($follower, false);

		$this->om->flush();

		return $follower;
	}

}
