<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Ladb\CoreBundle\Utils\WebpushNotificationUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Ladb\CoreBundle\Entity\Core\Like;
use Ladb\CoreBundle\Model\IndexableInterface;
use Ladb\CoreBundle\Model\LikableInterface;
use Ladb\CoreBundle\Model\AuthoredInterface;
use Ladb\CoreBundle\Model\HiddableInterface;
use Ladb\CoreBundle\Model\WatchableInterface;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\SearchUtils;
use Ladb\CoreBundle\Utils\LikableUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Utils\ActivityUtils;
use Ladb\CoreBundle\Utils\TypableUtils;

/**
 * @Route("/likes")
 */
class LikeController extends AbstractController {

	private function _retrieveRelatedEntity($entityType, $entityId, TypableUtils $typableUtils) {
		try {
			$entity = $typableUtils->findTypable($entityType, $entityId);
		} catch (\Exception $e) {
			throw $this->createNotFoundException($e->getMessage());
		}
		if (!($entity instanceof LikableInterface)) {
			throw $this->createNotFoundException('Entity must implements LikableInterface.');
		}
		return $entity;
	}

	/////

	/**
	 * @Route("/{entityType}/{entityId}/create", requirements={"entityType" = "\d+", "entityId" = "\d+"}, name="core_like_create")
	 * @Template("@LadbCore/Core/Like/create-xhr.html.twig")
	 */
	public function createAction(Request $request, $entityType, $entityId, ActivityUtils $activityUtils, LikableUtils $likableUtils, SearchUtils $searchUtils, TypableUtils $typableUtils, WatchableUtils $watchableUtils, WebpushNotificationUtils $webpushNotificationUtils) {

		$this->createLock('core_like_create', false, self::LOCK_TTL_CREATE_ACTION, false);

        // Exclude like if user is not email confirmed
        if (!$this->getUser()->getEmailConfirmed()) {
            throw $this->createNotFoundException('Not allowed - User email not confirmed (core_like_create)');
        }

		// Retrieve related entity

		$entity = $this->_retrieveRelatedEntity($entityType, $entityId, $typableUtils);
		if ($entity instanceof HiddableInterface && !$entity->getIsPublic()) {
			throw $this->createNotFoundException('Hidden entity could not be liked.');
		}

		$om = $this->getDoctrine()->getManager();
		$likeRepository = $om->getRepository(Like::CLASS_NAME);

		if (!$likeRepository->existsByEntityTypeAndEntityIdAndUser($entityType, $entityId, $this->getUser())) {

			$selfLike = $entity instanceof AuthoredInterface && $entity->getUser() == $this->getUser();
			if (!$selfLike) {

				$entity->incrementLikeCount();

				// Prepare like

				$like = new Like();
				$like->setEntityType($entityType);
				$like->setEntityId($entityId);
				$like->setUser($this->getUser());

				$this->getUser()->getMeta()->incrementSentLikeCount();
				if ($entity instanceof AuthoredInterface) {
					$like->setEntityUser($entity->getUser());
					$entity->getUser()->getMeta()->incrementRecievedLikeCount();
				}

				$om->persist($like);

				// Update index
				if ($entity instanceof IndexableInterface) {
					$searchUtils->replaceEntityInIndex($entity);
				}

				// Create activity
				$activityUtils->createLikeActivity($like, false);

				// Auto watch
				if ($entity instanceof WatchableInterface) {
					$watchableUtils->autoCreateWatch($entity, $this->getUser());
				}

				// Publish a webpush notification in queue
				if ($entity instanceof AuthoredInterface) {
					$webpushNotificationUtils->enqueueNewLikeNotification($like, $entity);
				}

				$om->flush();

			}

		}

		if (!$request->isXmlHttpRequest()) {

			// Return to
			$returnToUrl = $request->get('rtu');
			if (is_null($returnToUrl)) {
				$returnToUrl = $this->getRefererUrl($request);
			}

			return $this->redirect($returnToUrl);
		}

		return array(
			'likeContext'    => $likableUtils->getLikeContext($entity, $this->getUser()),
			'noTextOnMobile' => $request->get('noTextOnMobile', false),
		);
	}

	/**
	 * @Route("/{id}/delete", requirements={"id" = "\d+"}, name="core_like_delete")
	 * @Template("@LadbCore/Core/Like/delete-xhr.html.twig")
	 */
	public function deleteAction(Request $request, $id, ActivityUtils $activityUtils, LikableUtils $likableUtils, SearchUtils $searchUtils, TypableUtils $typableUtils) {
		$om = $this->getDoctrine()->getManager();
		$likeRepository = $om->getRepository(Like::CLASS_NAME);

		$like = $likeRepository->findOneById($id);
		if (is_null($like)) {
			throw $this->createNotFoundException('Unable to find Like entity (id='.$id.').');
		}
		if ($like->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_like_delete)');
		}

		// Retrieve related entity

		$entity = $this->_retrieveRelatedEntity($like->getEntityType(), $like->getEntityId(), $typableUtils);
		if ($entity instanceof HiddableInterface && !$entity->getIsPublic()) {
			throw $this->createNotFoundException('Hidden entity could not be unliked.');
		}

		// Update related entity
		$entity->incrementLikeCount(-1);

		// Decrement recieved like count on entity author
		if ($entity instanceof AuthoredInterface) {
			$entity->getUser()->getMeta()->incrementRecievedLikeCount(-1);
		}

		// Decrement sent like count on like user
		$like->getUser()->getMeta()->incrementSentLikeCount(-1);

		// Delete activities
		$activityUtils->deleteActivitiesByLike($like, false);

		// Delete like
		$om->remove($like);
		$om->flush();

		// Update index
		if ($entity instanceof IndexableInterface) {
			$searchUtils->replaceEntityInIndex($entity);
		}

		if (!$request->isXmlHttpRequest()) {

			// Return to (use referer because the user is already logged)
			$returnToUrl = $this->getRefererUrl($request);

			return $this->redirect($returnToUrl);
		}

		return array(
			'likeContext'    => $likableUtils->getLikeContext($entity, $this->getUser()),
			'noTextOnMobile' => $request->get('noTextOnMobile', false),
		);
	}

	/**
	 * @Route("/{entityType}/{entityId}", requirements={"entityType" = "\d+", "entityId" = "\d+"}, name="core_like_list_byentity")
	 * @Route("/{entityType}/{entityId}/{page}", requirements={"entityType" = "\d+", "entityId" = "\d+", "page" = "\d+"}, name="core_like_list_byentity_page")
	 * @Template("@LadbCore/Core/Like/list-byentity.html.twig")
	 */
	public function listByEntityAction(Request $request, $entityType, $entityId, PaginatorUtils $paginatorUtils, TypableUtils $typableUtils, $page = 0) {
		$om = $this->getDoctrine()->getManager();
		$likeRepository = $om->getRepository(Like::CLASS_NAME);

		// Retrieve related entity

		$entity = $this->_retrieveRelatedEntity($entityType, $entityId, $typableUtils);

		// Retrive likes

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $likeRepository->findPaginedByEntityTypeAndEntityIdJoinedOnUser($entityType, $entityId, $offset, $limit);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_like_list_byentity_page', array( 'entityType' => $entityType, 'entityId' => $entityId ), $page, $paginator->count());

		$parameters = array(
			'prevPageUrl' => $pageUrls->prev,
			'nextPageUrl' => $pageUrls->next,
			'entity'      => $entity,
			'likes'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Like/list-byentity-xhr.html.twig', $parameters);
		}
		return $parameters;
	}

}