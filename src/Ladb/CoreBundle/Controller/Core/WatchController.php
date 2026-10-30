<?php

namespace Ladb\CoreBundle\Controller\Core;

use Ladb\CoreBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bridge\Twig\Attribute\Template;
use Ladb\CoreBundle\Utils\WatchableUtils;
use Ladb\CoreBundle\Utils\TypableUtils;
use Ladb\CoreBundle\Utils\PaginatorUtils;
use Ladb\CoreBundle\Model\WatchableInterface;
use Ladb\CoreBundle\Entity\Core\Watch;
use Symfony\Contracts\Translation\TranslatorInterface;
use Doctrine\ORM\EntityManagerInterface;

#[Route(path: '/watches')]
class WatchController extends AbstractController {

	private function _retrieveRelatedEntity($entityType, $entityId, TypableUtils $typableUtils) {
		try {
			$entity = $typableUtils->findTypable($entityType, $entityId);
		} catch (\Exception $e) {
			throw $this->createNotFoundException($e->getMessage());
		}
		if (!($entity instanceof WatchableInterface)) {
			throw $this->createNotFoundException('Entity must implements WatchableInterface.');
		}
		return $entity;
	}

	/////
	#[Route(path: '/{entityType}/{entityId}/create', requirements: ['entityType' => '\d+', 'entityId' => '\d+'], name: 'core_watch_create')]
	#[Template('@LadbCore/Core/Watch/create-xhr.html.twig')]
	public function createAction(Request $request, $entityType, $entityId, TranslatorInterface $translator, TypableUtils $typableUtils, WatchableUtils $watchableUtils) {

		$this->createLock('core_watch_create', false, self::LOCK_TTL_CREATE_ACTION, false);

		// Retrieve related entity

		$entity = $this->_retrieveRelatedEntity($entityType, $entityId, $typableUtils);

		// Create the watch
		$watchableUtils->createWatch($entity, $this->getUser());

		// Flashbag
		if (!$this->getUser()->getEmailConfirmed() && $this->getUser()->getMeta()->getNewWatchActivityEmailNotificationEnabled()) {
			$this->addFlash('error', $translator->trans('default.alert.email_not_confirmed_error'));
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
			'watchContext' => $watchableUtils->getWatchContext($entity, $this->getUser()),
		);
	}

	#[Route(path: '/{id}/delete', requirements: ['id' => '\d+'], name: 'core_watch_delete')]
	#[Template('@LadbCore/Core/Watch/delete-xhr.html.twig')]
	public function deleteAction(Request $request, $id, EntityManagerInterface $om, TypableUtils $typableUtils, WatchableUtils $watchableUtils) {
		$watchRepository = $om->getRepository(Watch::CLASS_NAME);

		$watch = $watchRepository->findOneById($id);
		if (is_null($watch)) {
			throw $this->createNotFoundException('Unable to find Watch entity (id='.$id.').');
		}
		if ($watch->getUser()->getId() != $this->getUser()->getId()) {
			throw $this->createNotFoundException('Not allowed (core_watch_delete)');
		}

		// Retrieve related entity
		$entity = $this->_retrieveRelatedEntity($watch->getEntityType(), $watch->getEntityId(), $typableUtils);

		// Update related entity

		$entity->incrementWatchCount(-1);

		// Delete watch
		$om->remove($watch);
		$om->flush();

		if (!$request->isXmlHttpRequest()) {

			// Return to (use referer because the user is already logged)
			$returnToUrl = $this->getRefererUrl($request);

			return $this->redirect($returnToUrl);
		}

		return array(
			'watchContext' => $watchableUtils->getWatchContext($entity, $this->getUser()),
		);
	}

	#[Route(path: '/{entityType}/{entityId}', requirements: ['entityType' => '\d+', 'entityId' => '\d+'], name: 'core_watch_list_byentity')]
	#[Route(path: '/{entityType}/{entityId}/{page}', requirements: ['entityType' => '\d+', 'entityId' => '\d+', 'page' => '\d+'], name: 'core_watch_list_byentity_page')]
	#[Template('@LadbCore/Core/Watch/list-byentity.html.twig')]
	public function listByEntityAction(Request $request, $entityType, $entityId, EntityManagerInterface $om, PaginatorUtils $paginatorUtils, TypableUtils $typableUtils, $page = 0) {
		$watchRepository = $om->getRepository(Watch::CLASS_NAME);

		// Retrieve related entity

		$entity = $this->_retrieveRelatedEntity($entityType, $entityId, $typableUtils);

		// Retrive watchs

		$offset = $paginatorUtils->computePaginatorOffset($page);
		$limit = $paginatorUtils->computePaginatorLimit($page);
		$paginator = $watchRepository->findPaginedByEntityTypeAndEntityIdJoinedOnUser($entityType, $entityId, $offset, $limit);
		$pageUrls = $paginatorUtils->generatePrevAndNextPageUrl('core_watch_list_byentity_page', array( 'entityType' => $entityType, 'entityId' => $entityId ), $page, $paginator->count());

		$parameters = array(
			'prevPageUrl'  => $pageUrls->prev,
			'nextPageUrl'  => $pageUrls->next,
			'entity'       => $entity,
			'watchs'       => $paginator,
		);

		if ($request->isXmlHttpRequest()) {
			return $this->render('@LadbCore/Core/Watch/list-byentity-xhr.html.twig', $parameters);
		}
		return $parameters;
	}

}