<?php

namespace Ladb\CoreBundle\Event;

use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Entity\AbstractDraftableAuthoredPublication;
use Ladb\CoreBundle\Model\FeedbackableInterface;
use Ladb\CoreBundle\Model\LinkedToInterface;
use Ladb\CoreBundle\Model\MentionSourceInterface;
use Ladb\CoreBundle\Model\RepublishableInterface;
use Ladb\CoreBundle\Utils\FeedbackableUtils;
use Ladb\CoreBundle\Utils\MentionUtils;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Data\DataManager;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Ladb\CoreBundle\Utils\UserUtils;
use Ladb\CoreBundle\Utils\OpenGraphUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\TagUtils;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\ViewableUtils;
use Ladb\CoreBundle\Utils\GlobalUtils;
use Ladb\CoreBundle\Utils\TypableUtils;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\CommentableUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Model\ScrapableInterface;
use Ladb\CoreBundle\Model\PublicationInterface;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Model\ViewableInterface;
use Ladb\CoreBundle\Model\TaggableInterface;
use Ladb\CoreBundle\Model\WatchableInterface;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Model\CommentableInterface;
use Ladb\CoreBundle\Model\LikableInterface;
use Ladb\CoreBundle\Model\PicturedInterface;
use Ladb\CoreBundle\Model\MultiPicturedInterface;
use Ladb\CoreBundle\Entity\Core\View;
use Ladb\CoreBundle\Entity\AbstractPublication;

class PublicationListener implements EventSubscriberInterface {

	const PUBLICATION_CREATED = 'ladb.publication.created';
	const PUBLICATION_CREATED_FROM_CONVERT = 'ladb.publication.created_from_convert';
	const PUBLICATION_CHANGED = 'ladb.publication.changed';
	const PUBLICATION_UPDATED = 'ladb.publication.updated';
	const PUBLICATION_DELETED = 'ladb.publication.deleted';
	const PUBLICATION_LOCKED = 'ladb.publication.locked';
	const PUBLICATION_UNLOCKED = 'ladb.publication.unlocked';
	const PUBLICATION_PUBLISHED = 'ladb.publication.published';
	const PUBLICATION_UNPUBLISHED = 'ladb.publication.unpublished';
	const PUBLICATION_SHOWN = 'ladb.publication.shown';
	const PUBLICATIONS_LISTED = 'ladb.publications.listed';

	private $activityUtils;
	private $cacheManager;
	private $commentableUtils;
	private $dataManager;
	private $doctrine;
	private $feedbackableUtils;
	private $filterManager;
	private $globalUtils;
	private $likableUtils;
	private $mentionUtils;
	private $openGraphUtils;
	private $searchUtils;
	private $tagUtils;
	private $typableUtils;
	private $userUtils;
	private $viewableUtils;
	private $watchableUtils;

	public function __construct(ActivityUtils $activityUtils, CacheManager $cacheManager, CommentableUtils $commentableUtils, DataManager $dataManager, ManagerRegistry $doctrine, FeedbackableUtils $feedbackableUtils, FilterManager $filterManager, GlobalUtils $globalUtils, LikableUtils $likableUtils, MentionUtils $mentionUtils, OpenGraphUtils $openGraphUtils, SearchUtils $searchUtils, TagUtils $tagUtils, TypableUtils $typableUtils, UserUtils $userUtils, ViewableUtils $viewableUtils, WatchableUtils $watchableUtils) {
		$this->activityUtils = $activityUtils;
		$this->cacheManager = $cacheManager;
		$this->commentableUtils = $commentableUtils;
		$this->dataManager = $dataManager;
		$this->doctrine = $doctrine;
		$this->feedbackableUtils = $feedbackableUtils;
		$this->filterManager = $filterManager;
		$this->globalUtils = $globalUtils;
		$this->likableUtils = $likableUtils;
		$this->mentionUtils = $mentionUtils;
		$this->openGraphUtils = $openGraphUtils;
		$this->searchUtils = $searchUtils;
		$this->tagUtils = $tagUtils;
		$this->typableUtils = $typableUtils;
		$this->userUtils = $userUtils;
		$this->viewableUtils = $viewableUtils;
		$this->watchableUtils = $watchableUtils;
	}

	public static function getSubscribedEvents(): array {
		return array(
			PublicationListener::PUBLICATION_CREATED              => 'onPublicationCreated',
			PublicationListener::PUBLICATION_CREATED_FROM_CONVERT => 'onPublicationCreatedFromConvert',
			PublicationListener::PUBLICATION_CHANGED              => 'onPublicationChanged',
			PublicationListener::PUBLICATION_UPDATED              => 'onPublicationUpdated',
			PublicationListener::PUBLICATION_DELETED              => 'onPublicationDeleted',
			PublicationListener::PUBLICATION_LOCKED               => 'onPublicationLocked',
			PublicationListener::PUBLICATION_UNLOCKED             => 'onPublicationUnlocked',
			PublicationListener::PUBLICATION_PUBLISHED            => 'onPublicationPublished',
			PublicationListener::PUBLICATION_UNPUBLISHED          => 'onPublicationUnpublished',
			PublicationListener::PUBLICATION_SHOWN                => 'onPublicationShown',
			PublicationListener::PUBLICATIONS_LISTED              => 'onPublicationsListed',
		);
	}

	/////

	private function _resolvePicturesPageImageFilter(AbstractPublication $publication) {

		$filter = '470x275o';
		if ($publication instanceof MultiPicturedInterface) {
			$pictures = $publication->getPictures();
		} else if ($publication instanceof PicturedInterface) {
			$pictures = array( $publication->getMainPicture() );
		} else {
			return;
		}

		foreach ($pictures as $picture) {
			if (is_null($picture)) {
				continue;
			}
			// An empty picture has no file (website find without Open Graph image)
			$absolutePath = $picture->getAbsolutePath();
			if (is_null($absolutePath) || !is_file($absolutePath)) {
				continue;
			}
			$path = $picture->getPath();
			if (!$this->cacheManager->isStored($path, $filter)) {
				$binary = $this->dataManager->find($filter, $path);
				$this->cacheManager->store(
					$this->filterManager->applyFilter($binary, $filter),
					$path,
					$filter
				);
			}
		}

	}

	private function _scrapeOpenGraph(AbstractPublication $publication) {
		if ($this->globalUtils->getDebug()) {
			return;
		}
		if (!($publication instanceof ScrapableInterface) || !$publication->getIsScrapable()) {
			return;
		}

		// Scrape Open Graph URL (canonical)
		$this->openGraphUtils->scrape($this->typableUtils->getUrlAction($publication, 'show', true, false));

	}

	/////

	public function onPublicationCreated(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof TaggableInterface) {

			// Tags usage
			$this->tagUtils->useTaggableTags($publication);

		}

		if ($publication instanceof WatchableInterface) {

			// Auto watch
			$this->watchableUtils->autoCreateWatch($publication, $this->globalUtils->getUser());

		}

		if ($publication instanceof IndexableInterface && $publication->isIndexable()) {

			// Search index update
			$this->searchUtils->insertEntityToIndex($publication);

		}

		// Resolve main picture to avoid image url redirection
		$this->_resolvePicturesPageImageFilter($publication);

		// Scrape Open Graph URL
//		$this->_scrapeOpenGraph($publication);

	}

	public function onPublicationCreatedFromConvert(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof MentionSourceInterface) {

			// Process mentions
			$this->mentionUtils->processMentions($publication);

		}

		if ($publication instanceof TaggableInterface) {

			// Tags usage
			$this->tagUtils->useTaggableTags($publication);

		}

		// Search index update
		$this->searchUtils->insertEntityToIndex($publication);

		// Resolve main picture to avoid image url redirection
		$this->_resolvePicturesPageImageFilter($publication);

		// Scrape Open Graph URL
//		$this->_scrapeOpenGraph($publication);

	}

	public function onPublicationChanged(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof ViewableInterface) {

			// Delete listed views
			$this->viewableUtils->deleteViews($publication);

		}

		if ($publication instanceof IndexableInterface && $publication->isIndexable()) {

			// Search index update
			$this->searchUtils->replaceEntityInIndex($publication);

		}

	}

	public function onPublicationUpdated(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof MentionSourceInterface) {

			// Process mentions
			$this->mentionUtils->processMentions($publication);

		}

		if ($publication instanceof IndexableInterface && $publication->isIndexable()) {

			// Search index update
			$this->searchUtils->replaceEntityInIndex($publication);

			// Linked entities update
			if ($publication instanceof LinkedToInterface) {
				foreach ($publication->getLinkedEntities() as $entity) {
					if ($entity instanceof IndexableInterface && $entity->isIndexable()) {

						// Search index update
						$this->searchUtils->replaceEntityInIndex($entity);

					}
				}
			}

		}

		if ($publication instanceof TaggableInterface && array_key_exists('previouslyUsedTags', $event->getData())) {

			// Tags usage
			$this->tagUtils->useTaggableTags($publication, $event->getData()['previouslyUsedTags']);

		}

		// Resolve pictures
		$this->_resolvePicturesPageImageFilter($publication);

		// Scrape Open Graph URL
//		$this->_scrapeOpenGraph($publication);

	}

	public function onPublicationDeleted(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof IndexableInterface && $publication->isIndexable()) {

			// Search index update
			$this->searchUtils->deleteEntityFromIndex($publication);

			// Linked entities update
			if ($publication instanceof LinkedToInterface) {
				foreach ($publication->getLinkedEntities() as $entity) {
					if ($entity instanceof IndexableInterface && $entity->isIndexable()) {

						// Search index update
						$this->searchUtils->replaceEntityInIndex($entity);

					}
				}
			}

		}

        if ($publication instanceof ViewableInterface) {

            // Delete views
            $this->viewableUtils->deleteViews($publication);

        }

        if ($publication->getNotificationStrategy() != PublicationInterface::NOTIFICATION_STRATEGY_NONE) {

			// Delete activity
			$this->activityUtils->deleteActivitiesByEntityTypeAndEntityId($publication->getType(), $publication->getId());

		}

    }

	public function onPublicationLocked(PublicationEvent $event) {
	}

	public function onPublicationUnlocked(PublicationEvent $event) {
	}

	public function onPublicationPublished(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof IndexableInterface && $publication->isIndexable()) {

			// Search index update
			$this->searchUtils->replaceEntityInIndex($publication);

			// Linked entities update
			if ($publication instanceof LinkedToInterface) {
				foreach ($publication->getLinkedEntities() as $entity) {
					if ($entity instanceof IndexableInterface && $entity->isIndexable()) {

						// Search index update
						$this->searchUtils->replaceEntityInIndex($entity);

					}
				}
			}

		}

		if ($publication instanceof CommentableInterface) {

			// Increment users counters
			$this->commentableUtils->incrementUsersCommentCount($publication);

		}

		if ($publication instanceof LikableInterface) {

			// Increment users counters
			$this->likableUtils->incrementUsersLikeCount($publication);

		}

		if ($publication instanceof FeedbackableInterface) {

			// Increment users counters
			$this->feedbackableUtils->incrementUsersFeedbackCount($publication, 1);

		}

		// The publish count is already incremented: 1 on the first publication
		if ($publication->getNotificationStrategy() != PublicationInterface::NOTIFICATION_STRATEGY_NONE
			&& $publication instanceof AuthoredInterface
			&& !($publication instanceof RepublishableInterface && $publication->getPublishCount() > 1)) {

			// Get session user as "publisher"
			$user = $this->globalUtils->getUser();

			// Create activity
			$this->activityUtils->createPublishActivity($publication->getUser(), $publication->getType(), $publication->getId(), $user);

		}

		// Resolve main picture to avoid image url redirection
		$this->_resolvePicturesPageImageFilter($publication);

		// Scrape Open Graph URL
//		$this->_scrapeOpenGraph($publication);

	}

	public function onPublicationUnpublished(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof IndexableInterface && $publication->isIndexable()) {

			// Search index update
			$this->searchUtils->replaceEntityInIndex($publication);

			// Linked entities update
			if ($publication instanceof LinkedToInterface) {
				foreach ($publication->getLinkedEntities() as $entity) {
					if ($entity instanceof IndexableInterface && $entity->isIndexable()) {

						// Search index update
						$this->searchUtils->replaceEntityInIndex($entity);

					}
				}
			}

		}

		if ($publication instanceof ViewableInterface) {

			// Delete listed views
			$this->viewableUtils->deleteViews($publication);

		}

		if ($publication instanceof CommentableInterface) {

			// Decrement users counters
			$this->commentableUtils->incrementUsersCommentCount($publication, -1);

		}

		if ($publication instanceof LikableInterface) {

			// Decrement users counters
			$this->likableUtils->incrementUsersLikeCount($publication, -1);

		}

		if ($publication instanceof FeedbackableInterface) {

			// Decrement users counters
			$this->feedbackableUtils->incrementUsersFeedbackCount($publication, -1);

		}

		if ($publication->getNotificationStrategy() != PublicationInterface::NOTIFICATION_STRATEGY_NONE
			&& !($publication instanceof RepublishableInterface)) {

			// Delete activity
			$this->activityUtils->deleteActivitiesByEntityTypeAndEntityId($publication->getType(), $publication->getId());

		}

	}

	public function onPublicationShown(PublicationEvent $event) {
		$publication = $event->getPublication();

		if ($publication instanceof ViewableInterface) {

			// Process showed view
			$this->viewableUtils->processShownView($publication);

		}

	}

	public function onPublicationsListed(PublicationsEvent $event) {
		$publications = $event->getPublications();

		$user = $this->globalUtils->getUser();
		if (!is_null($user)) {

			// Prepare publication isShown field
			$entityType = null;
			$entityIds = array();
			foreach($publications as $publication) {
				if ($publication instanceof ViewableInterface) {
					$entityType = $publication->getType();
					$entityIds[] = $publication->getId();
					$publication->setIsShown(false);
				}
			}

			if (is_null($entityType)) {
				return;
			}

			$om = $this->doctrine->getManager();
			$viewRepository = $om->getRepository(View::CLASS_NAME);
			$views = $viewRepository->findByEntityTypeAndEntityIdsAndUserAndKind($entityType, $entityIds, $user, View::KIND_SHOWN);
			if (!is_null($views) && count($views) > 0) {
				foreach ($publications as $publication) {
					foreach ($views as $view) {
						if ($publication->getId() == $view->getEntityId()) {
							$publication->setIsShown(true);
							break;
						}
					}
				}
			}

			if ($event->isNeedCounterResetRefreshTime()) {

				// Compute unlisted counter for viewable entity type (if outdated) to be able to keep counter value after first list display
				$this->userUtils->computeUnlistedCounterByEntityType($user, $entityType, true, false);

			}

			// Process listed view
			$this->viewableUtils->processListedView($publications);

			if ($event->isNeedCounterResetRefreshTime()) {

				// Reset unlisted counter refresh time
				$this->userUtils->resetUnlistedCounterRefreshTimeByEntityType($user, $entityType);

			}

		}

	}

}