<?php
namespace Ladb\CoreBundle\Consumer;

use Doctrine\Persistence\ManagerRegistry;
use Ladb\CoreBundle\Message\ListedViewMessage;
use Ladb\CoreBundle\Message\ShownViewMessage;
use Ladb\CoreBundle\Model\IndexableInterface;
use Psr\Log\LoggerInterface;
use Ladb\CoreBundle\Entity\Core\User;
use Ladb\CoreBundle\Entity\Core\View;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\TypableUtils;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

class ViewConsumer {

	private $logger;
	private $om;
	private $userRepository;
	private $viewRepository;
	private $typableUtils;
	private $searchUtils;

	public function __construct(ManagerRegistry $doctrine, LoggerInterface $logger, SearchUtils $searchUtils, TypableUtils $typableUtils) {

		$this->logger = $logger;

		$this->om = $doctrine->getManager();
		$this->userRepository = $this->om->getRepository(User::CLASS_NAME);
		$this->viewRepository = $this->om->getRepository(View::CLASS_NAME);

		$this->typableUtils = $typableUtils;
		$this->searchUtils = $searchUtils;

	}

	/////

	private function _executeListedProcess($entityType, $entityIds, $userId) {

		if (!is_null($userId)) {

			$user = $this->userRepository->findOneById($userId);
			if (!is_null($user)) {

				$viewRepository = $this->om->getRepository(View::CLASS_NAME);
				$viewedCount = $viewRepository->countByEntityTypeAndEntityIdsAndUserAndKind($entityType, $entityIds, $user, View::KIND_LISTED);
				if ($viewedCount < count($entityIds)) {

					$newViewCount = 0;
					foreach ($entityIds as $entityId) {

						if (!$viewRepository->existsByEntityTypeAndEntityIdAndUserAndKind($entityType, $entityId, $user, View::KIND_LISTED)) {

							// Create a new listed view
							$view = new View();
							$view->setEntityType($entityType);
							$view->setEntityId($entityId);
							$view->setUser($user);
							$view->setKind(View::KIND_LISTED);

							$this->om->persist($view);

							$newViewCount++;
						}

					}

					if ($newViewCount > 0) {
						$this->om->flush();
					}

				}

			}

		}

	}

	private function _executeShownProcess($entityType, $entityId, $userId) {

		// Retrieve viewable
		try {
			$viewable = $this->typableUtils->findTypable($entityType, $entityId);
		} catch(\Exception $e) {
			$this->logger->error('ViewConsumer/execute', array ( 'exception' => $e));
			return;
		}
		$updated = false;

		if (!is_null($userId)) {

			$user = $this->userRepository->findOneById($userId);
			if (!is_null($user)) {

				// Authenticated user -> use viewManager

				$view = $this->viewRepository->findOneByEntityTypeAndEntityIdAndUserAndKind($viewable->getType(), $viewable->getId(), $user, View::KIND_SHOWN);
				if (is_null($view)) {

					// Create a new view
					$view = new View();
					$view->setEntityType($viewable->getType());
					$view->setEntityId($viewable->getId());
					$view->setUser($user);
					$view->setKind(View::KIND_SHOWN);

					$this->om->persist($view);

					// Exclude self contribution view and non public viewables
					if ($viewable instanceof AuthoredInterface && $viewable->getUser()->getId() == $user->getId()) {
						// Saved now, not by the flush of a next message: the worker clears the entity manager after each message
						$this->om->flush();
						return;
					}

					// Increment viewCount
					$viewable->incrementViewCount();

					$updated = true;

				} else {

					// Exclude self contribution view and non public viewables
					if ($viewable instanceof AuthoredInterface && $viewable->getUser()->getId() == $user->getId()) {
						return;
					}

					if ($view->getCreatedAt() <= (new \DateTime())->sub(new \DateInterval('P1D'))) { // 1 day

						// View is older than 1 day. Update view, increment view count.

						// Reset view createAt
						$view->setCreatedAt(new \DateTime());

						// Increment viewCount
						$viewable->incrementViewCount();

						$updated = true;

					}

				}

			}

		} else {

			// Increment viewCount
			$viewable->incrementViewCount();

			$updated = true;

		}

		if ($updated) {

			// Flush DB updates (view and/or entity)
			$this->om->flush();

			// Update in Elasticsearch

//			Elasticsearch update is temporarily removed due to a strange bug that remove item from index...

//			if ($viewable instanceof IndexableInterface && $viewable->isIndexable()) {
//				$this->searchUtils->replaceEntityInIndex($viewable);
//			}

		}

	}

	/////

	#[AsMessageHandler]
	public function handleListedView(ListedViewMessage $message) {
		$this->_executeListedProcess($message->getEntityType(), $message->getEntityIds(), $message->getUserId());
	}

	#[AsMessageHandler]
	public function handleShownView(ShownViewMessage $message) {
		$this->_executeShownProcess($message->getEntityType(), $message->getEntityId(), $message->getUserId());
	}

}